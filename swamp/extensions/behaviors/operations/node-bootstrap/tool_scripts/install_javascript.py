"""Install only the admitted application-role JavaScript runtimes and Vite+ lock."""

import hashlib
import json
import os
import pathlib
import pwd
import re
import shutil
import subprocess
import sys
import tarfile
import tempfile
import urllib.request
import zipfile

ROOT = pathlib.Path("/opt/orbit")
BIN = pathlib.Path("/usr/local/bin")


def install(value):
    account = pwd.getpwnam(value["user"])
    tools = value["tools"]
    home = pathlib.Path(account.pw_dir)
    candidates = [
        ROOT / "vite-plus",
        home / ".vite-plus",
        home / ".local/share/vite-plus",
    ]
    scope = candidates[-1]
    for candidate in candidates:
        if not candidate.exists():
            continue
        metadata = candidate.lstat()
        if (
            not candidate.is_dir()
            or candidate.is_symlink()
            or metadata.st_uid != account.pw_uid
        ):
            raise ValueError("node.tool_scope_conflict")
        if (candidate / "bin/vp").is_file():
            scope = candidate
            break
    if ROOT.exists() and (ROOT.is_symlink() or not ROOT.is_dir() or ROOT.stat().st_uid != 0):
        raise ValueError("tool.scope_conflict")
    for path in (scope / "bin", scope / "recipe", scope / "js_runtime", scope / "js_runtime/node"):
        if path.exists() and (path.is_symlink() or not path.is_dir() or path.stat().st_uid != account.pw_uid):
            raise ValueError("tool.scope_conflict")
    for name in ("node", "vp", "pnpm", "npm", "npx", *(["bun"] if "bun" in tools else [])):
        path = BIN / name
        if path.exists() or path.is_symlink():
            target = ROOT / "bun/bin/bun" if name == "bun" else scope / "bin" / name
            expected = '#!/bin/sh\n' + ('' if name == "bun" else 'export VP_HOME="' + str(scope) + '"\n') + 'exec "' + str(target) + '" "$@"\n'
            if path.is_symlink() or not path.is_file() or path.stat().st_uid != 0 or path.read_text() != expected:
                raise ValueError("tool.launcher_conflict")
    if scope == candidates[-1]:
        for parent in [home / ".local", home / ".local/share"]:
            if not parent.exists():
                parent.mkdir()
                os.chown(parent, account.pw_uid, account.pw_gid)
    for path in (
        ROOT,
        scope,
        scope / "bin",
        scope / "recipe",
        scope / "js_runtime/node",
    ):
        path.mkdir(parents=True, exist_ok=True)
    for path in (
        scope,
        scope / "bin",
        scope / "recipe",
        scope / "js_runtime",
        scope / "js_runtime/node",
    ):
        os.chown(path, account.pw_uid, account.pw_gid)
    environment = {
        "HOME": account.pw_dir,
        "VP_HOME": str(scope),
        "PATH": str(scope / "bin") + ":/usr/bin:/bin",
    }

    def user_command(argv):
        subprocess.run(
            [
                "sudo",
                "-n",
                "-u",
                account.pw_name,
                "-H",
                "--",
                "env",
                "-i",
                *[key + "=" + item for key, item in environment.items()],
                *map(str, argv),
            ],
            check=True,
            stdout=sys.stderr,
        )

    with tempfile.TemporaryDirectory(prefix="orbit-enrollment-js-") as temporary:
        work = pathlib.Path(temporary)
        for name in ("node", "vp-cli", "pnpm", *(["bun"] if "bun" in tools else [])):
            pin = tools[name]
            archive = work / name
            with (
                urllib.request.urlopen(pin["url"]) as response,
                archive.open("wb") as target,
            ):
                shutil.copyfileobj(response, target)
            with archive.open("rb") as stream:
                if (
                    hashlib.file_digest(stream, pin["algorithm"]).hexdigest()
                    != pin["digest"]
                ):
                    raise ValueError("node.tool_artifact_mismatch")
            stage = work / (name + "-extracted")
            stage.mkdir()
            if name == "bun":
                with zipfile.ZipFile(archive) as bundle:
                    bundle.extractall(stage)
            else:
                with tarfile.open(archive) as bundle:
                    bundle.extractall(stage, filter="data")
            if name == "node":
                target = scope / "js_runtime/node" / pin["version"]
                shutil.copytree(
                    stage / ("node-v" + pin["version"] + "-linux-x64"),
                    target,
                    dirs_exist_ok=True,
                )
            elif name == "vp-cli":
                shutil.copyfile(stage / "vp", scope / "bin/vp")
                (scope / "bin/vp").chmod(0o755)
                (scope / "bin/.vp-setup-complete").touch()
            elif name == "pnpm":
                target = scope / "recipe" / ("pnpm-" + pin["version"])
                shutil.copytree(stage / "package", target, dirs_exist_ok=True)
                shutil.copyfile(target / "pnpm", scope / "bin/pnpm")
                (scope / "bin/pnpm").chmod(0o755)
                current = scope / "recipe/pnpm-current"
                current.unlink(missing_ok=True)
                current.symlink_to(target)
            else:
                target = ROOT / "bun/bin"
                target.mkdir(parents=True, exist_ok=True)
                shutil.copyfile(next(stage.glob("*/bun")), target / "bun")
                (target / "bun").chmod(0o755)
                for path in (ROOT / "bun", target, target / "bun"):
                    os.chown(path, account.pw_uid, account.pw_gid)
        shutil.chown(scope, account.pw_uid, account.pw_gid)
        subprocess.run(
            [
                "chown",
                "-R",
                str(account.pw_uid) + ":" + str(account.pw_gid),
                str(scope / "js_runtime/node" / tools["node"]["version"]),
                str(scope / "recipe" / ("pnpm-" + tools["pnpm"]["version"])),
                str(scope / "bin/vp"),
            ],
            check=True,
        )
        lock = value["vp_lock"]
        if hashlib.sha256(lock.encode()).hexdigest() != tools["vp"]["lock_sha256"]:
            raise ValueError("node.tool_lock_mismatch")
        runtime = scope / "recipe" / ("vp-runtime-" + tools["vp"]["lock_sha256"])
        runtime.mkdir(exist_ok=True)
        parsed = json.loads(lock)
        (runtime / "package.json").write_text(
            json.dumps(
                {
                    "name": parsed["name"],
                    "version": parsed["version"],
                    "private": True,
                    "dependencies": {"vite-plus": tools["vp"]["version"]},
                }
            )
        )
        (runtime / "package-lock.json").write_text(lock)
        for path in (runtime, runtime / "package.json", runtime / "package-lock.json"):
            os.chown(path, account.pw_uid, account.pw_gid)
        node = scope / "js_runtime/node" / tools["node"]["version"]
        environment["PATH"] = str(node / "bin") + ":/usr/bin:/bin"
        user_command(
            [
                node / "bin/node",
                node / "lib/node_modules/npm/bin/npm-cli.js",
                "ci",
                "--ignore-scripts",
                "--no-audit",
                "--no-fund",
                "--prefix",
                runtime,
            ]
        )
        modules = scope / "node_modules"
        modules.mkdir(exist_ok=True)
        os.chown(modules, account.pw_uid, account.pw_gid)
        link = modules / "vite-plus"
        if link.is_symlink():
            link.unlink()
        link.symlink_to(runtime / "node_modules/vite-plus")
        os.chown(link, account.pw_uid, account.pw_gid, follow_symlinks=False)
        current = scope / "recipe/vp-current"
        current.unlink(missing_ok=True)
        current.symlink_to(runtime / "node_modules/vite-plus")
        os.chown(current, account.pw_uid, account.pw_gid, follow_symlinks=False)
        for name in ("node", "npm", "npx"):
            link = scope / "bin" / name
            link.unlink(missing_ok=True)
            link.symlink_to("vp")
            os.chown(link, account.pw_uid, account.pw_gid, follow_symlinks=False)
        (scope / ".orbit-recipe-node.json").write_text(
            json.dumps({"version": tools["node"]["version"]})
        )
        os.chown(scope / ".orbit-recipe-node.json", account.pw_uid, account.pw_gid)
        user_command([scope / "bin/vp", "env", "default", tools["node"]["version"]])
        for name in ("vp", "node", "pnpm", "npm", "npx"):
            path = BIN / name
            path.write_text(
                '#!/bin/sh\nexport VP_HOME="'
                + str(scope)
                + '"\nexec "'
                + str(scope / "bin" / name)
                + '" "$@"\n'
            )
            path.chmod(0o755)
        if "bun" in tools:
            (BIN / "bun").write_text(
                '#!/bin/sh\nexec "' + str(ROOT / "bun/bin/bun") + '" "$@"\n'
            )
            (BIN / "bun").chmod(0o755)
        for name, binary in [
            ("node", node / "bin/node"),
            ("vp-cli", scope / "bin/vp"),
            ("pnpm", scope / "bin/pnpm"),
            *([("bun", ROOT / "bun/bin/bun")] if "bun" in tools else []),
        ]:
            actual = (
                subprocess.check_output(
                    [str(binary), "--version"], env=environment, text=True
                )
                .strip()
                .lstrip("v")
            )
            match = re.search(
                r"(?:^|\s)v?([0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?)\b", actual
            )
            if match is None or match[1] != tools[name]["version"]:
                raise ValueError("node.tool_version_mismatch")
        if (
            json.loads((runtime / "node_modules/vite-plus/package.json").read_text())[
                "version"
            ]
            != tools["vp"]["version"]
        ):
            raise ValueError("node.tool_version_mismatch")
    return {"javascript_ready": True}


if __name__ == "__main__":
    try:
        print(json.dumps(install(json.load(sys.stdin))))
    except (ValueError, OSError, KeyError, subprocess.SubprocessError):
        print(json.dumps({"error_code": "node.tool_javascript_failed"}))
        sys.exit(1)
