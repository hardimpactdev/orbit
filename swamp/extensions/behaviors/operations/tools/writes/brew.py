from support import INPUT, query

cask = INPUT["manager"] == "brew-cask"
env = {k: "1" for k in ("HOMEBREW_NO_AUTO_UPDATE", "HOMEBREW_NO_ANALYTICS", "HOMEBREW_NO_ENV_HINTS", "HOMEBREW_NO_INSTALL_CLEANUP", "HOMEBREW_NO_AUTOREMOVE", "HOMEBREW_NO_INSTALLED_DEPENDENTS_CHECK")}
if INPUT["platform"] == "macos":
    env["HOMEBREW_FORCE_API_AUTO_UPDATE"] = "1"
query([INPUT["before"]["prefix"] + "/bin/brew", "install" if INPUT["kind"] == "tool.install" else "upgrade", "--cask" if cask else "--formula", *([] if cask else ["--force-bottle"]), ("homebrew/cask/" if cask else "homebrew/core/") + INPUT["package"]], env)
print('{"ready":true}')
