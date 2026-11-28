"""Offline validation and immutable package publication for completed monthly jobs."""
import fcntl
import json
import logging
import os
import re
import stat
import tempfile
from contextlib import ExitStack, contextmanager
from datetime import datetime
from hashlib import sha256
from pathlib import Path

from . import parse, pdf, snapshot, store, xlsx

MIMES = {"pdf": "application/pdf",
         "xlsx": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"}
DETAILS = {"pdf": ("page_count",), "xlsx": ("worksheet_count", "worksheet_names")}
MAX_ARTIFACT_BYTES = 10 * 1024 * 1024  # Laravel IntelligenceArtifactContract::MAX_BYTES
MAX_MANIFEST_BYTES = 8192
TRANSPORT = ("kind", "mime", "size_bytes", "sha256")
SOURCE = ("job.json", "evidence.json", "report.json", snapshot.SNAPSHOT_FILE, snapshot.MANIFEST_FILE)
IDENTITY = ("event_id", "evaluation_id", "umkm_id", "report_reference", "period_month",
            "source_cutoff_at", "source_report_sha256", "snapshot_sha256")
LOG = logging.getLogger(__name__)


class PackageError(Exception):
    pass


def _encoded(value):
    return json.dumps(value, ensure_ascii=True, separators=(",", ":"), allow_nan=False).encode("ascii")


def _projection(entries):
    return [{key: entry[key] for key in TRANSPORT} for entry in sorted(entries, key=lambda e: e["kind"])]


def artifact_set_hash(event_id, source_report_sha256, artifacts):
    return sha256(_encoded({"event_id": event_id, "source_report_sha256": source_report_sha256,
                            "artifacts": _projection(artifacts)})).hexdigest()


def _sha(value):
    return isinstance(value, str) and re.fullmatch(r"[a-f0-9]{64}", value) is not None


def _utc(value):
    if not isinstance(value, str) or not re.fullmatch(
            r"[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?Z", value):
        raise ValueError
    return datetime.fromisoformat(value[:-1] + "+00:00")


def validate_manifest(doc, eid):
    """Validate v1 shape and both hashes, independently of filesystem data."""
    try:
        if (type(doc) is not dict or doc.keys() != set(IDENTITY + (
                "schema_version", "package_status", "created_at_utc", "artifacts",
                "artifact_set_hash", "transport_artifact_set_hash"))
                or type(doc["schema_version"]) is not int or doc["schema_version"] != 1
                or doc["package_status"] != "ready" or store.check_id(doc["event_id"]) != eid
                or doc["report_reference"] != "JMI-" + eid.upper()
                or any(type(doc[k]) is not int or doc[k] < 1 for k in ("evaluation_id", "umkm_id"))
                or not _sha(doc["snapshot_sha256"]) or not _sha(doc["source_report_sha256"])
                or doc["period_month"] != snapshot.wib_period(_utc(doc["source_cutoff_at"]))):
            raise ValueError
        _utc(doc["created_at_utc"])
        entries = doc["artifacts"]
        if type(entries) is not list or len(entries) != 2:
            raise ValueError
        for (kind, mime), entry in zip(MIMES.items(), entries, strict=True):
            if (type(entry) is not dict or entry.keys() != set(TRANSPORT + DETAILS[kind] + (
                    "filename", "template_version", "source_report_sha256", "generated_at_utc"))
                    or entry["kind"] != kind or entry["mime"] != mime or entry["filename"] != f"report.{kind}"
                    or type(entry["size_bytes"]) is not int or not 1 <= entry["size_bytes"] <= MAX_ARTIFACT_BYTES
                    or not _sha(entry["sha256"]) or entry["source_report_sha256"] != doc["source_report_sha256"]
                    or not isinstance(entry["template_version"], str) or not re.fullmatch(
                        rf"joyumkm-{'formal' if kind == 'pdf' else 'workbook'}-[1-9][0-9]*", entry["template_version"])):
                raise ValueError
            _utc(entry["generated_at_utc"])
            if kind == "pdf" and (type(entry["page_count"]) is not int or entry["page_count"] < 1):
                raise ValueError
            if kind == "xlsx" and (type(entry["worksheet_count"]) is not int or entry["worksheet_count"] != len(xlsx.SHEETS)
                                   or entry["worksheet_names"] != list(xlsx.SHEETS)):
                raise ValueError
        if (doc["artifact_set_hash"] != artifact_set_hash(eid, doc["source_report_sha256"], entries)
                or doc["transport_artifact_set_hash"] != sha256(_encoded(_projection(entries))).hexdigest()):
            raise ValueError
    except (ValueError, KeyError, TypeError, OverflowError, parse.PayloadError, snapshot.SnapshotError):
        raise PackageError("MANIFEST_INVALID") from None


def _private(info, *, directory=False):
    valid = stat.S_ISDIR(info.st_mode) if directory else stat.S_ISREG(info.st_mode) and info.st_nlink == 1
    if (not valid or info.st_uid != os.geteuid()
            or stat.S_IMODE(info.st_mode) != (0o700 if directory else 0o600)):
        raise PackageError("PACKAGE_UNSAFE_PATH")
    return info


def _stamp(path):
    info = _private(path.lstat())
    return info.st_dev, info.st_ino, info.st_size, info.st_mtime_ns, info.st_ctime_ns


def _read(path, limit, invalid):
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(fd, "rb") as stream:
        _private(os.fstat(fd))
        data = stream.read(limit + 1)
    if len(data) > limit:
        raise PackageError(invalid)
    return data


@contextmanager
def _lock(path, *, directory=False, create=False, shared=False, nonblocking=False):
    flags = os.O_CREAT | os.O_RDWR if create else os.O_RDONLY
    flags |= os.O_DIRECTORY if directory else os.O_NONBLOCK
    if os.path.lexists(path):
        _private(path.lstat(), directory=directory)
    fd = os.open(path, flags | os.O_NOFOLLOW, 0o600)
    try:
        _private(os.fstat(fd), directory=directory)
        try:
            mode = fcntl.LOCK_SH if shared else fcntl.LOCK_EX
            fcntl.flock(fd, mode | (fcntl.LOCK_NB if nonblocking else 0))
        except BlockingIOError:
            raise PackageError("PACKAGE_LOCK_BUSY") from None
        if not os.path.samestat(_private(os.fstat(fd), directory=directory),
                                _private(path.lstat(), directory=directory)):
            raise PackageError("PACKAGE_UNSAFE_PATH")
        yield
    finally:
        os.close(fd)


def _source(directory, eid, prior):
    def read(name):
        return _read(directory / name, parse.MAX_RAW_BYTES, "PACKAGE_SOURCE_INVALID")

    try:
        raw = read("report.json")
        digest = sha256(raw).hexdigest()
        if prior and digest != prior["source_report_sha256"]:
            raise PackageError("SOURCE_CONFLICT")
        job, evidence, committed = (parse.strict_loads(read(name)) for name in (
            "job.json", "evidence.json", snapshot.MANIFEST_FILE))
        if job.get("integrity_conflicts"):
            raise PackageError("SOURCE_CONFLICT")
        doc, _ = pdf.read_source(directory, eid, expected_hash=digest)
        data = read(snapshot.SNAPSHOT_FILE)
        source = parse.strict_loads(data)
        period = doc["monthly_extension"]["monthly_recap"]["assessment_period"]
        if (any(type(doc[k]) is not int or type(job[k]) is not int or doc[k] != job[k]
                for k in ("evaluation_id", "umkm_id"))
                or type(job["monthly_pipeline"]) is not int or type(job["source_event_version"]) is not int
                or job["source_event_version"] != 2
                or parse.canonical_hash(committed) != parse.canonical_hash(
                    snapshot._manifest_for_completed(source, data, job["payload_hash"]))
                or type(source["schema_version"]) is not int or source["schema_version"] != snapshot.SCHEMA_VERSION
                or type(source["event"]["event_version"]) is not int
                or source["event"]["event_version"] != job["source_event_version"]
                or period["cutoff"]["evaluation_id"] != doc["evaluation_id"]
                or source["event"]["occurred_at"] != evidence["occurred_at"]
                or _utc(period["cutoff"]["at"]) != snapshot._event_instant(evidence)):
            raise ValueError
        return dict(event_id=eid, evaluation_id=doc["evaluation_id"], umkm_id=doc["umkm_id"],
                    report_reference="JMI-" + eid.upper(), period_month=period["current"],
                    source_cutoff_at=period["cutoff"]["at"], source_report_sha256=digest,
                    snapshot_sha256=committed["sha256"])
    except pdf.PdfError as error:
        raise PackageError("SOURCE_CONFLICT" if str(error) == "PDF_SOURCE_CONFLICT"
                           else "PACKAGE_SOURCE_INVALID") from None
    except (ValueError, TypeError, KeyError, AttributeError, parse.PayloadError, snapshot.SnapshotError):
        raise PackageError("PACKAGE_SOURCE_INVALID") from None


def _artifact(directory, eid, source_hash, kind, validator, size):
    prefix = kind.upper()
    filename = f"report.{kind}"
    if not 1 <= size <= MAX_ARTIFACT_BYTES:
        raise PackageError(f"{prefix}_SIZE_INVALID")
    invalid = f"{prefix}_METADATA_INVALID"
    try:
        metadata = parse.strict_loads(_read(directory / f"{filename}.json", MAX_MANIFEST_BYTES, invalid))
        _utc(metadata["generated_at_utc"])
        if (metadata.keys() != set(DETAILS[kind] + ("event_id", "source_report_sha256", "template_version",
                                                   f"{kind}_sha256", "size_bytes", "generated_at_utc"))
                or metadata["event_id"] != eid or not _sha(metadata[f"{kind}_sha256"])
                or not _sha(metadata["source_report_sha256"])):
            raise ValueError
    except (ValueError, KeyError, TypeError, AttributeError, parse.PayloadError, snapshot.SnapshotError):
        raise PackageError(invalid) from None
    if metadata["source_report_sha256"] != source_hash:
        raise PackageError(f"{prefix}_SOURCE_CONFLICT")
    if metadata["template_version"] != validator.TEMPLATE_VERSION:
        raise PackageError(f"{prefix}_TEMPLATE_CONFLICT")
    fd = os.open(directory / filename, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(fd, "rb") as stream:
        _private(os.fstat(fd))
        signature = b"%PDF-" if kind == "pdf" else b"PK\x03\x04"
        if stream.read(len(signature)) != signature:
            raise PackageError(f"{prefix}_MIME_INVALID")
    # operate() would reacquire the renderer lock. Use its verification path under our locks.
    try:
        verified = validator.publish(directory, eid, verify=True)
    except (pdf.PdfError, xlsx.XlsxError) as error:
        raise PackageError(str(error)) from None
    if verified != {"result": "valid", **metadata} or size != metadata["size_bytes"]:
        raise PackageError("PACKAGE_CHANGED")
    return dict(kind=kind, filename=filename, mime=MIMES[kind], size_bytes=size, sha256=verified[f"{kind}_sha256"],
                template_version=verified["template_version"], generated_at_utc=verified["generated_at_utc"],
                source_report_sha256=source_hash, **{key: verified[key] for key in DETAILS[kind]})


def _unchanged(stamps):
    try:
        if all(_stamp(path) == stamp for path, stamp in stamps.items()):
            return
    except (OSError, PackageError):
        pass
    raise PackageError("PACKAGE_CHANGED")


def _publish(path, doc, stamps):
    data = _encoded(doc) + b"\n"
    if len(data) > MAX_MANIFEST_BYTES:
        raise PackageError("MANIFEST_INVALID")
    fd, temporary = tempfile.mkstemp(prefix=".manifest.", suffix=".tmp", dir=path.parent)
    try:
        with os.fdopen(fd, "wb") as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        _unchanged(stamps)
        if os.path.lexists(path):
            raise PackageError("MANIFEST_CONFLICT")
        os.replace(temporary, path)
        try:
            store.fsync_dir(path.parent)
            _unchanged(stamps)
        except PackageError:
            path.unlink()
            store.fsync_dir(path.parent)
            raise
    finally:
        Path(temporary).unlink(missing_ok=True)


def _operate(eid, *, build, nonblocking, blobs=None):
    root = Path(os.environ.get("JMI_HOME", store.DEFAULT_HOME)).absolute()
    directory = root / "jobs" / eid
    if directory.resolve() != directory:
        raise PackageError("PACKAGE_UNSAFE_PATH")
    for path in (root, directory.parent, directory, root / "locks", root / "rejects"):
        _private(path.lstat(), directory=True)
    with _lock(directory, directory=True, shared=not build, nonblocking=nonblocking), ExitStack() as locks:
        path = directory / "manifest.json"
        stamps = {}
        prior = None
        if os.path.lexists(path):
            stamps[path] = _stamp(path)
            try:
                prior = parse.strict_loads(_read(path, MAX_MANIFEST_BYTES, "MANIFEST_INVALID"))
            except parse.PayloadError:
                raise PackageError("MANIFEST_INVALID") from None
            validate_manifest(prior, eid)
        elif not build:
            raise PackageError("MANIFEST_MISSING")
        # Worker takes .proc before .snap or .lock. Snapshot takes only .snap.
        for suffix in ("proc", "snap", "lock"):
            lock = root / "locks" / f"{eid}.{suffix}"
            try:
                locks.enter_context(_lock(lock, create=build and prior is None, shared=True, nonblocking=nonblocking))
            except FileNotFoundError:
                raise PackageError("PACKAGE_SOURCE_LOCK_MISSING") from None
        for name in SOURCE:
            stamps[directory / name] = _stamp(directory / name)
        identity = _source(directory, eid, prior)
        for kind in MIMES:
            if not os.path.lexists(directory / f"report.{kind}"):
                raise PackageError(f"{kind.upper()}_MISSING")
            try:
                locks.enter_context(_lock(directory / f".report.{kind}.lock", create=build and prior is None, shared=not build, nonblocking=nonblocking))
            except FileNotFoundError:
                raise PackageError(f"{kind.upper()}_LOCK_MISSING") from None
        entries = []
        for kind, validator in zip(MIMES, (pdf, xlsx), strict=True):
            for suffix in ("", ".json"):
                item = directory / f"report.{kind}{suffix}"
                try:
                    stamps[item] = _stamp(item)
                except FileNotFoundError:
                    error = "SIDECAR_MISSING" if suffix else "MISSING"
                    raise PackageError(f"{kind.upper()}_{error}") from None
            entries.append(_artifact(directory, eid, identity["source_report_sha256"], kind, validator,
                                     stamps[directory / f"report.{kind}"][2]))
        doc = dict(**identity, schema_version=1, package_status="ready", artifacts=entries,
                   artifact_set_hash=artifact_set_hash(eid, identity["source_report_sha256"], entries),
                   transport_artifact_set_hash=sha256(_encoded(_projection(entries))).hexdigest(),
                   created_at_utc=prior["created_at_utc"] if prior else store.now())
        validate_manifest(doc, eid)
        _unchanged(stamps)
        if blobs is not None and prior is not None:
            # P4-04: read the exact bytes while the shared locks are held and bind them to the verified checksums.
            for entry in prior["artifacts"]:
                data = _read(directory / entry["filename"], MAX_ARTIFACT_BYTES, f"{entry['kind'].upper()}_SIZE_INVALID")
                if len(data) != entry["size_bytes"] or sha256(data).hexdigest() != entry["sha256"]:
                    raise PackageError("PACKAGE_CHANGED")
                blobs[entry["kind"]] = data
            blobs["report"] = _read(directory / "report.json", parse.MAX_RAW_BYTES, "PACKAGE_SOURCE_INVALID")
            if sha256(blobs["report"]).hexdigest() != prior["source_report_sha256"]:
                raise PackageError("SOURCE_CONFLICT")
            _unchanged(stamps)
        if prior:
            if prior["artifact_set_hash"] != doc["artifact_set_hash"]:
                raise PackageError("ARTIFACT_SET_CONFLICT")
            for previous, current in zip(prior["artifacts"], entries, strict=True):
                # Recovering identical artifact bytes can legitimately update a sidecar timestamp.
                current["generated_at_utc"] = previous["generated_at_utc"]
            if prior != doc:
                raise PackageError("MANIFEST_CONFLICT")
            if build:
                store.fsync_file(path)
                store.fsync_dir(directory)
                _unchanged(stamps)
            return ("reused" if build else "valid"), prior
        _publish(path, doc, stamps)
        return "created", doc


def _result(eid, *, build, nonblocking):
    try:
        store.check_id(eid)
    except parse.PayloadError as error:
        return dict(result="failed", package_status="invalid", error_code=error.code)
    try:
        result, manifest = _operate(eid, build=build, nonblocking=nonblocking)
        return dict(result=result, event_id=eid, package_status="ready", manifest=manifest)
    except (OSError, PackageError) as error:
        if isinstance(error, PackageError):
            code = str(error)
        else:
            code = "PACKAGE_SOURCE_MISSING" if isinstance(error, FileNotFoundError) else "PACKAGE_IO_FAILED"
        status = "conflict" if code.endswith("_CONFLICT") else "not_ready" if code.endswith("_MISSING") else "invalid"
        if status == "conflict":
            LOG.warning("%s event_id=%s", code, eid)
        return dict(result="failed", event_id=eid, package_status=status, error_code=code)


def build_manifest(event_id, *, nonblocking=False):
    return _result(event_id, build=True, nonblocking=nonblocking)


def verify_manifest(event_id, *, nonblocking=False):
    return _result(event_id, build=False, nonblocking=nonblocking)


def get_transport_manifest(event_id, *, nonblocking=False):
    """Return only P2 callback fields after verifying the committed package."""
    result = verify_manifest(event_id, nonblocking=nonblocking)
    if result["package_status"] != "ready":
        raise PackageError(result["error_code"])
    return {"artifacts": _projection(result["manifest"]["artifacts"])}


def read_package(event_id, *, nonblocking=True):
    """P4-04: verify the committed package and return ``(manifest, {"pdf", "xlsx", "report"} -> exact bytes)``.

    Bytes are read under the same shared locks as the verification and bound to the manifest checksums, so what the
    caller later transports is what was hashed. All locks are released before returning. Raises PackageError."""
    store.check_id(event_id)
    blobs = {}
    try:
        _, manifest = _operate(event_id, build=False, nonblocking=nonblocking, blobs=blobs)
    except FileNotFoundError:
        raise PackageError("PACKAGE_SOURCE_MISSING") from None
    except OSError:
        raise PackageError("PACKAGE_IO_FAILED") from None
    return manifest, blobs


def command(event_id, *, verify):
    result = verify_manifest(event_id) if verify else build_manifest(event_id)
    code = result.get("error_code")
    return result, 2 if code == "INVALID_EVENT_ID" else 3 if code else 0

