import json

from support import INPUT, finish, query


def observe():
    before = INPUT["before"]
    cask = INPUT["manager"] == "brew-cask"
    env = {k: "1" for k in ("HOMEBREW_NO_AUTO_UPDATE", "HOMEBREW_NO_ANALYTICS", "HOMEBREW_NO_ENV_HINTS", "HOMEBREW_NO_INSTALL_CLEANUP")}
    if INPUT["platform"] == "macos":
        env["HOMEBREW_FORCE_API_AUTO_UPDATE"] = "1"
    result = json.loads(query([before["prefix"] + "/bin/brew", "info", "--json=v2", "--cask" if cask else "--formula", ("homebrew/cask/" if cask else "homebrew/core/") + INPUT["package"]], env).stdout)
    rows = result["casks" if cask else "formulae"]
    if len(rows) != 1 or result["formulae" if cask else "casks"] != []:
        raise ValueError("Ambiguous Homebrew candidate")
    return {**before, "homebrew": rows[0]}
finish(observe)
