"""Run a program in a pseudo-terminal and answer its prompts. Standard library only.

A script is a list of `{see, send}` and `{see, choose}` items. `see` waits until the text appears in the output that came after
the previous item. `send` types text and Enter; `^C` sends Ctrl-C and no Enter. `choose` moves a Laravel Prompts select to the
item whose label equals the text and presses Enter.
"""
import fcntl
import os
import pty
import re
import select
import signal
import struct
import termios
import time

ANSI = re.compile(r'\x1b\[[0-9;?]*[ -/]*[@-~]|\x1b[()][A-Z0-9]|\x1b[=>]|\r')
HIGHLIGHT = re.compile(r'›\s*(?:[●○◉◯◼◻]\s*)?([^│\n]*?)\s*[│\n]')
DOWN = b'\x1b[B'
KEYS = {'^C': b'\x03', '^D': b'\x04'}
MAX_OPTIONS = 30


class TerminalError(Exception):
    pass


def plain(text):
    return ANSI.sub('', text)


class Session:
    def __init__(self, argv, env, cwd='/', size=(50, 160)):
        self.text, self.mark, self.rc = '', 0, None
        self.pid, self.fd = pty.fork()
        if self.pid == 0:
            try:
                os.chdir(cwd)
                os.execvpe(argv[0], argv, env)
            finally:
                os._exit(127)
        fcntl.ioctl(self.fd, termios.TIOCSWINSZ, struct.pack('HHHH', size[0], size[1], 0, 0))

    def read(self, seconds):
        """Append what the program wrote within `seconds`. Returns False at the end of the output."""
        ready, _, _ = select.select([self.fd], [], [], seconds)
        if not ready:
            return True
        try:
            data = os.read(self.fd, 65536)
        except OSError:  # Linux reports the end of a pty as EIO
            return False
        if not data:
            return False
        self.text += plain(data.decode('utf-8', 'replace'))
        return True

    def wait_for(self, check, deadline, what):
        """Read until `check(new text)` returns a value, the program ends or the deadline passes."""
        while True:
            found = check(self.text[self.mark:])
            if found is not None:
                return found
            left = deadline - time.time()
            if left <= 0:
                raise TerminalError(f'timeout waiting for {what}; the output ends with {self.text[-200:]!r}')
            if not self.read(min(left, 1)):
                found = check(self.text[self.mark:])
                if found is not None:
                    return found
                raise TerminalError(f'the program ended before {what}; the output ends with {self.text[-200:]!r}')

    def send(self, data):
        os.write(self.fd, data)

    def finish(self, deadline):
        """Read to the end of the output and collect the exit code. A program that does not end is killed."""
        while time.time() < deadline:
            if not self.read(1):
                _, status = os.waitpid(self.pid, 0)
                self.rc = os.waitstatus_to_exitcode(status)
                return
        os.kill(self.pid, signal.SIGKILL)
        os.waitpid(self.pid, 0)
        raise TerminalError('the program did not end in time')


def choose(session, label, deadline):
    """Press Down until the highlighted option is `label`, then Enter. The highlight is the line with `›`."""
    seen = []
    for _ in range(MAX_OPTIONS):
        def highlighted(fresh):
            found = HIGHLIGHT.findall(fresh)
            return found[-1] if found else None

        current = session.wait_for(highlighted, deadline, 'a highlighted option')
        if re.fullmatch(re.escape(label) + r'(?:[ (].*)?', current):
            session.send(b'\r')
            return
        if current in seen:
            break
        seen.append(current)
        session.mark = len(session.text)
        session.send(DOWN)
    raise TerminalError(f'no option {label!r} in {seen}')


def drive(argv, script, env, seconds=120, cwd='/'):
    """Run `argv` in a terminal and play `script`. Returns `{rc, text}`; the text has no escape codes."""
    deadline = time.time() + seconds
    session = Session(argv, env, cwd)
    try:
        for item in script:
            wanted = str(item['see'])
            session.mark += session.wait_for(lambda fresh: fresh.index(wanted) + len(wanted) if wanted in fresh else None, deadline, f'{wanted!r}')
            if 'choose' in item:
                choose(session, str(item['choose']), deadline)
            else:
                data = str(item['send'])
                session.send(KEYS[data] if data in KEYS else data.encode() + b'\r')
            session.mark = len(session.text)
        session.finish(deadline)
    except Exception:
        try:
            os.kill(session.pid, signal.SIGKILL)
            os.waitpid(session.pid, 0)
        except OSError:
            pass
        raise
    finally:
        os.close(session.fd)
    return {'rc': session.rc, 'text': session.text}
