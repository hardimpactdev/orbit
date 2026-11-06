#!/bin/bash
# Installs the web build of the commit from its CI artifact, checked against the artifact digest. Arguments: release id, repository, artifact id, size, digest. The GitHub token arrives on stdin.
# Run by the step "Install the web build" of swamp/workflows/workflow-operation-gateway-release.yaml.
set -euo pipefail
id=$1 repository=$2 artifact=$3 size=$4 digest=${5#sha256:}
web=/home/orbit/web/releases
fail() { echo "$1: $2" >&2; exit 1; }
[ ! -f "$web/$id/index.html" ] || exit 0
# A directory without index.html is a stopped install; it is replaced.
rm -rf "${web:?}/$id"
[ "$size" -le 134217728 ] || fail gateway.release_web_build_invalid "artifact of $size bytes is above 128 MiB"
work=$(mktemp -d) && trap 'rm -rf "$work"' EXIT
umask 077
{ printf 'Authorization: Bearer '; cat; printf '\n'; } > "$work/header"
# curl sends the custom header only to api.github.com, not to the storage host it redirects to.
curl -fsSL --max-time 600 -H @"$work/header" -o "$work/web.zip" \
  "https://api.github.com/repos/$repository/actions/artifacts/$artifact/zip" || fail gateway.release_web_build_unavailable "artifact $artifact download failed"
[ "$(sha256sum "$work/web.zip" | cut -d' ' -f1)" = "$digest" ] || fail gateway.release_web_build_invalid "artifact $artifact does not match its digest"
owner=$(stat -c %U /home/orbit/web)
install -d -m 0750 -o "$owner" -g caddy "$web"
partial="$web/.$id.partial" && rm -rf "$partial"
python3 -I -c '
import os, stat, sys, zipfile
archive, target = sys.argv[1], sys.argv[2]
with zipfile.ZipFile(archive) as z:
    entries = z.infolist()
    if len(entries) > 20000 or sum(e.file_size for e in entries) > 536870912:
        sys.exit("too many entries or bytes")
    names = set()
    for e in entries:
        name = e.filename
        mode = (e.external_attr >> 16) & 0o170000
        if (name.startswith("/") or "\\" in name or any(p in ("", ".", "..") for p in name.rstrip("/").split("/"))
                or name in names or e.flag_bits & 1 or mode not in (0, stat.S_IFREG, stat.S_IFDIR)):
            sys.exit("unsafe entry " + repr(name))
        names.add(name)
    z.extractall(target)
if not os.path.isfile(os.path.join(target, "index.html")):
    sys.exit("no index.html")
' "$work/web.zip" "$partial" || { rm -rf "$partial"; fail gateway.release_web_build_invalid "artifact $artifact is no valid web build"; }
chown -R "$owner:caddy" "$partial"
find "$partial" -type d -exec chmod 0750 {} + && find "$partial" -type f -exec chmod 0640 {} +
mv -T "$partial" "$web/$id"
