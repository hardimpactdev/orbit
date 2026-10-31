"""Three-way merge of canonical Caddy source/scope groups; no host effects."""

import re

MARKER = "# Managed by Orbit: Node Caddy build\n"
SECTION = re.compile(r"(?m)^# orbit: ([^\s]+) ([^\r\n]+)\n")


class PublicationConflict(ValueError):
    pass


def split(content):
    if (
        not isinstance(content, str)
        or not content.startswith(MARKER)
        or "\x00" in content
    ):
        raise PublicationConflict("caddy.foreign_config")
    matches = list(SECTION.finditer(content))
    header = content[: matches[0].start()] if matches else content
    # The renderer separates source blocks by one blank line.
    header = header.rstrip("\n") + "\n"
    groups = {}
    for index, match in enumerate(matches):
        end = matches[index + 1].start() if index + 1 < len(matches) else len(content)
        key = match.group(1), match.group(2)
        groups.setdefault(key, []).append(
            content[match.start() : end].rstrip("\n") + "\n"
        )
    return header, groups


def merge(before, after, current):
    """Apply only changed owned groups, preserving current unrelated revisions.

    Reversing before and after applies restoration. A conflicting owned group
    refuses the entire merge before producing any candidate.
    """
    before_header, original = split(before)
    after_header, desired = split(after)
    current_header, observed = split(current)
    if before_header != after_header or current_header != before_header:
        raise PublicationConflict("caddy.global_revision_conflict")
    result = dict(observed)
    for key in dict.fromkeys([*original, *desired]):
        previous, next_value, actual = (
            original.get(key),
            desired.get(key),
            observed.get(key),
        )
        if previous == next_value:
            continue
        if actual != previous and actual != next_value:
            raise PublicationConflict("caddy.owned_revision_conflict")
        if next_value is None:
            result.pop(key, None)
        else:
            result[key] = next_value
    blocks = [block for group in result.values() for block in group]
    return before_header + ("\n" + "\n".join(blocks) if blocks else "")
