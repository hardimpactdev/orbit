import os
import pathlib
import stat
import urllib.request

root = pathlib.Path("/")
op = os.environ["ORBIT_OPERATION_ID"]
if not op.isdecimal():
    raise SystemExit("metrics.input_invalid")
p = root / ("run/orbit-metrics-fpm-" + op)
fd = os.open(p, os.O_WRONLY | os.O_CREAT | os.O_TRUNC | os.O_NOFOLLOW, 0o600)
with (
    os.fdopen(fd, "wb") as out,
    urllib.request.urlopen(
        "https://github.com/cboxdk/fpm-exporter/releases/download/v3.1.1/fpm-exporter-linux-amd64",
        timeout=30,
    ) as response,
):
    st = os.fstat(out.fileno())
    if not stat.S_ISREG(st.st_mode) or st.st_uid != 0 or st.st_mode & 0o077:
        raise SystemExit("metrics.service_binary_ownership_drift")
    data = response.read(128 * 1024 * 1024 + 1)
    if len(data) > 128 * 1024 * 1024:
        raise SystemExit("metrics.service_download_failed")
    out.write(data)
    out.flush()
    os.fsync(out.fileno())
print('{"downloaded":true}')
