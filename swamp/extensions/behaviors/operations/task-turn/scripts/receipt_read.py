import json
import pathlib
import sys

from public_output import emit

receipt = pathlib.Path(sys.argv[1])
if not receipt.exists():
    print("null")
elif receipt.is_symlink() or receipt.stat().st_size > 65536:
    raise SystemExit("tasks.receipt_invalid")
else:
    emit(json.loads(receipt.read_text()))
