"""Check a Schedule's account, working directory and command copy before the command runs.

argv: user group home shell working_directory command_hash; the command arrives on stdin.
"""

import grp
import hashlib
import os
import pwd
import subprocess
import sys


def account(user, group, home, shell):
    entry = pwd.getpwnam(user)
    return (
        entry.pw_name == user
        and entry.pw_uid > 0
        and entry.pw_dir == home
        and entry.pw_shell == shell
        and grp.getgrnam(group).gr_name == group
    )


def directory(user, path):
    if pwd.getpwnam(user).pw_uid == os.geteuid():
        return os.path.isdir(path) and os.access(path, os.X_OK)
    for test in ("-d", "-x"):
        probe = ["/usr/bin/sudo", "-n", "-u", user, "--", "/usr/bin/test", test, path]
        if subprocess.run(probe, check=False, timeout=10).returncode != 0:
            return False
    return True


def command(value, expected):
    try:
        text = value.decode("utf-8")
    except UnicodeDecodeError:
        return False
    return (
        0 < len(text) <= 4096
        and not any(c in text for c in "\0\r\n")
        and hashlib.sha256(value).hexdigest() == expected
    )


def check(args, stdin):
    if len(args) != 6:
        return "the check needs six arguments"
    user, group, home, shell, path, expected = args
    try:
        if not account(user, group, home, shell):
            return f"the account {user} does not match the Schedule"
    except (KeyError, OSError):
        return f"the account {user} or group {group} does not exist"
    if not directory(user, path):
        return f"{user} cannot enter {path}"
    if not command(stdin, expected):
        return "the command copy does not match the recorded hash"
    return None


if __name__ == "__main__":
    problem = check(sys.argv[1:], sys.stdin.buffer.read(16386))
    if problem is not None:
        print(problem, file=sys.stderr)
        raise SystemExit(1)
