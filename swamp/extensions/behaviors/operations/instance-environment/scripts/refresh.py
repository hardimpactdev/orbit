"""Refresh a production Instance's runtime after its .env sync, as root.

argv: the public runtime plan as JSON. An existing config cache is rebuilt in a private path as
the application account, booted once and then swapped in; an uncached release stays uncached.
The account's PHP-FPM service reloads to reset OPcache, and each running Process with
restart_on_deploy restarts. Prints one public JSON object; exits 1 with an error_code.
"""

import json
import os
import pwd
import re
import shutil
import stat
import subprocess
import sys
import tempfile
from pathlib import Path

SYSTEMCTL = "/usr/bin/systemctl"
DOCKER = ["/usr/bin/docker", "--host=unix:///var/run/docker.sock"]


class Refused(Exception):
    pass


def check(ok, code):
    if not ok:
        raise Refused(code)


def owned_directory(path, uid, code):
    check(path.is_dir() and path.resolve(strict=True) == path and path.stat().st_uid == uid, code)


def owned_file(path, uid, code):
    check(os.path.lexists(path) and stat.S_ISREG(path.lstat().st_mode) and path.lstat().st_uid == uid, code)


def as_account(account, argv, environment, cwd):
    def drop():
        os.initgroups(account.pw_name, account.pw_gid)
        os.setgid(account.pw_gid)
        os.setuid(account.pw_uid)

    return subprocess.run(argv, cwd=cwd, env=environment, preexec_fn=drop, stdin=subprocess.DEVNULL,
                          stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=120, check=False).returncode == 0


def config_cache(config):
    """Returns config_cached, config_uncached or no_current_release."""
    code = "env.config_cache_refresh_failed"
    user, version = config["user"], config["version"]
    check(re.fullmatch("[a-z_][a-z0-9_-]{0,31}", user) is not None and user != "root" and re.fullmatch(r"[0-9]+\.[0-9]+", version) is not None, code)
    account = pwd.getpwnam(user)
    check(account.pw_uid != 0, code)
    home = Path(config["home"])
    check(home == Path(account.pw_dir), code)
    owned_directory(home, account.pw_uid, code)
    current, application = home / "current", Path(config["application"])
    check(application == current or application.is_relative_to(current), code)
    if not os.path.lexists(current):
        return "no_current_release"
    check(current.is_symlink(), code)
    release, releases = current.resolve(strict=True), home / "releases"
    owned_directory(releases, account.pw_uid, code)
    check(release.parent == releases and re.fullmatch("[A-Za-z0-9][A-Za-z0-9._-]{0,127}", release.name) is not None, code)
    app = release / application.relative_to(current)
    owned_directory(app, account.pw_uid, code)
    cache = app / "bootstrap" / "cache" / "config.php"
    if not os.path.lexists(cache):
        return "config_uncached"
    owned_directory(cache.parent, account.pw_uid, code)
    owned_file(cache, account.pw_uid, code)
    temporary = Path(tempfile.mkdtemp(prefix=".orbit-config-", dir=cache.parent))
    try:
        os.chown(temporary, account.pw_uid, account.pw_gid)
        temporary.chmod(0o700)
        candidate = temporary / "config.php"
        environment = {"PATH": "/usr/bin:/bin", "HOME": str(home), "LANG": "C.UTF-8", "APP_CONFIG_CACHE": str(candidate)}
        php = "/usr/bin/php" + version
        check(as_account(account, [php, "artisan", "config:cache"], environment, app), code)
        owned_file(candidate, account.pw_uid, code)
        check(as_account(account, [php, "artisan", "--version", "--no-interaction"], environment, app), code)
        candidate.chmod(stat.S_IMODE(cache.lstat().st_mode))
        os.replace(candidate, cache)
        fd = os.open(cache.parent, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(fd)
        finally:
            os.close(fd)
        return "config_cached"
    finally:
        shutil.rmtree(temporary)


def run(argv):
    return subprocess.run(argv, capture_output=True, text=True, timeout=60, check=False)


def restart(process):
    if process["runtime"] == "systemd":
        check(run([SYSTEMCTL, "restart", process["name"]]).returncode == 0, "env.runtime_refresh_failed")
        check(run([SYSTEMCTL, "is-active", "--quiet", process["name"]]).returncode == 0, "env.runtime_refresh_failed")
        return
    check(run([*DOCKER, "container", "restart", process["name"]]).returncode == 0, "env.runtime_refresh_failed")
    state = run([*DOCKER, "container", "inspect", "--format", "{{.State.Status}}", process["name"]])
    check(state.returncode == 0 and state.stdout.strip() == "running", "env.runtime_refresh_failed")


def refresh(plan):
    status = "skipped_no_php_version"
    if plan["config"] is not None:
        try:
            outcome = config_cache(plan["config"])
        except (OSError, KeyError, subprocess.SubprocessError):
            raise Refused("env.config_cache_refresh_failed") from None
        if outcome == "no_current_release":
            return {"runtime_refresh": "skipped_no_current_release", "restarted_process_ids": []}
        service = plan["fpm_service"]
        check(run([SYSTEMCTL, "reload", service]).returncode == 0 and run([SYSTEMCTL, "is-active", "--quiet", service]).returncode == 0,
              "env.php_runtime_refresh_failed")
        status = "config_cache_and_opcache_refreshed" if outcome == "config_cached" else "opcache_refreshed_config_uncached"
    restarted = []
    for process in plan["processes"]:
        restart(process)
        restarted.append(process["id"])
    return {"runtime_refresh": status, "restarted_process_ids": restarted}


def main(argv):
    try:
        return refresh(json.loads(argv[0])), 0
    except Refused as refusal:
        return {"error_code": str(refusal)}, 1
    except (OSError, ValueError, KeyError, TypeError, IndexError, subprocess.SubprocessError):
        return {"error_code": "env.runtime_refresh_failed"}, 1


if __name__ == "__main__":
    result, code = main(sys.argv[1:])
    print(json.dumps(result, separators=(",", ":")))
    sys.exit(code)
