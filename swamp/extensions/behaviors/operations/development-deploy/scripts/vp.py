"""One Vite+ CLI action, with registry settings confined to the target checkout."""
import json
import os
import re
import stat
import subprocess
from pathlib import Path

from common import ACCOUNT, INPUT, MODE, ROOT, emit, environment, need


def scope():
    found = [path for path in (Path("/opt/orbit/vite-plus"), Path(ACCOUNT.pw_dir) / ".vite-plus", Path(ACCOUNT.pw_dir) / ".local/share/vite-plus")
             if (path / "bin/vp").is_file()]
    need(bool(found), "tool.vp_scope_absent")
    store = found[0]
    # Legacy agent rule (vp_scope.rs): the scope directory and the binary belong to the account, and the binary runs.
    directory, binary = store.lstat(), (store / "bin/vp").lstat()
    need(stat.S_ISDIR(directory.st_mode) and owned(directory) and owned(binary) and os.access(store / "bin/vp", os.X_OK),
         "tool.vp_scope_conflict")
    return store


def owned(info):
    return info.st_uid == ACCOUNT.pw_uid and info.st_gid == ACCOUNT.pw_gid


VERSION = r"[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?"
RANGE_VERSION = r"v?(?:[0-9]+|[xX*])(?:\.(?:[0-9]+|[xX*])){0,2}(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?"
COMPARATOR = r"(?:[<>=~^]{1,2}\s*)?" + RANGE_VERSION
RANGE_CLAUSE = COMPARATOR + r"(?:\s+(?:-\s+)?" + COMPARATOR + r")*"
ENGINE_RANGE = r"\s*" + RANGE_CLAUSE + r"(?:\s*\|\|\s*" + RANGE_CLAUSE + r")*\s*"


def small(name):
    path = Path(ROOT) / name
    if not path.is_file():
        return None
    need(path.stat().st_size <= 4 * 1024 * 1024, "deployment.vp_requirements_invalid")
    return path.read_text()


def pnpm_fields(raw):
    """Read scalar map fields from pnpm's generated block-map lock format."""
    stack, fields = [], {}
    for line in raw.splitlines():
        match = re.fullmatch(r"( *)(?:'([^']*)'|\"([^\"]*)\"|([^:#][^:]*)):\s*(.*)", line)
        if match is None:
            continue
        indent = len(match[1])
        while stack and stack[-1][0] >= indent:
            stack.pop()
        key = next(value for value in match.groups()[1:4] if value is not None)
        value = match[5].strip()
        path = tuple(entry[1] for entry in stack) + (key,)
        if not value:
            stack.append((indent, key))
        else:
            if value.startswith("'") and value.endswith("'"):
                value = value[1:-1].replace("''", "'")
            elif value.startswith('"'):
                value = json.loads(value)
            fields[path] = value
    return fields


def node_engine(fields, prefix):
    value = fields.get(prefix + ("engines", "node"))
    if value is not None:
        return value
    inline = fields.get(prefix + ("engines",))
    if inline is None:
        return None
    need(inline.startswith("{") and inline.endswith("}"), "deployment.vp_requirements_invalid")
    match = re.search(r"(?:^\{\s*|,\s*)(?:node|'node'|\"node\"):\s*(?:'([^']*)'|\"([^\"]*)\"|([^,}]+))(?=\s*[,}])", inline)
    if match is None:
        need("node" not in inline, "deployment.vp_requirements_invalid")
        return None
    return next(value.strip() for value in match.groups() if value is not None)


