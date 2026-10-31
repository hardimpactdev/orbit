import hashlib
import os
import pathlib

op = os.environ["ORBIT_OPERATION_ID"]
if not op.isdecimal():
    raise SystemExit("metrics.input_invalid")
p = pathlib.Path("/run/orbit-metrics-fpm-" + op)
with p.open("rb") as stream:
    digest = hashlib.file_digest(stream, "sha256").hexdigest()
if digest != "570d2b3d3284179c47acff8efd8f73ea650e42a2c6bd08c0a8e95cb4b0e3ca98":
    raise SystemExit("metrics.service_checksum_mismatch")
print('{"verified":true}')
