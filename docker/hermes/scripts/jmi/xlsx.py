"""Offline XLSX publication and verification for completed monthly reports."""
import fcntl
import io
import logging
import os
import posixpath
import re
import stat
from datetime import datetime, timezone
from pathlib import Path
from xml.etree import ElementTree
from zipfile import ZipFile

from openpyxl import load_workbook

from . import parse, pdf, store
from .xlsx_template import SHEETS, render

TEMPLATE_VERSION = "joyumkm-workbook-1"
LOG = logging.getLogger(__name__)
HEX = re.compile(r"[a-f0-9]{64}")
PART = re.compile(
    r"\[Content_Types\]\.xml|_rels/\.rels|docProps/(?:app|core)\.xml|"
    r"xl/(?:workbook|styles|sharedStrings|theme/theme1|_rels/workbook|"
    r"worksheets/sheet[1-5]|worksheets/_rels/sheet[1-5]|tables/table\d+|"
    r"drawings/drawing\d+|drawings/_rels/drawing\d+|charts/chart\d+)\.xml(?:\.rels)?"
)
FORMULA = re.compile(
    r'IF\(COUNT\(([A-Z]+\d+):([A-Z]+\d+)\)=2,ROUND\(ABS\(\2-\1\),10\),""\)|'
    r'IF\(COUNT\(([A-Z]+\d+):([A-Z]+\d+)\)=6,SUM\(\3:\4\),""\)'
)
CHART_REF = re.compile(r"'?Histori'?!\$[A-L]\$\d+(?::\$[A-L]\$\d+)?")


class XlsxError(Exception):
    pass


def inspect_xlsx(data):
    try:
        with ZipFile(io.BytesIO(data)) as package:
            entries = package.infolist()
            names = {entry.filename for entry in entries}
            if (len(names) != len(entries) or len(entries) > 100 or
                    sum(entry.file_size for entry in entries) > 16_000_000):
                raise ValueError
            for entry in entries:
                if not PART.fullmatch(entry.filename) or stat.S_ISLNK(entry.external_attr >> 16):
                    raise ValueError
                raw = package.read(entry)
                if re.search(br"<!DOCTYPE|<!ENTITY", raw, re.I):
                    raise ValueError
                tree = ElementTree.fromstring(raw)
                for element in tree.iter():
                    tag = element.tag.rsplit("}", 1)[-1]
                    if tag in ("hyperlink", "oleObject", "externalReference", "externalLink", "connection"):
                        raise ValueError
                    if tag == "Relationship":
                        target = element.get("Target", "")
                        base = entry.filename.split("_rels/", 1)[0]
                        resolved = posixpath.normpath(posixpath.join(base, target))
                        if (element.get("TargetMode", "").lower() == "external"
                                or not re.fullmatch(r"(?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]+", target)
                                or resolved not in names or resolved.endswith(".rels")):
                            raise ValueError
                    if tag == "f":
                        rule = CHART_REF if entry.filename.startswith("xl/charts/") else FORMULA
                        if not rule.fullmatch(element.text or "") or element.attrib:
                            raise ValueError
                    if tag == "definedName" and (
                            element.get("name") not in ("_xlnm.Print_Area", "_xlnm.Print_Titles")
                            or not re.fullmatch(r"'?(?:Ringkasan|Enam Faktor|Tiga Dimensi|Histori|Rekomendasi)'?!\$[A-M]?\$\d+:\$[A-M]?\$\d+", element.text or "")):
                        # Print row names use $1:$2, while print areas use $A$1:$L$32.
                        if (element.get("name") != "_xlnm.Print_Titles"
                                or not re.fullmatch(r"'?(?:Ringkasan|Enam Faktor|Tiga Dimensi|Histori|Rekomendasi)'?!\$\d+:\$\d+", element.text or "")):
                            raise ValueError
        workbook = load_workbook(io.BytesIO(data), data_only=False)
        try:
            if tuple(workbook.sheetnames) != SHEETS or any(
                    sheet.sheet_state != "visible" or not sheet.tables or not sheet.freeze_panes
                    or not sheet.print_area for sheet in workbook):
                raise ValueError
        finally:
            workbook.close()
    except Exception:
        raise XlsxError("XLSX_INVALID") from None
    return list(SHEETS)


def checked(path):
    exists = pdf.checked_file(path, private=True)
    if exists and stat.S_IMODE(path.lstat().st_mode) != 0o600:
        raise XlsxError("XLSX_UNSAFE_PATH")
    return exists


