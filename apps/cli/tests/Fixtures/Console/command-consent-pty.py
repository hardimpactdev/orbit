"""Exercise the real command in a PTY; return raw bytes, status, and termios restoration."""
import base64
import errno
import fcntl
import json
import os
import pty
import select
import signal
import struct
import subprocess
import sys
import termios
import time

def reader_has_children(pid):
    if sys.platform == 'linux':
        with open(f'/proc/{pid}/task/{pid}/children') as children:
            return bool(children.read().strip())
    parents = subprocess.check_output(['ps', '-A', '-o', 'ppid='], text=True)
    return str(pid) in parents.split()


configuration = json.loads(open(sys.argv[3]).read())
master, slave = pty.openpty()
fcntl.ioctl(slave, termios.TIOCSWINSZ, struct.pack('HHHH', 50, configuration.get('columns', 200), 0, 0))
before = termios.tcgetattr(slave)
process = subprocess.Popen(sys.argv[1:4], stdin=slave, stdout=slave, stderr=slave,
                           env={**os.environ, 'TERM': 'xterm-256color', 'PAO_DISABLE': '1'})
output = bytearray()
keys = [base64.b64decode(key) for key in configuration['keys']]
ready = False
awaiting_output = False
idle_timeout = configuration.get('idle_timeout', 10)
last_output = time.monotonic()
while process.poll() is None:
    if select.select([master], [], [], 0.02)[0]:
        try:
            chunk = os.read(master, 65536)
            if chunk:
                output.extend(chunk)
                last_output = time.monotonic()
                awaiting_output = False
        except OSError as error:
            if error.errno != errno.EIO:
                raise
    if time.monotonic() - last_output > idle_timeout:
        process.kill()
        process.wait()
        raise RuntimeError('Command fixture timed out: ' + output.decode(errors='replace'))
    ready = ready or configuration['prompt'].encode() in output
    pending_input = struct.unpack('i', fcntl.ioctl(slave, termios.FIONREAD, struct.pack('i', 0)))[0]
    if ready and keys and not awaiting_output and pending_input == 0:
        # PTY input delivery is asynchronous. An empty FIONREAD snapshot can
        # precede delivery, so first observe the complete key queued while the
        # reader is stopped. After resuming, zero proves it has consumed it.
        os.kill(process.pid, signal.SIGSTOP)
        _, status = os.waitpid(process.pid, os.WUNTRACED)
        if not os.WIFSTOPPED(status):
            process.returncode = os.waitstatus_to_exitcode(status)
            break
        try:
            # A completed prompt can restore canonical mode before the next
            # prompt configures raw input. Do not type into that transition.
            # stty runs in a child process. A stopped reader with no children
            # cannot change terminal mode between this check and the write.
            if reader_has_children(process.pid):
                continue
            if termios.tcgetattr(slave)[3] & (termios.ICANON | termios.ISIG | termios.ECHO):
                continue
            key = keys.pop(0)
            if os.write(master, key) != len(key):
                raise RuntimeError('Incomplete PTY key write')
            deadline = time.monotonic() + idle_timeout
            while struct.unpack('i', fcntl.ioctl(slave, termios.FIONREAD, struct.pack('i', 0)))[0] < len(key):
                remaining = deadline - time.monotonic()
                if remaining <= 0 or not select.select([slave], [], [], remaining)[0]:
                    raise RuntimeError('PTY key was not delivered: ' + repr(key) + ' ' +
                                       repr(termios.tcgetattr(slave)) + '\n' + output.decode(errors='replace'))
        finally:
            os.kill(process.pid, signal.SIGCONT)
        awaiting_output = True
while select.select([master], [], [], 0.02)[0]:
    try:
        chunk = os.read(master, 65536)
    except OSError as error:
        if error.errno != errno.EIO:
            raise
        break
    if not chunk:
        break
    output.extend(chunk)
after = termios.tcgetattr(slave)
# Darwin sets PENDIN when stty restores canonical input. It is a transient
# kernel input-reprocessing marker, not a change to the saved terminal mode.
if sys.platform == 'darwin':
    before[3] &= ~termios.PENDIN
    after[3] &= ~termios.PENDIN
restored = after == before
os.close(master)
os.close(slave)
print(json.dumps({'status': process.returncode, 'raw': base64.b64encode(output).decode(),
                  'restored': restored, 'termios_diff': repr([(i, a, b) for i, (a, b) in enumerate(zip(before, after)) if a != b]), 'prompt_seen': ready, 'keys_remaining': len(keys)}))
