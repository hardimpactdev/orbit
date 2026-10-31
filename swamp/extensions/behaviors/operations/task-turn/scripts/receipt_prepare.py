import json
import pathlib
import subprocess
import sys

value = json.load(sys.stdin)
path = subprocess.check_output(
    ["git", "rev-parse", "--path-format=absolute", "--git-path", "orbit"],
    cwd=value["checkout_path"],
    text=True,
).strip()
root = pathlib.Path(path)
root.mkdir(mode=0o700, parents=True, exist_ok=True)
for name in ["turn", "turn.json", "receipt.json"]:
    if (root / name).is_symlink():
        raise SystemExit("tasks.receipt_path_invalid")
(root / "turn").write_text(
    pathlib.Path(__file__).with_name("receipt_helper.py").read_text()
)
(root / "turn").chmod(0o700)
(root / "turn.json").write_text(
    json.dumps(
        {
            "thread": value["thread_id"],
            "role": value["role"],
            "deliverables": value["deliverables"],
        }
    )
)
(root / "turn.json").chmod(0o600)
(root / "receipt.json").unlink(missing_ok=True)
print(json.dumps({"prepared": True}))