def project(store):
    # Manifests, scripts, resolved URLs and npmrc never leave this target process.
    package = json.loads(small("package.json") or "{}")
    dependencies = {**package.get("dependencies", {}), **package.get("devDependencies", {})}
    names = [name for name, value in dependencies.items() if name == "vite-plus" or isinstance(value, str) and value.startswith("npm:vite-plus@")]
    if not names:
        return None, []
    versions, constraints, locked_manager = set(), [], None
    npm = small("package-lock.json")
    pnpm = small("pnpm-lock.yaml")
    if pnpm is not None:
        family = "pnpm"
        fields = pnpm_fields(pnpm)
        locked_manager = fields.get(("packageManager",))
        for name in names:
            for section in ("dependencies", "devDependencies"):
                prefix = ("importers", ".", section, name)
                value = fields.get(prefix + ("version",)) or fields.get((section, name, "version")) or fields.get((section, name))
                if value is None:
                    continue
                if value.startswith("link:"):
                    directory = (Path(ROOT) / value[5:]).resolve()
                    need(directory.is_relative_to(Path(ROOT)), "deployment.vp_requirements_invalid")
                    workspace = json.loads((directory / "package.json").read_text())
                    need(workspace.get("name") == "vite-plus", "deployment.vp_requirements_invalid")
                    value = workspace.get("version")
                    engine = workspace.get("engines", {}).get("node")
                else:
                    value = re.sub(r"\(.*\)$", "", value).removeprefix("npm:").removeprefix("vite-plus@").removeprefix("workspace:")
                    engine = node_engine(fields, ("packages", "vite-plus@" + value))
                versions.add(value)
                if engine:
                    constraints.append(["node", engine])
    else:
        need(npm is not None, "deployment.vp_requirements_invalid")
        family = "npm"
        lockfile = json.loads(npm)
        locked_manager = "npm@" + lockfile["npmVersion"] if isinstance(lockfile.get("npmVersion"), str) else lockfile.get("packageManager")
        for name in names:
            entry = lockfile.get("packages", {}).get("node_modules/" + name) or lockfile.get("dependencies", {}).get(name, {})
            if entry.get("link") is True:
                entry = lockfile.get("packages", {}).get(entry.get("resolved"), {})
            versions.add(entry.get("version"))
            if entry.get("engines", {}).get("node"):
                constraints.append(["node", entry["engines"]["node"]])
    need(len(versions) == 1, "deployment.vp_requirements_invalid")
    vp_version = versions.pop()
    need(isinstance(vp_version, str) and re.fullmatch(VERSION, vp_version), "deployment.vp_requirements_invalid")
    node = (small(".node-version") or small(".nvmrc") or package.get("engines", {}).get("node", "")).strip().removeprefix("v")
    need(re.fullmatch(VERSION, node), "deployment.vp_requirements_invalid")
    manager = package.get("packageManager") or locked_manager or family + "@" + package.get("engines", {}).get(family, "")
    match = re.fullmatch(r"(npm|pnpm)@(" + VERSION + r")(?:\+(sha(?:224|256|384|512)\.[a-fA-F0-9]+))?", manager)
    need(match is not None and match[1] == family, "deployment.vp_requirements_invalid")
    for field in ("node", family):
        if package.get("engines", {}).get(field):
            constraints.append([field, package["engines"][field]])
    for _, constraint in constraints:
        need(isinstance(constraint, str) and len(constraint) <= 256 and re.fullmatch(ENGINE_RANGE, constraint), "deployment.vp_requirements_invalid")
    return {"store": str(store), "vp_version": vp_version, "node_version": node, "package_manager": family,
            "package_manager_version": match[2], "package_manager_hash": match[3]}, constraints


def action():
    need(ACCOUNT.pw_uid > 0 and os.geteuid() == ACCOUNT.pw_uid)
    store = scope()
    env = environment()
    env["VP_HOME"] = str(store)
    if (Path(ROOT) / ".npmrc").is_file():
        env["NPM_CONFIG_USERCONFIG"] = str(Path(ROOT) / ".npmrc")
    if MODE == "vp-project":
        pins, constraints = project(store)
        version = subprocess.run([str(store / "bin/vp"), "--version"], cwd=ROOT, env=env, capture_output=True)
        need(version.returncode == 0 and len(version.stdout) <= 1024, "deployment.vp_store_failed")
        return {"runtime": pins, "constraints": constraints, "version": version.stdout.decode().strip()}
    pins = INPUT.get("runtime")
    if pins is None:
        return {}
    need(pins["store"] == str(store), "tool.vp_scope_conflict")
    if MODE == "vp-install":
        manager = pins["package_manager"] + "@" + pins["package_manager_version"]
        if pins["package_manager_hash"]:
            manager += "+" + pins["package_manager_hash"]
        result = subprocess.run([str(store / "bin/vp"), "env", "install", "node@" + pins["node_version"], manager], cwd=ROOT, env=env,
                                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        need(result.returncode == 0, "deployment.vp_store_failed")
        return {}
    need(MODE == "vp-verify")
    node = store / "js_runtime/node" / pins["node_version"] / "bin/node"
    manager = store / "package_manager" / pins["package_manager"] / pins["package_manager_version"] / pins["package_manager"]
    need(node.is_file() and os.access(node, os.X_OK), "deployment.vp_store_failed")
    manifest = manager / "package.json"
    need(manifest.is_file(), "deployment.vp_store_failed")
    need(json.loads(manifest.read_text()).get("version") == pins["package_manager_version"], "deployment.vp_store_failed")
    return {}


emit(action)
