export const PHP_RUNTIME = String.raw`import contextlib
import fcntl
import os
import pathlib
import re
import stat

P = pathlib.Path
class Refused(Exception):
    pass
def check(value, reason):
    if not value:
        raise Refused(reason)
def directory(path):
    return path.is_dir()
class Host:
    def __init__(self):
        self.locks = P('/run/lock')
        self.root_uid = self.root_gid = 0
class Runtime:
    def __init__(self, host, data, home=False):
        self.user = data['user']
        check(re.fullmatch(r'[a-z_][a-z0-9_-]{0,31}', self.user), 'valid account')
@contextlib.contextmanager
def runtime_lock(h, user):
    check(directory(h.locks), "lock directory exists")
    fd = os.open(h.locks / ("orbit-php-" + user + ".lock"),
                 os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        m = os.fstat(fd)
        check(stat.S_ISREG(m.st_mode) and m.st_uid == h.root_uid
              and m.st_gid == h.root_gid
              and stat.S_IMODE(m.st_mode) == 0o600, "trusted runtime lock")
        fcntl.flock(fd, fcntl.LOCK_EX)
        yield
    finally:
        os.close(fd)
`;
export const PHP_ACTIONS = String.raw`from runtime_common import Host, Runtime, runtime_lock


def context():
    data = {'user': 'orbit_app'}
    host = Host()
    runtime = Runtime(host, data)
    return host, runtime, data
def stage(host, runtime, data):
    runtime = Runtime(host, data, True)
    with runtime_lock(host, runtime.user):
        pass
def execute(action):
    return action(*context())
`;
export const PHP_ENTRY = String.raw`from monitor_common import execute, stage

execute(stage)
`;
