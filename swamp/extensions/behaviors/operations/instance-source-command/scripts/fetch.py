"""Execute one Git fetch; the short-lived credential enters only through stdin."""
import base64
import json
import os
import sys

material = json.load(sys.stdin) or {}
checkout, repository = sys.argv[1:3]
if isinstance(material, str):
    material = json.loads(material)
token = material.get("github_token") or material.get("token")
environment = {**os.environ, "GIT_ALLOW_PROTOCOL": "https", "GIT_TERMINAL_PROMPT": "0", "GIT_CONFIG_NOSYSTEM": "1", "GIT_CONFIG_GLOBAL": "/dev/null"}
if token:
    entries = [("http.https://github.com/.extraheader", "Authorization: Basic " + base64.b64encode(("x-access-token:" + token).encode()).decode()),
               ("url.https://github.com/.insteadOf", "git@github.com:"),
               ("url.https://github.com/.insteadOf", "ssh://git@github.com/")]
    environment["GIT_CONFIG_COUNT"] = str(len(entries))
    for index, (key, content) in enumerate(entries):
        environment["GIT_CONFIG_KEY_" + str(index)] = key
        environment["GIT_CONFIG_VALUE_" + str(index)] = content
settings = ["core.hooksPath=/dev/null", "core.fsmonitor=false", "credential.helper=", "protocol.allow=never", "protocol.ext.allow=never", "protocol.https.allow=always", "core.sshCommand=false", "core.alternateRefsCommand=", "http.followRedirects=false"]
args = ["git", *[part for setting in settings for part in ("-c", setting)], "-C", checkout,
        "fetch", "--quiet", "--no-tags", "--prune", "--", repository, "+refs/heads/*:refs/remotes/origin/*"]
# Git errors may include a credential from a configured remote helper. Keep them on the host.
null = os.open(os.devnull, os.O_WRONLY)
os.dup2(null, 2)
os.execve("/usr/bin/git", args, environment)
