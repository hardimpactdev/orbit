import os

from bw import cli, emit, session_file


def unlock():
    value = cli(
        "unlock", "--passwordenv", "BW_PASSWORD", "--raw", authenticate="unlock"
    )
    path = session_file()
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(descriptor, "wb") as stream:
        stream.write(value.strip())
    return {"unlocked": True}


if __name__ == "__main__":
    emit(unlock)
