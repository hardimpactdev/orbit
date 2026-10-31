"""Remove only an admitted unused leaf from its two owned locations."""

import shutil

from leaf import admitted, roots
from public_input import emit, intent


def main():
    data, _account = admitted(intent())
    if data.get("in_use") is not False:
        raise ValueError("app-dev.certificate_in_use")
    for path in roots(data):
        if path.exists():
            shutil.rmtree(path)
    return {"applied": True}


if __name__ == "__main__":
    emit(main)
