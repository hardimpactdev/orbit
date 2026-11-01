import json

from support import ACCOUNT, INPUT, Path, finish, query


def observe():
    scope = Path("/opt/orbit/composer")
    if not scope.is_dir():
        return {"scope": "absent"}
    manifest = scope / "composer.json"
    if scope.is_symlink() or scope.stat().st_uid != ACCOUNT.pw_uid or manifest.is_symlink():
        return {"scope": "conflict"}
    if not manifest.exists():
        return {"scope": "absent"}
    if not manifest.is_file() or manifest.stat().st_uid != ACCOUNT.pw_uid:
        return {"scope": "conflict"}
    roots = json.loads(manifest.read_text()).get("require", {})
    if roots == []:
        roots = {}
    if not isinstance(roots, dict):
        raise TypeError("invalid root manifest")
    env = {"COMPOSER_HOME": str(scope)}
    manager = query(["/usr/bin/composer", "--version", "--no-ansi"], env).stdout.splitlines()[0]
    if INPUT.get("manager_only"):
        return {"manager_version": manager, "versions": [], "complete": True}
    data = json.loads(query(["/usr/bin/composer", "global", "show", "--format=json", "--no-ansi"], env).stdout)
    entries = [] if data == [] else data["installed"]
    return {"manager_version": manager, "roots": list(roots), "complete": True,
            "versions": [p.get("version") for p in entries if p["name"] == INPUT["package"]]}

finish(observe)
