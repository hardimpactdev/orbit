"""The installed formulae that still depend on the selected formula; any one of them refuses the removal."""
import json

from support import INPUT, query

env = {k: "1" for k in ("HOMEBREW_NO_AUTO_UPDATE", "HOMEBREW_NO_ANALYTICS", "HOMEBREW_NO_ENV_HINTS")}
users = query([INPUT["before"]["prefix"] + "/bin/brew", "uses", "--installed", INPUT["package"]], env).stdout.split()
print(json.dumps({"packages": [INPUT["package"], *users]}))
