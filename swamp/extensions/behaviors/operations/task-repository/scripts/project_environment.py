"""Strict Project dotenv parsing shared by Instance and Tasks setup actions."""

import os
import re
import stat


class ProjectEnvironmentRefusal(ValueError):
    """A public refusal code with private redaction targets, never raw diagnostic text."""

    def __init__(self, code, targets=()):
        super().__init__(code)
        self.redaction_targets = frozenset(target for target in targets if target)


def parse_project_environment(dotenv):
    if len(dotenv.encode()) > 1048576:
        raise ProjectEnvironmentRefusal("instance.step_not_admitted")
    env = {}
    raw_lines = re.split(r"\r\n|\n|\r", dotenv)

    def refuse():
        targets = [line for line in raw_lines if line.strip() and not line.lstrip().startswith("#")]
        targets.extend(value for value in env.values() if value)
        raise ProjectEnvironmentRefusal("instance.step_not_admitted", targets)

    def resolve(text, quoted=False):
        escapes = {
            "n": chr(10),
            "r": chr(13),
            "t": chr(9),
            "f": chr(12),
            "v": chr(11),
            chr(92): chr(92),
            chr(34): chr(34),
            "$": "$",
        }
        chars, dollars = [], []
        position = 0
        while position < len(text):
            character = text[position]
            if quoted and character == "\\":
                position += 1
                if position == len(text) or text[position] not in escapes:
                    refuse()
                chars.append(escapes[text[position]])
            else:
                if character == "$":
                    dollars.append(len(chars))
                chars.append(character)
            position += 1
        value = "".join(chars)
        tail, cut = "", len(value)
        # phpdotenv Resolver processes original dollar positions from right to left.
        for position in reversed(dollars):
            tail = re.sub(r'^\$\{([a-zA-Z0-9_.]+)\}',
                          lambda match: env.get(match.group(1), match.group(0)), value[position:cut] + tail, count=1)
            cut = position
        return value[:cut] + tail

    entries, buffered = [], []
    for line in raw_lines:
        # Match phpdotenv Parser/Lines entry boundaries, including quotes inside comments or values.
        quote_count = len(re.findall(r'(?<!\\)"', line.replace('\\\\', '')))
        position = line.find('="')
        starts = not buffered and position >= 0 and ("#" not in line or line.index("#") > position) and quote_count <= 1
        if buffered or starts:
            buffered.append(line)
            if quote_count > (1 if starts else 0):
                entries.append("\n".join(buffered))
                buffered = []
        else:
            entries.append(line)
    if buffered:
        entries.append("\n".join(buffered))

    for line in entries:
        if not line.strip() or line.lstrip().startswith("#"):
            continue
        match = re.fullmatch(r"[ \t]*(?:export[ \t]+)?([A-Za-z_][A-Za-z0-9_]{0,254})[ \t]*=[ \t]*(.*)", line, re.DOTALL)
        if not match:
            refuse()
        key, text = match.groups()
        if key.startswith(("LD_", "BASH_")):
            refuse()
        if text.startswith(('"', "'")):
            quote, text = text[0], text[1:]
            position = 0
            escaped = False
            while position < len(text):
                character = text[position]
                if escaped:
                    escaped = False
                elif character == "\\" and quote == '"':
                    escaped = True
                elif character == quote:
                    rest = text[position + 1:].strip(" \n\r\t\0\v\f")
                    if rest and not rest.startswith("#"):
                        refuse()
                    text = text[:position]
                    break
                position += 1
            else:
                refuse()
            if quote == '"':
                text = resolve(text, quoted=True)
        else:
            text = text.split("#", 1)[0].rstrip(" \n\r\t\0\v\f")
            if re.search(r"[ \t\v\f\r\n]", text):
                refuse()
            text = resolve(text)
        if "\0" in text or key in env or len(text.encode()) > 65536 or len(env) >= 1024:
            refuse()
        env[key] = text
    return env


def load_project_environment(root):
    try:
        descriptor = os.open(
            os.path.join(root, ".env"),
            os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC | os.O_NONBLOCK,
        )
    except FileNotFoundError:
        return {}
    except OSError:
        raise ProjectEnvironmentRefusal("instance.step_layout_invalid") from None
    with os.fdopen(descriptor, "r", encoding="utf-8", newline="") as stream:
        details = os.fstat(stream.fileno())
        if (
            not stat.S_ISREG(details.st_mode)
            or details.st_uid != os.getuid()
            or details.st_gid != os.getgid()
            or details.st_mode & 0o022
            or details.st_size > 1048576
        ):
            raise ProjectEnvironmentRefusal("instance.step_layout_invalid")
        try:
            return parse_project_environment(stream.read(1048577))
        except UnicodeError:
            raise ProjectEnvironmentRefusal("instance.step_not_admitted") from None
