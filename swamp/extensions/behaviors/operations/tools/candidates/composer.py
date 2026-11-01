import json
import re

from support import INPUT, finish, query


def observe():
    name = INPUT["package"]
    install = INPUT["kind"] == "tool.install"
    command = ["/usr/bin/composer", "global", "require" if install else "update", name + ":*" if install else name, "--dry-run", "--no-interaction", "--no-ansi", "--no-progress", "--no-audit", "--with-all-dependencies"]
    result = query(command, {"COMPOSER_HOME": "/opt/orbit/composer"})
    text = result.stdout + "\n" + result.stderr
    found, operations, noop = [], 0, 0
    for line in text.splitlines():
        if line == "Nothing to install, update or remove":
            noop += 1
            continue
        match = re.fullmatch(r"\s*-\s+(Locking|Installing)\s+(\S+)\s+\(([^\s()]+)\)\s*", line) or re.fullmatch(r"\s*-\s+(Upgrading|Downgrading)\s+(\S+)\s+\([^\s()]+\s+=>\s+([^\s()]+)\)\s*", line)
        if match:
            operations += 1
            if match[2] == name:
                found.append((match[1], match[3]))
        elif re.match(r"\s*-\s+(Locking|Installing|Upgrading|Downgrading|Removing)(?:\s|$)", line):
            raise ValueError("Unreadable Composer candidate")
    if not noop and (len(found) == 1 or len(found) == 2 and found[0][0] == "Locking" and found[1][0] == "Installing" and found[0][1] == found[1][1]):
        return {"version": found[-1][1]}
    if noop == 1 and operations == 0:
        rows = json.loads(query(["/usr/bin/composer", "global", "show", "--format=json", "--no-ansi"], {"COMPOSER_HOME": "/opt/orbit/composer"}).stdout)["installed"]
        selected = [r["version"] for r in rows if r["name"] == name]
        if len(selected) == 1:
            return {"version": selected[0]}
    raise ValueError("Unreadable Composer candidate")
finish(observe)
