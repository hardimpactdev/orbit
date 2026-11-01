import json

from support import ACCOUNT, INPUT, Path, finish, query


def observe():
    paths = ([Path("/opt/orbit/vite-plus")] if INPUT["platform"] == "linux" else []) + [
        Path(ACCOUNT.pw_dir) / ".vite-plus", Path(ACCOUNT.pw_dir) / ".local/share/vite-plus"]
    found = []
    for path in paths:
        if not path.exists():
            continue
        if path.stat().st_uid != ACCOUNT.pw_uid:
            return {"scope": "conflict"}
        if (path / "bin/vp").is_file():
            found.append(path)
            if INPUT["platform"] == "linux":
                break
    if len(found) != 1:
        return {"scope": "absent" if not found else "conflict"}
    binary, env = str(found[0] / "bin/vp"), {"VP_HOME": str(found[0])}
    manager = query([binary, "--version"], env).stdout.splitlines()[0]
    entries = json.loads(query([binary, "list", "-g", "--json"], env).stdout)
    names = [p["name"] for p in entries]
    complete = all(isinstance(n, str) and 0 < len(n) <= 255 for n in names) and len(set(names)) == len(names)
    return {"manager_version": manager, "complete": complete,
            "versions": [p.get("version") for p in entries if p["name"] == INPUT["package"]]}

finish(observe)
