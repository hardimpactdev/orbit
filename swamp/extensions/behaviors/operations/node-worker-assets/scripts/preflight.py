"""Observe the admitted worker account, platform and available memory."""
import platform
import pwd
import sys

WORKER_USER = ""
MINIMUM_MEMORY_BYTES = 0


def main():
    if platform.system() != "Linux" or platform.machine() != "x86_64":
        raise ValueError()
    if pwd.getpwnam(WORKER_USER).pw_uid == 0:
        raise ValueError()
    with open("/proc/meminfo", encoding="ascii") as stream:
        rows = [line for line in stream if line.startswith("MemAvailable:")]
    fields = rows[0].split() if len(rows) == 1 else []
    if (len(fields) != 3 or fields[2] != "kB" or not fields[1].isdigit()
            or int(fields[1]) * 1024 < MINIMUM_MEMORY_BYTES):
        raise ValueError()


if __name__ == "__main__":
    try:
        main()
    except (OSError, ValueError, KeyError):
        print("Node worker account, platform or available RAM check failed", file=sys.stderr)
        sys.exit(1)
