"""Bounded Laravel log observations; messages, arguments and local paths stay private."""

import re
from pathlib import Path

HEADER = re.compile(
    r"^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] [^.\[\]\s]+\.(DEBUG|INFO|NOTICE|WARNING|ERROR|CRITICAL|ALERT|EMERGENCY): ",
    re.MULTILINE,
)
BUDGET = 1048576
CAP = 8388608


def signal(record, roots):
    header = HEADER.search(record)
    exception = re.search(
        r"\[object\] \(([A-Za-z_\\][A-Za-z0-9_\\]{0,511})\(code:\s*(-?\d{1,6})\)",
        record,
    )
    if (
        not header
        or header[2] not in ("ERROR", "CRITICAL", "ALERT", "EMERGENCY")
        or not exception
    ):
        return None
    name = exception[1].replace("\\\\", "\\")
    base = name.split("\\")[-1]
    statuses = {
        "BadRequestHttpException": 400,
        "UnauthorizedHttpException": 401,
        "AccessDeniedHttpException": 403,
        "NotFoundHttpException": 404,
        "MethodNotAllowedHttpException": 405,
        "NotAcceptableHttpException": 406,
        "ConflictHttpException": 409,
        "GoneHttpException": 410,
        "LengthRequiredHttpException": 411,
        "PreconditionFailedHttpException": 412,
        "PreconditionRequiredHttpException": 428,
        "UnsupportedMediaTypeHttpException": 415,
        "UnprocessableEntityHttpException": 422,
        "LockedHttpException": 423,
        "TooManyRequestsHttpException": 429,
        "ServiceUnavailableHttpException": 503,
    }
    status = (
        int(exception[2])
        if base == "HttpException" and 100 <= int(exception[2]) <= 599
        else statuses.get(base)
    )
    if base == "ValidationException" or status and status < 500:
        return None
    frame = None
    for entry in re.finditer(
        r"^#\d+ (.{1,2048}?)\(\d+\): (.{1,2048})$", record, re.MULTILINE
    ):
        path = entry[1].replace("\\", "/")
        root = next((root for root in roots if path.startswith(root + "/app/")), None)
        if root is None:
            continue
        relative = path[len(root) + 1 :]
        if not re.fullmatch(
            r"app/[A-Za-z0-9_./-]+", relative
        ) or ".." in relative.split("/"):
            continue
        call = re.sub(r"\([^)]*\)$", "", entry[2].replace("\\\\", "\\").rstrip())
        call = re.sub(r":\d+(?=})", "", call)
        if re.fullmatch(r"[A-Za-z0-9_\\:>{}().-]{1,1024}", call):
            frame = relative + ":" + call
            break
    if frame is None:
        return None
    request = re.search(r'"request_id"\s*:\s*"([A-Za-z0-9_.:-]{1,128})"', record)
    return {
        "exception_class": name,
        "frame": frame,
        "request_id": request[1] if request else None,
        "recorded_at": header[1],
    }


def collect(body):
    directory = Path(body["log_directory"])
    base = Path(body["base_directory"])
    if not directory.is_absolute() or not base.is_absolute():
        raise ValueError("log path")
    files = []
    if directory.exists():
        for path in directory.iterdir():
            if re.fullmatch(r"laravel.*\.log", path.name) and path.is_file():
                files.append((path, path.stat()))
    current = next((row for row in files if row[0].name == "laravel.log"), None)
    daily = sorted(
        (row for row in files if row[0].name.startswith("laravel-")),
        key=lambda row: (row[1].st_mtime_ns, str(row[0])),
    )
    current = current or (daily[-1] if daily else None)

    def cursor(row, offset=None):
        return {
            "path": str(row[0]),
            "inode": row[1].st_ino,
            "offset": row[1].st_size if offset is None else offset,
        }

    old = body.get("cursor")
    if old is None:
        return {"cursor": cursor(current) if current else None, "signals": []}
    if (
        Path(old["path"]).parent != directory
        or not isinstance(old["inode"], int)
        or not isinstance(old["offset"], int)
        or old["offset"] < 0
    ):
        raise ValueError("log cursor")
    known = next(
        (
            row
            for row in files
            if str(row[0]) == old["path"] and row[1].st_ino == old["inode"]
        ),
        None,
    )
    known = known or next((row for row in files if row[1].st_ino == old["inode"]), None)
    reading = known or current
    if not reading:
        return {"cursor": old, "signals": []}
    start = min(old["offset"], reading[1].st_size) if known else 0
    with reading[0].open("rb") as stream:
        stream.seek(start)
        data = stream.read(BUDGET)
        eof = start + len(data) >= reading[0].stat().st_size
        text = data.decode(errors="replace")
        starts = list(HEADER.finditer(text))
        if not eof and len(starts) < 2:
            data += stream.read(CAP - len(data))
            eof = start + len(data) >= reading[0].stat().st_size
            text = data.decode(errors="replace")
            starts = list(HEADER.finditer(text))
    consumed = (
        len(data)
        if eof or len(data) == CAP and len(starts) < 2
        else len(text[: starts[-1].start()].encode())
        if len(starts) > 1
        else 0
    )
    text = data[:consumed].decode(errors="replace")
    starts = [match.start() for match in HEADER.finditer(text)]
    records = [
        text[start : (starts[index + 1] if index + 1 < len(starts) else len(text))]
        for index, start in enumerate(starts)
    ]
    next_cursor = (
        cursor(current, 0)
        if start + consumed >= reading[0].stat().st_size
        and current
        and current[0] != reading[0]
        else cursor(reading, start + consumed)
    )
    roots = [str(base).rstrip("/"), str(base.resolve()).rstrip("/")]
    return {
        "cursor": next_cursor,
        "signals": [
            value for record in records if (value := signal(record, roots)) is not None
        ],
    }


if __name__ == "__main__":
    import json

    print(json.dumps(collect(json.loads("__DOCTOR_LOG_INPUT__"))))
