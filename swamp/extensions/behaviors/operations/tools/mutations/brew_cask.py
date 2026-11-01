from support import INPUT, query

prefix = INPUT["before"]["prefix"]
env = {k: "1" for k in ("HOMEBREW_NO_AUTO_UPDATE", "HOMEBREW_NO_ANALYTICS", "HOMEBREW_NO_ENV_HINTS",
                       "HOMEBREW_NO_INSTALLED_DEPENDENTS_CHECK", "HOMEBREW_NO_INSTALL_CLEANUP", "HOMEBREW_NO_AUTOREMOVE")}
query([prefix + "/bin/brew", "uninstall", "--cask", INPUT["package"]], env)
print('{"ready":true}')
