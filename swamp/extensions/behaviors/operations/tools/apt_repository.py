"""One managed apt repository phase, executed by official SSH with public argv."""

import json
import os
import re
import stat
import subprocess
import sys
import tempfile
from pathlib import Path
from urllib.parse import urlsplit
from urllib.request import HTTPRedirectHandler, build_opener


class Refusal(Exception):
    def __init__(self, code):
        self.code = code


def refuse(code):
    raise Refusal("tool.repository_" + code)


def https(url):
    parsed = urlsplit(url)
    if (
        parsed.scheme != "https"
        or not parsed.hostname
        or parsed.username
        or parsed.password
        or parsed.query
        or parsed.fragment
        or re.search(r"\s", url)
    ):
        refuse("invalid")
    return url


class HttpsRedirect(HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, message, headers, newurl):
        https(newurl)
        return super().redirect_request(request, fp, code, message, headers, newurl)


def fetch(url, maximum):
    with build_opener(HttpsRedirect()).open(https(url), timeout=30) as response:
        data = response.read(maximum + 1)
    if not data or len(data) > maximum:
        refuse("unavailable")
    return data


def validate(repository):
    if (
        not isinstance(repository, dict)
        or set(repository)
        - {"name", "uris", "suites", "components", "architectures", "signing_key", "packages"}
        or not re.fullmatch(
            r"[a-z][a-z0-9]*(?:-[a-z0-9]+)*", repository.get("name", "")
        )
        or len(repository["name"]) > 63
        or repository["name"] in ("caddy", "php")
    ):
        refuse("invalid")
    for field in ("uris", "suites", "components", "architectures"):
        if field == "architectures" and field not in repository:
            continue
        values = repository.get(field)
        if (
            not isinstance(values, list)
            or not 1 <= len(values) <= 16
            or any(not isinstance(v, str) or not v for v in values)
            or len(set(values)) != len(values)
        ):
            refuse("invalid")
        for value in values:
            if field == "uris":
                https(value)
                if len(value) > 2048:
                    refuse("invalid")
            elif not re.fullmatch(
                r"[a-z0-9][a-z0-9-]{0,31}"
                if field == "architectures"
                else r"[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}",
                value,
            ):
                refuse("invalid")
    packages = repository.get("packages", [])
    if (
        not isinstance(packages, list)
        or len(packages) > 256
        or len(set(packages)) != len(packages)
        or any(
            not isinstance(p, str) or not re.fullmatch(r"[a-z0-9][a-z0-9+.-]{0,254}", p)
            for p in packages
        )
    ):
        refuse("invalid")
    key = repository.get("signing_key")
    if (
        not isinstance(key, dict)
        or set(key) != {"url", "fingerprint"}
        or not re.fullmatch(
            r"(?:[A-F0-9]{40}|[A-F0-9]{64})", key.get("fingerprint", "")
        )
    ):
        refuse("invalid")
    https(key["url"])


def paths(repository, root=Path("/")):
    name = "orbit-" + repository["name"]
    return (
        root / ("etc/apt/sources.list.d/" + name + ".sources"),
        root / ("etc/apt/keyrings/" + name + ".gpg"),
        root / ("etc/apt/preferences.d/" + name + ".pref"),
    )


def render(repository):
    lines = [
        "Types: deb",
        "URIs: " + " ".join(repository["uris"]),
        "Suites: " + " ".join(repository["suites"]),
        "Components: " + " ".join(repository["components"]),
    ]
    if repository.get("architectures"):
        lines.append("Architectures: " + " ".join(repository["architectures"]))
    lines.append("Signed-By: " + str(paths(repository)[1]))
    return "\n".join(lines) + "\n"


def render_pin(repository):
    # The vendor origin may serve only its declared packages, at a priority above the
    # distribution so same-named distribution packages never replace them. Every other
    # package from that origin stays below any installed or distribution version.
    blocks = []
    for host in dict.fromkeys(urlsplit(uri).hostname for uri in repository["uris"]):
        if repository.get("packages"):
            blocks.append(
                "Package: " + " ".join(sorted(repository["packages"]))
                + '\nPin: origin "' + host + '"\nPin-Priority: 990\n'
            )
        blocks.append('Package: *\nPin: origin "' + host + '"\nPin-Priority: 1\n')
    return "\n".join(blocks)


def command(argv, **kwargs):
    return subprocess.run(
        argv,
        check=True,
        stdin=subprocess.DEVNULL,
        capture_output=True,
        env={**os.environ, "LC_ALL": "C", "DEBIAN_FRONTEND": "noninteractive"},
        **kwargs,
    )


