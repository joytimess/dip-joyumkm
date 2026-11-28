"""Offline, per-event PDF publication for completed P3-02 jobs."""
import fcntl
import io
import json
import logging
import re
import os
import stat
from hashlib import sha256
from pathlib import Path

from pypdf import PdfReader

from . import engine, parse, snapshot, store

TEMPLATE_VERSION = "joyumkm-formal-1"
LOG = logging.getLogger(__name__)


class PdfError(Exception):
    pass


def digest(data):
    return sha256(data).hexdigest()


def inspect_pdf(data):
    """Check the generated document and reject interactive content."""
    try:
        reader = PdfReader(io.BytesIO(data), strict=True)
        if (reader.is_encrypted or not reader.pages
                or any(key in reader.root_object for key in ("/OpenAction", "/AA", "/Names", "/AcroForm"))):
            raise ValueError
        for page in reader.pages:
            if (abs(float(page.mediabox.width) - 595.28) > 0.02
                    or abs(float(page.mediabox.height) - 841.89) > 0.02
                    or "/AA" in page or "/Annots" in page or not page.extract_text().strip()):
                raise ValueError
        return len(reader.pages)
    except Exception:
        raise PdfError("PDF_INVALID") from None


def checked_file(path, *, private=False):
    try:
        info = path.lstat()
    except FileNotFoundError:
        return False
    if (not stat.S_ISREG(info.st_mode) or info.st_nlink != 1
            or (private and (info.st_uid != os.geteuid() or info.st_mode & 0o077))):
        raise PdfError("PDF_UNSAFE_PATH")
    return True


def read_source(directory, eid, *, expected_hash=None):
    paths = [directory / name for name in
             ("job.json", "report.json", "evidence.json", snapshot.SNAPSHOT_FILE, snapshot.MANIFEST_FILE)]
    if not all(checked_file(path) for path in paths):
        raise PdfError("PDF_SOURCE_MISSING")
    job, raw, evidence = (path.read_bytes() for path in paths[:3])
    source_hash = digest(raw)
    if expected_hash is not None and expected_hash != source_hash:
        raise PdfError("PDF_SOURCE_CONFLICT")
    try:
        job = parse.strict_loads(job)
        doc = parse.strict_loads(raw)
        if (job["status"] != "completed" or job["event_id"] != eid or job["monthly_pipeline"] != 1
                or doc["event_id"] != eid or doc["status"] != "completed"
                or doc["source_event_version"] != 2):
            raise ValueError
        if job.get("report_hash", source_hash) != source_hash:
            raise ValueError
        engine._validate_document(doc, parse.strict_loads(evidence), engine.load_knowledge(), job)
    except Exception:
        raise PdfError("PDF_SOURCE_INVALID") from None
    return doc, source_hash


def operate(eid, *, verify=False, nonblocking=False):
    eid = store.check_id(eid)
    root = Path(os.environ.get("JMI_HOME", store.DEFAULT_HOME)).absolute()
    directory = root / "jobs" / eid
    if directory.resolve() != directory:
        raise PdfError("PDF_UNSAFE_PATH")
    for parent in (root, directory.parent, directory):
        try:
            info = parent.lstat()
        except FileNotFoundError:
            raise PdfError("PDF_SOURCE_MISSING") from None
        if (not stat.S_ISDIR(info.st_mode) or info.st_uid != os.geteuid() or info.st_mode & 0o077):
            raise PdfError("PDF_UNSAFE_PATH")
    lock = directory / ".report.pdf.lock"
    checked_file(lock)
    fd = os.open(lock, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW | os.O_NONBLOCK, 0o600)
    try:
        fcntl.flock(fd, fcntl.LOCK_EX | (fcntl.LOCK_NB if nonblocking else 0))
        lock_info = os.fstat(fd)
        if not stat.S_ISREG(lock_info.st_mode) or lock_info.st_nlink != 1 or lock_info.st_mode & 0o077:
            raise PdfError("PDF_UNSAFE_PATH")
        return publish(directory, eid, verify)
    finally:
        os.close(fd)


def publish(directory, eid, verify):
    pdf = directory / "report.pdf"
    sidecar = directory / "report.pdf.json"
    has_pdf = checked_file(pdf, private=True)
    metadata = None
    prior_hash = None
    if checked_file(sidecar, private=True):
        try:
            metadata = parse.strict_loads(sidecar.read_bytes())
            if (metadata["event_id"] != eid or not metadata["generated_at_utc"]
                    or not metadata["template_version"] or len(metadata["pdf_sha256"]) != 64
                    or type(metadata["size_bytes"]) is not int or type(metadata["page_count"]) is not int):
                raise ValueError
            prior_hash = metadata["source_report_sha256"]
            if not re.fullmatch(r"[a-f0-9]{64}", prior_hash):
                raise ValueError
        except (ValueError, KeyError, TypeError, parse.PayloadError):
            raise PdfError("PDF_METADATA_INVALID") from None
    elif has_pdf:
        raise PdfError("PDF_METADATA_MISSING")

    doc, source_hash = read_source(directory, eid, expected_hash=prior_hash)

    if metadata and metadata["template_version"] != TEMPLATE_VERSION:
        raise PdfError("PDF_TEMPLATE_CONFLICT")

    if has_pdf:
        data = pdf.read_bytes()
        try:
            valid = (digest(data) == metadata["pdf_sha256"] and len(data) == metadata["size_bytes"]
                     and inspect_pdf(data) == metadata["page_count"])
        except PdfError:
            valid = False
        if valid:
            store.fsync_file(pdf)
            store.fsync_file(sidecar)
            store.fsync_dir(directory)
            return {"result": "valid" if verify else "reused", **metadata}

    if verify:
        raise PdfError("PDF_CORRUPT" if has_pdf else "PDF_NOT_READY")

    if os.path.lexists(directory / "manifest.json"):
        raise PdfError("ARTIFACT_SET_CONFLICT")
    for candidate in directory.glob(".report.pdf.*.tmp"):
        if re.fullmatch(r"\.report\.pdf\.(?:json\.)?\d+\.[a-f0-9]{8}\.tmp", candidate.name):
            candidate.unlink()

    try:
        from .pdf_template import render

        data = render(doc)
    except Exception:
        raise PdfError("PDF_RENDER_FAILED") from None
    metadata = {
        "event_id": eid, "source_report_sha256": source_hash, "template_version": TEMPLATE_VERSION,
        "pdf_sha256": digest(data), "size_bytes": len(data), "page_count": inspect_pdf(data),
        "generated_at_utc": store.now(),
    }
    read_source(directory, eid, expected_hash=source_hash)
    # Bind the source before PDF publication so an interrupted write can safely resume.
    store.atomic_write(sidecar, metadata)
    store.atomic_write(pdf, data)
    return {"result": "recovered" if has_pdf else "created", **metadata}


def command(eid, *, verify=False):
    try:
        return operate(eid, verify=verify), 0
    except (PdfError, OSError) as error:
        code = str(error) if isinstance(error, PdfError) else "PDF_IO_FAILED"
        if code.endswith("_CONFLICT"):
            LOG.warning("%s event_id=%s", code, eid)
        return {"result": "failed", "error_code": code}, 3

