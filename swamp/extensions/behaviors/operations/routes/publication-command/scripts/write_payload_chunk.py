"""Write one public fragment; native forEach supplies disjoint offsets."""

import base64
import json
import os
import stat
import tempfile

from payload_common import metadata, stage_for

value = metadata()
stage = stage_for(value)
chunk = json.loads(os.environ["ORBIT_CADDY_DELTA_CHUNK"])
offset = chunk["offset"]
if (
    not isinstance(offset, int)
    or offset < 0
    or offset % 32768
    or offset >= value["content_bytes"]
):
    raise ValueError("caddy.delta_chunk_invalid")
content = base64.b64decode(chunk["data"], validate=True)
if len(content) != min(32768, value["content_bytes"] - offset):
    raise ValueError("caddy.delta_chunk_invalid")
path = stage / str(offset)
if path.exists() or path.is_symlink():
    info = path.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_uid != 0:
        raise ValueError("caddy.delta_chunk_conflict")
fd, temporary = tempfile.mkstemp(prefix=".chunk-", dir=stage)
try:
    with os.fdopen(fd, "wb") as output:
        output.write(content)
        output.flush()
        os.fsync(output.fileno())
    os.replace(temporary, path)
finally:
    if os.path.lexists(temporary):
        os.unlink(temporary)
print('{"written":true}')
