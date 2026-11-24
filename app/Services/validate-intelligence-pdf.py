"""Bounded QPDF validation for private intelligence uploads."""
import json
import os
import subprocess
import sys
import threading

MEMORY = 384 * 1024 * 1024
OUTPUT = 8 * 1024 * 1024
FORBIDDEN = set("""
/A /AA /OpenAction /JS /JavaScript /Launch /EF /AF /EmbeddedFile
/EmbeddedFiles /FileAttachment /Filespec /RichMedia /SubmitForm /ImportData
/XFA /AcroForm /GoToR /GoToE /URI /Rendition /Movie /Sound /3D /Screen
/SetOCGState /Hide /ResetForm /Named /Thread /GoTo3DView
""".split())


def limit_processes():
    if os.name != "nt":
        import resource

        resource.setrlimit(resource.RLIMIT_AS, (MEMORY, MEMORY))
        resource.setrlimit(resource.RLIMIT_CPU, (6, 6))
        return

    import ctypes as c
    from ctypes import wintypes as w

    class BasicLimits(c.Structure):
        _fields_ = [
            ("process_time", c.c_longlong), ("job_time", c.c_longlong),
            ("flags", w.DWORD), ("min_working_set", c.c_size_t),
            ("max_working_set", c.c_size_t), ("active_processes", w.DWORD),
            ("affinity", c.c_size_t), ("priority", w.DWORD), ("scheduling", w.DWORD),
        ]

    class ExtendedLimits(c.Structure):
        _fields_ = [
            ("basic", BasicLimits), ("io", c.c_ulonglong * 6),
            ("process_memory", c.c_size_t), ("job_memory", c.c_size_t),
            ("peak_process_memory", c.c_size_t), ("peak_job_memory", c.c_size_t),
        ]

    kernel = c.WinDLL("kernel32", use_last_error=True)
    kernel.CreateJobObjectW.argtypes = [c.c_void_p, w.LPCWSTR]
    kernel.CreateJobObjectW.restype = w.HANDLE
    kernel.SetInformationJobObject.argtypes = [w.HANDLE, c.c_int, c.c_void_p, w.DWORD]
    kernel.AssignProcessToJobObject.argtypes = [w.HANDLE, w.HANDLE]
    kernel.GetCurrentProcess.restype = w.HANDLE
    job = kernel.CreateJobObjectW(None, None)
    limits = ExtendedLimits()
    # Kill on close, cap total committed memory, and permit only this process and QPDF.
    limits.basic.flags = 0x2000 | 0x200 | 0x8
    limits.basic.active_processes = 2
    limits.job_memory = MEMORY
    if not job or not kernel.SetInformationJobObject(job, 9, c.byref(limits), c.sizeof(limits)):
        raise c.WinError(c.get_last_error())
    if not kernel.AssignProcessToJobObject(job, kernel.GetCurrentProcess()):
        raise c.WinError(c.get_last_error())


def qpdf(executable, path, *options):
    command = [
        executable, "--suppress-recovery", *options, path,
    ]
    # Pass only OS essentials and executable lookup paths to the parser.
    environment = {key: os.environ[key] for key in ("PATH", "SystemRoot", "TEMP", "TMP")
                   if key in os.environ}
    with subprocess.Popen(command, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                          stderr=subprocess.STDOUT, env=environment) as process:
        timer = threading.Timer(3, process.kill)
        timer.start()
        try:
            output = process.stdout.read(OUTPUT + 1)
            if len(output) > OUTPUT:
                process.kill()
                raise ValueError("output limit")
            return process.wait(), output
        finally:
            timer.cancel()


def unsafe(graph):
    pending = [graph]
    while pending:
        value = pending.pop()
        if isinstance(value, dict):
            if FORBIDDEN.intersection(value) or "/F" in value.get("dict", {}):
                return True
            pending.extend(value.values())
        elif isinstance(value, list):
            pending.extend(value)
        elif isinstance(value, str) and value in FORBIDDEN:
            return True
    return False


def validate(executable, path):
    limit_processes()
    status, output = qpdf(executable, path, "--json=2", "--json-key=qpdf",
                          "--json-key=encrypt", "--json-stream-data=none")
    if status != 0:
        return 2
    document = json.loads(output)
    if document["encrypt"]["encrypted"] is not False:
        return 4
    metadata, graph = document["qpdf"]
    if metadata["jsonversion"] != 2 or "/Root" not in graph["trailer"]["value"]:
        return 2
    if unsafe(graph):
        return 3
    status, _ = qpdf(executable, path, "--check")
    return 0 if status == 0 else 2


if __name__ == "__main__":
    try:
        sys.exit(validate(*sys.argv[1:]))
    except (OSError, ValueError, TypeError, KeyError, RecursionError):
        sys.exit(2)
