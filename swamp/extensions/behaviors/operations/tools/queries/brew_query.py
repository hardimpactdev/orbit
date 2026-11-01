"""Read the selected package from Homebrew's own JSON metadata."""
import json

from support import ACCOUNT, INPUT, Path, query


def brew_facts(cask):
    prefixes = [Path("/home/linuxbrew/.linuxbrew")] if INPUT["platform"] == "linux" else [
        Path("/opt/homebrew"), Path("/usr/local"), Path(ACCOUNT.pw_dir) / "homebrew", Path(ACCOUNT.pw_dir) / ".homebrew"]
    found = [p for p in prefixes if (p / "bin/brew").is_file()]
    if len(found) != 1:
        return {"scope": "absent" if not found else "conflict"}
    prefix = found[0]
    if prefix.stat().st_uid != ACCOUNT.pw_uid:
        return {"scope": "conflict"}
    env = {k: "1" for k in ("HOMEBREW_NO_AUTO_UPDATE", "HOMEBREW_NO_ANALYTICS", "HOMEBREW_NO_ENV_HINTS",
                           "HOMEBREW_NO_INSTALLED_DEPENDENTS_CHECK", "HOMEBREW_NO_INSTALL_CLEANUP")}
    if not cask and INPUT["platform"] == "macos":
        env["HOMEBREW_FORCE_API_AUTO_UPDATE"] = "1"
    binary = str(prefix / "bin/brew")
    manager = query([binary, "--version"], env).stdout.splitlines()[0]
    if INPUT.get("manager_only"):
        return {"manager_version": manager, "versions": [], "complete": True}
    args = ["info", "--json=v2", "--cask" if cask else "--formula", "--installed"]
    rows = json.loads(query([binary, *args], env).stdout)["casks" if cask else "formulae"]
    names = query([binary, "list", "--cask" if cask else "--formula", "-1"], env).stdout.splitlines()
    if any(not n or any(ord(c) < 32 or ord(c) == 127 for c in n) for n in names) or len(set(names)) != len(names):
        raise ValueError("invalid installed name list")
    selected = [r for r in rows if r.get("token" if cask else "name") == INPUT["package"]
                and (not cask or r.get("tap") == "homebrew/cask")]
    if len(selected) > 1:
        raise ValueError("ambiguous package")
    record = selected[0] if selected else None
    complete = bool(record) == (INPUT["package"] in names)
    versions = ([record["installed"]] if record.get("installed") else []) if cask and record else [
        r.get("version") for r in record.get("installed", [])] if record else []
    major = int(query(["/usr/bin/sw_vers", "-productVersion"]).stdout.split(".")[0]) if INPUT["platform"] == "macos" else 0
    return {"manager_version": manager, "versions": versions, "complete": complete, "homebrew": record,
            "os_major": major, "prefix": str(prefix), "home": ACCOUNT.pw_dir,
            "token_count": sum(r.get("token") == INPUT["package"] for r in rows) if cask else 0}
