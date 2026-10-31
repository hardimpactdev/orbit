import json
import pathlib
import subprocess
import sys

from public_output import emit

value = json.load(sys.stdin)
path = subprocess.check_output(
    ["git", "rev-parse", "--path-format=absolute", "--git-path", "orbit"],
    cwd=value["checkout_path"],
    text=True,
).strip()
receipt = pathlib.Path(path) / "receipt.json"
if not receipt.exists():
    print("null")
elif receipt.is_symlink() or receipt.stat().st_size > 65536:
    raise SystemExit("tasks.receipt_invalid")
else:
    emit(json.loads(receipt.read_text()))
