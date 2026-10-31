import hashlib
import os
import pathlib

op = os.environ["ORBIT_OPERATION_ID"]
if not op.isdecimal():
    raise SystemExit("metrics.input_invalid")
p = pathlib.Path("/run/orbit-metrics-cadvisor-" + op)
with p.open("rb") as stream:
    digest = hashlib.file_digest(stream, "sha256").hexdigest()
if digest != "6f258bed28113218c88729634c47ead8d928b81b1504b75fd911b98d29003a87":
    raise SystemExit("metrics.cadvisor_checksum_mismatch")
print('{"verified":true}')
