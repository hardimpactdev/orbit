"""Install the admitted Homebrew revision; package changes stay in separate actions."""
import json
import os
import pwd
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path


def install(value):
    account = pwd.getpwnam(value["user"])
    prefix = Path("/home/linuxbrew/.linuxbrew")
    if prefix.exists() or prefix.is_symlink():
        raise ValueError("tool.scope_conflict")
    parent = prefix.parent
    if parent.exists() and (parent.is_symlink() or parent.stat().st_uid != 0):
        raise ValueError("tool.scope_conflict")
    parent.mkdir(exist_ok=True, mode=0o755)
    destination = prefix
    prefix = Path(tempfile.mkdtemp(prefix=".orbit-brew-", dir=parent))
    os.chmod(prefix, 0o755)
    os.chown(prefix, account.pw_uid, account.pw_gid)
    try:
        env = {"HOME": account.pw_dir, "USER": account.pw_name, "PATH": "/usr/bin:/bin", "LC_ALL": "C", "HOMEBREW_NO_AUTO_UPDATE": "1", "HOMEBREW_NO_ANALYTICS": "1"}
        def run(args):
            return subprocess.run(args, check=True, env=env, user=account.pw_uid, group=account.pw_gid, cwd=account.pw_dir, capture_output=True, text=True)
        repository = prefix / "Homebrew"
        run(["/usr/bin/git", "clone", "--filter=blob:none", "--no-checkout", "https://github.com/Homebrew/brew", str(repository)])
        run(["/usr/bin/git", "-C", str(repository), "checkout", "--detach", value["revision"]])
        for name in ["bin", "Cellar", "Caskroom", "Frameworks", "etc", "include", "lib", "opt", "sbin", "share", "var", "var/homebrew", "var/homebrew/locks"]:
            path = prefix / name
            path.mkdir(exist_ok=True, mode=0o775)
            os.chown(path, account.pw_uid, account.pw_gid)
        (prefix / "bin/brew").symlink_to("../Homebrew/bin/brew")
        os.lchown(prefix / "bin/brew", account.pw_uid, account.pw_gid)
        if run([str(prefix / "bin/brew"), "--version"]).stdout.splitlines()[0] != "Homebrew " + value["version"]:
            raise ValueError("tool.manager_version_mismatch")
        os.rename(prefix, destination)
    finally:
        if prefix.exists():
            shutil.rmtree(prefix)
    return {"homebrew_ready": True}


if __name__ == "__main__":
    print(json.dumps(install(json.load(sys.stdin))))