def metadata_for(sidecar, eid):
    try:
        metadata = parse.strict_loads(sidecar.read_bytes())
        generated = datetime.fromisoformat(metadata["generated_at_utc"].replace("Z", "+00:00"))
        if (metadata["event_id"] != eid or generated.utcoffset() != timezone.utc.utcoffset(None)
                or not isinstance(metadata["template_version"], str) or not metadata["template_version"]
                or not HEX.fullmatch(metadata["source_report_sha256"])
                or not HEX.fullmatch(metadata["xlsx_sha256"])
                or type(metadata["size_bytes"]) is not int or metadata["size_bytes"] <= 0
                or type(metadata["worksheet_count"]) is not int or metadata["worksheet_count"] != len(SHEETS)
                or metadata["worksheet_names"] != list(SHEETS)):
            raise ValueError
        return metadata
    except (KeyError, ValueError, TypeError, AttributeError, parse.PayloadError):
        raise XlsxError("XLSX_METADATA_INVALID") from None


def read_source(directory, eid, expected_hash=None):
    try:
        return pdf.read_source(directory, eid, expected_hash=expected_hash)
    except pdf.PdfError as error:
        raise XlsxError(str(error).replace("PDF_", "XLSX_", 1)) from None


def publish(directory, eid, verify):
    workbook = directory / "report.xlsx"
    sidecar = directory / "report.xlsx.json"
    present = checked(workbook)
    metadata = metadata_for(sidecar, eid) if checked(sidecar) else None
    if present and not metadata:
        raise XlsxError("XLSX_METADATA_MISSING")
    doc, source_hash = read_source(directory, eid, metadata["source_report_sha256"] if metadata else None)
    if metadata and metadata["template_version"] != TEMPLATE_VERSION:
        raise XlsxError("XLSX_TEMPLATE_CONFLICT")
    if present and workbook.stat().st_size == metadata["size_bytes"]:
        data = workbook.read_bytes()
        if pdf.digest(data) == metadata["xlsx_sha256"]:
            try:
                inspect_xlsx(data)
            except XlsxError:
                pass
            else:
                store.fsync_file(workbook)
                store.fsync_file(sidecar)
                store.fsync_dir(directory)
                return {"result": "valid" if verify else "reused", **metadata}
    if verify:
        raise XlsxError("XLSX_CORRUPT" if present else "XLSX_NOT_READY")
    if os.path.lexists(directory / "manifest.json"):
        raise XlsxError("ARTIFACT_SET_CONFLICT")
    for candidate in directory.glob(".report.xlsx.*.tmp"):
        if re.fullmatch(r"\.report\.xlsx\.(?:json\.)?\d+\.[a-f0-9]{8}\.tmp", candidate.name):
            candidate.unlink()
    try:
        data = render(doc)
    except Exception:
        raise XlsxError("XLSX_RENDER_FAILED") from None
    sheets = inspect_xlsx(data)
    new_metadata = {
        "event_id": eid, "source_report_sha256": source_hash, "template_version": TEMPLATE_VERSION,
        "xlsx_sha256": pdf.digest(data), "size_bytes": len(data), "worksheet_count": len(sheets),
        "worksheet_names": sheets, "generated_at_utc": store.now(),
    }
    read_source(directory, eid, source_hash)
    store.atomic_write(sidecar, new_metadata)
    store.atomic_write(workbook, data)
    return {"result": "recovered" if metadata else "created", **new_metadata}


def operate(eid, *, verify=False, nonblocking=False):
    eid = store.check_id(eid)
    root = Path(os.environ.get("JMI_HOME", store.DEFAULT_HOME)).absolute()
    directory = root / "jobs" / eid
    try:
        if directory.resolve() != directory:
            raise XlsxError("XLSX_UNSAFE_PATH")
        for parent in (root, directory.parent, directory):
            info = parent.lstat()
            if (not stat.S_ISDIR(info.st_mode) or info.st_uid != os.geteuid() or info.st_mode & 0o077):
                raise XlsxError("XLSX_UNSAFE_PATH")
    except FileNotFoundError:
        raise XlsxError("XLSX_SOURCE_MISSING") from None
    lock = directory / ".report.xlsx.lock"
    try:
        checked(lock)
        fd = os.open(lock, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW | os.O_NONBLOCK, 0o600)
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | (fcntl.LOCK_NB if nonblocking else 0))
            info = os.fstat(fd)
            if (not stat.S_ISREG(info.st_mode) or info.st_uid != os.geteuid()
                    or info.st_nlink != 1 or stat.S_IMODE(info.st_mode) != 0o600):
                raise XlsxError("XLSX_UNSAFE_PATH")
            return publish(directory, eid, verify)
        finally:
            os.close(fd)
    except pdf.PdfError as error:
        raise XlsxError(str(error).replace("PDF_", "XLSX_", 1)) from None


def command(eid, *, verify=False):
    try:
        return operate(eid, verify=verify), 0
    except (XlsxError, OSError) as error:
        code = str(error) if isinstance(error, XlsxError) else "XLSX_IO_FAILED"
        if code.endswith("_CONFLICT"):
            LOG.warning("%s event_id=%s", code, eid)
        return {"result": "failed", "error_code": code}, 3

