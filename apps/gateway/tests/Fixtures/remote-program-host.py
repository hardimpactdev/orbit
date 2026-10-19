"""Share read-only imports and compilation; run every remote program in a fresh OS child."""
import ast
import atexit
import base64
import builtins
import importlib
import io
import json
import os
import select
import signal
import sys
import tempfile
import traceback

compiled = {}


def prepare(source, filename):
    key = (source, filename)
    if key not in compiled:
        tree = ast.parse(source, filename)
        for node in ast.walk(tree):
            if isinstance(node, ast.Import):
                for alias in node.names:
                    importlib.import_module(alias.name)
            elif isinstance(node, ast.ImportFrom) and node.module:
                importlib.import_module(node.module)
        compiled[key] = builtins.compile(tree, filename, 'exec')
    return compiled[key]


for line in sys.stdin:
    request = json.loads(line)
    source, _, _ = base64.b64decode(request['input']).partition(b'\0')
    program = prepare(source, request['name'])
    bootstrap = prepare(request['bootstrap'].encode(), '<bootstrap>')
    with tempfile.TemporaryFile() as stdin, tempfile.TemporaryFile() as stdout, tempfile.TemporaryFile() as stderr:
        stdin.write(base64.b64decode(request['input']))
        stdin.seek(0)
        pid = os.fork()
        if pid == 0:
            code = 0
            try:
                os.setsid()
                os.chdir(request['cwd'])
                os.environ.update(request['environment'])
                for number, stream in enumerate((stdin, stdout, stderr)):
                    os.dup2(stream.fileno(), number)
                sys.stdin = io.TextIOWrapper(os.fdopen(os.dup(0), 'rb'), encoding='utf-8')
                sys.stdout = io.TextIOWrapper(os.fdopen(os.dup(1), 'wb'), encoding='utf-8')
                sys.stderr = io.TextIOWrapper(os.fdopen(os.dup(2), 'wb'), encoding='utf-8')
                sys.argv = ['-c', request['name']]
                def compile_program(value, filename, mode):
                    assert value == source and filename == request['name'] and mode == 'exec'
                    return program
                exec(bootstrap, {'__name__': '__main__', 'compile': compile_program})
            except SystemExit as error:
                code = error.code if isinstance(error.code, int) else (0 if error.code is None else 1)
                if isinstance(error.code, str):
                    print(error.code, file=sys.stderr)
            except BaseException:
                traceback.print_exc()
                code = 1
            finally:
                atexit._run_exitfuncs()
                sys.stdout.flush()
                sys.stderr.flush()
            os._exit(code)
        descriptor = os.pidfd_open(pid)
        try:
            timed_out = not select.select([descriptor], [], [], request['timeout'])[0]
            if timed_out:
                os.killpg(pid, signal.SIGKILL)
            _, status = os.waitpid(pid, 0)
        finally:
            os.close(descriptor)
        if timed_out:
            stderr.seek(0, os.SEEK_END)
            stderr.write(b'Python test program timed out.\n')
        stdout.seek(0)
        stderr.seek(0)
        print(json.dumps({'exit': 124 if timed_out else os.waitstatus_to_exitcode(status), 'timed_out': timed_out,
                          'stdout': base64.b64encode(stdout.read()).decode(),
                          'stderr': base64.b64encode(stderr.read()).decode()}), flush=True)
