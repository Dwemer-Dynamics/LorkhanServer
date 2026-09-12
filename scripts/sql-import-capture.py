#!/usr/bin/env python3
"""Capture untrusted sandbox output with parent-owned limits; successful output still needs schema validation."""
import os
from pathlib import Path
import selectors
import signal
import subprocess
import sys
import tempfile
import time


def capture(command, destination, max_bytes=1073741824, timeout=610, max_stderr=65536):
    """Publish one private, complete capture without overwriting files or retaining partial failures."""
    destination = Path(destination)
    parent = destination.parent.resolve(strict=True)
    destination = parent / destination.name
    owner = parent.stat()
    if owner.st_uid != os.geteuid() or owner.st_mode & 0o022:
        raise RuntimeError("import_capture_directory_unsafe")
    if os.path.lexists(destination):
        raise FileExistsError("import_capture_exists")
    process = None
    partial = None
    written = 0
    diagnostics = 0
    deadline = time.monotonic() + timeout
    try:
        with tempfile.NamedTemporaryFile(prefix=".sql-import-", suffix=".partial", dir=parent, delete=False) as output:
            partial = Path(output.name)
            process = subprocess.Popen(command, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                                       stderr=subprocess.PIPE, start_new_session=True, close_fds=True)
            with selectors.DefaultSelector() as selector:
                for stream in (process.stdout, process.stderr):
                    os.set_blocking(stream.fileno(), False)
                    selector.register(stream, selectors.EVENT_READ)
                while selector.get_map() or process.poll() is None:
                    remaining = deadline - time.monotonic()
                    if remaining <= 0:
                        raise RuntimeError("import_capture_timeout")
                    for key, _ in selector.select(min(remaining, 0.2)):
                        chunk = os.read(key.fd, 65536)
                        if not chunk:
                            selector.unregister(key.fileobj)
                            continue
                        if key.fileobj is process.stdout:
                            written += len(chunk)
                            if written > max_bytes:
                                raise RuntimeError("import_capture_output_limit")
                            output.write(chunk)
                        else:
                            diagnostics += len(chunk)
                            if diagnostics > max_stderr:
                                raise RuntimeError("import_capture_diagnostic_limit")
                    if not selector.get_map() and process.poll() is None:
                        time.sleep(min(0.02, max(0, deadline - time.monotonic())))
            if process.wait() != 0:
                raise RuntimeError("import_capture_failed")
            if written == 0:
                raise RuntimeError("import_capture_empty")
            output.flush()
            os.fsync(output.fileno())
            os.fchmod(output.fileno(), 0o400)
        # Hard-link publication is atomic and refuses a destination created during capture.
        os.link(partial, destination)
        return written
    finally:
        if process is not None:
            # Kill the owned process group even if its leader exited with inherited pipes open.
            try:
                os.killpg(process.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
            process.wait()
            process.stdout.close()
            process.stderr.close()
        if partial is not None:
            partial.unlink(missing_ok=True)


if __name__ == "__main__":
    # A worker cancellation must unwind capture and terminate the owned sandbox group.
    def cancel(signum, frame):
        raise RuntimeError("import_capture_cancelled")

    for signum in (signal.SIGTERM, signal.SIGINT, signal.SIGHUP):
        signal.signal(signum, cancel)
    try:
        if os.geteuid() == 0 or len(sys.argv) != 3:
            raise RuntimeError("import_capture_arguments")
        sandbox = Path(__file__).resolve().with_name("import-sql-sandbox.sh")
        capture(["/bin/bash", str(sandbox), str(Path(sys.argv[1]).absolute())], sys.argv[2])
    except Exception:
        # Never return raw sandbox diagnostics, input paths or SQL to a caller.
        sys.stderr.write("isolated_sql_capture_failed\n")
        sys.exit(1)
