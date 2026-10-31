import hashlib
import pathlib
import stat

root = pathlib.Path("/")
for path, digest in [
    (
        "usr/local/bin/orbit-cadvisor",
        "6f258bed28113218c88729634c47ead8d928b81b1504b75fd911b98d29003a87",
    ),
    (
        "usr/local/bin/orbit-fpm-exporter",
        "570d2b3d3284179c47acff8efd8f73ea650e42a2c6bd08c0a8e95cb4b0e3ca98",
    ),
]:
    p = root / path
    for parent in [
        *reversed([q for q in p.parents if q == root or root in q.parents]),
        p,
    ]:
        try:
            st = parent.lstat()
        except FileNotFoundError:
            continue
        if (
            stat.S_ISLNK(st.st_mode)
            or st.st_uid != 0
            or st.st_mode & 0o022
            or (parent != p and not stat.S_ISDIR(st.st_mode))
        ):
            raise SystemExit("metrics.binary_ownership_drift")
    if p.exists():
        if not p.is_file():
            raise SystemExit("metrics.binary_ownership_drift")
        with p.open("rb") as stream:
            if hashlib.file_digest(stream, "sha256").hexdigest() != digest:
                raise SystemExit("metrics.binary_ownership_drift")
print('{"owned":true}')
