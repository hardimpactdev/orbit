"""Canonical shared FPM pool sections, extracted from the Route PHP publisher."""

import re

POOL = re.compile(
    r"^orbit-(?:instance|workspace|app-instance)-[1-9][0-9]*(?:-[0-9a-f]{8})?$"
)


def split(text):
    if not isinstance(text, str) or len(text.encode()) > 1048576 or "\0" in text:
        raise ValueError("php.shared_pool_invalid")
    prefix, pools, name, directories = "", {}, None, set()
    for line in text.splitlines(True):
        trimmed = line.strip()
        if trimmed.startswith("["):
            name = trimmed[1:-1] if trimmed.endswith("]") else ""
            if not POOL.fullmatch(name) or name in pools:
                raise ValueError("php.shared_pool_invalid")
            pools[name] = ""
        if name is not None:
            if "=" in trimmed:
                key, value = map(str.strip, trimmed.split("=", 1))
                if key == "chdir":
                    if (
                        name in directories
                        or not value.startswith("/")
                        or any(ord(c) < 32 for c in value)
                    ):
                        raise ValueError("php.shared_pool_invalid")
                    directories.add(name)
            pools[name] += line
        elif not trimmed or trimmed.startswith((";", "#")):
            prefix += line
        else:
            raise ValueError("php.shared_pool_invalid")
    return prefix, pools


def merge(before, after, current):
    _, original = split(before)
    _, desired = split(after)
    prefix, observed = split(current)
    result = dict(observed)
    for name in dict.fromkeys([*original, *desired]):
        previous, next_value, actual = (
            original.get(name),
            desired.get(name),
            observed.get(name),
        )
        if previous == next_value:
            continue
        if actual != previous and actual != next_value:
            raise ValueError("php.owned_revision_conflict")
        if next_value is None:
            result.pop(name, None)
        else:
            result[name] = next_value
    return (
        ""
        if observed and not result
        else prefix + "".join(result[name] for name in sorted(result))
    )