def verified_key(data, fingerprint):
    with tempfile.TemporaryDirectory(prefix="orbit-apt-key-") as directory:
        key = Path(directory) / "download"
        key.write_bytes(data)
        base = [
            "/usr/bin/gpg",
            "--batch",
            "--no-options",
            "--no-autostart",
            "--homedir",
            directory,
        ]
        result = command(
            [*base, "--with-colons", "--show-keys", "--fingerprint", str(key)],
            text=True,
        )
        primary = []
        want = False
        for line in result.stdout.splitlines():
            fields = line.split(":")
            if fields[0] in ("pub", "sub"):
                want = fields[0] == "pub"
            elif fields[0] == "fpr" and want:
                primary.append(fields[9])
                want = False
        if primary != [fingerprint]:
            refuse("key_mismatch")
        command([*base, "--import-options", "import-minimal", "--import", str(key)])
        return command([*base, "--export", fingerprint]).stdout


def safe_path(path, root=Path("/"), directory=False):
    parents = [p for p in path.parents if p == root or root in p.parents]
    for parent in reversed(parents):
        try:
            info = parent.lstat()
        except FileNotFoundError:
            continue
        if (
            not stat.S_ISDIR(info.st_mode)
            or info.st_uid != os.geteuid()
            or info.st_mode & 0o022
        ):
            refuse("failed")
    try:
        info = path.lstat()
    except FileNotFoundError:
        return
    kind = stat.S_ISDIR if directory else stat.S_ISREG
    if not kind(info.st_mode) or info.st_uid != os.geteuid() or info.st_mode & 0o022:
        refuse("failed")


def write(path, data, root=Path("/")):
    safe_path(path, root)
    with tempfile.NamedTemporaryFile(
        dir=path.parent, prefix=".orbit-", delete=False
    ) as stream:
        temporary = Path(stream.name)
        try:
            stream.write(data)
            stream.flush()
            os.fchmod(stream.fileno(), 0o644)
            os.replace(temporary, path)
        finally:
            temporary.unlink(missing_ok=True)


def prepare(repository, root=Path("/")):
    # Check every URI/suite before publishing either managed file. Apt then verifies signatures.
    try:
        for uri in repository["uris"]:
            for suite in repository["suites"]:
                release = fetch(
                    uri.rstrip("/") + "/dists/" + suite + "/InRelease", 8 * 1024 * 1024
                )
                if not release.startswith(b"-----BEGIN PGP SIGNED MESSAGE-----"):
                    refuse("unavailable")
        downloaded = fetch(repository["signing_key"]["url"], 2 * 1024 * 1024)
    except (OSError, ValueError):
        refuse("unavailable")
    key = verified_key(downloaded, repository["signing_key"]["fingerprint"])
    source, keyring, pin = paths(repository, root)
    # The apt directory exists on every apt Node; only Orbit's keyring and preference directories may be new.
    for directory in (keyring.parent, pin.parent):
        safe_path(directory, root, directory=True)
        directory.mkdir(mode=0o755, exist_ok=True)
    for path in (source, keyring, pin):
        safe_path(path, root)
    previous = [
        (path, path.read_bytes() if path.exists() else None)
        for path in (keyring, pin, source)
    ]
    try:
        write(keyring, key, root)
        write(pin, render_pin(repository).encode(), root)
        write(source, render(repository).encode(), root)
        try:
            command(
                [
                    "/usr/bin/apt-get",
                    "-o",
                    "Dir::Etc::sourcelist=" + str(source),
                    "-o",
                    "Dir::Etc::sourceparts=-",
                    "-o",
                    "APT::Get::List-Cleanup=0",
                    "-o",
                    "APT::Update::Error-Mode=any",
                    "update",
                ]
            )
        except subprocess.SubprocessError:
            refuse("unavailable")
    except (OSError, Refusal):
        for path, data in previous:
            if data is None:
                path.unlink(missing_ok=True)
            else:
                write(path, data, root)
        raise


def cleanup(repository, root=Path("/")):
    for path in paths(repository, root):
        safe_path(path, root)
        path.unlink(missing_ok=True)


def adopt(repository, root=Path("/")):
    source, keyring, pin = paths(repository, root)
    try:
        if source.read_text() != render(repository) or pin.read_text() != render_pin(repository):
            refuse("adoption_mismatch")
        verified_key(keyring.read_bytes(), repository["signing_key"]["fingerprint"])
    except FileNotFoundError:
        refuse("adoption_mismatch")


def main():
    try:
        action, encoded = sys.argv[1:]
        repository = json.loads(encoded)
        validate(repository)
        {"prepare": prepare, "cleanup": cleanup, "adopt": adopt}[action](repository)
        result = {"ready": True}
    except Refusal as error:
        result = {"ready": False, "error_code": error.code}
    except (OSError, ValueError, KeyError, TypeError, subprocess.SubprocessError):
        result = {"ready": False, "error_code": "tool.repository_failed"}
    print(json.dumps(result))


if __name__ == "__main__":
    main()
