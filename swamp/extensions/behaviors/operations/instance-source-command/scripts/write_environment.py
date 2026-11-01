"""Write one captured environment copy; private content enters only through stdin."""
import json
import os
import sys
import tempfile
from pathlib import Path

material = json.load(sys.stdin)
if isinstance(material, str):
    material = json.loads(material)
path = Path(sys.argv[1]) / ".env"
if path.is_symlink():
    raise ValueError("instance.source_invalid")
fd, temporary = tempfile.mkstemp(prefix=".env-native-", dir=path.parent)
try:
    with os.fdopen(fd, "w") as stream:
        stream.write(material["content"])
        stream.flush()
        os.fsync(stream.fileno())
    os.chmod(temporary, 0o640)
    os.replace(temporary, path)
finally:
    if os.path.exists(temporary):
        os.unlink(temporary)
print(json.dumps({"keys": material["keys"]}))
