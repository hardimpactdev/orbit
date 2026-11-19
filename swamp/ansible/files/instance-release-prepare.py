#!/usr/bin/env python3
"""Create or verify one AppProd preparer and its private network and persistent volume."""
import argparse
import json
import re
import runpy
import subprocess
import sys
from pathlib import Path

PROJECT = "orbit-releases"
STOCK = "ubuntu-26.04-instance-stock"
OWNER = "user.orbit.release.target"
ROLE = "user.orbit.release.role"
ROOT = Path("/var/lib/orbit/production")
VITE_ROOTS = {Path("/opt/orbit-production-runtime/vite-plus"), Path("/opt/orbit-runtime/vite-plus")}


def run(*args, stdin=None, timeout=300):
    return subprocess.run(args, input=stdin, capture_output=True, text=True, check=True, timeout=timeout).stdout.strip()


def inc(*args, project=PROJECT, **kwargs):
    return run("incus", "--force-local", "--project", project, *args, **kwargs)


def query(path, project=PROJECT):
    return json.loads(run("incus", "--force-local", "query", path + "?project=" + project))


def ensure_device(name, key, expected):
    devices = query("/1.0/instances/" + name)["expanded_devices"]
    actual = devices.get(key)
    if actual is None:
        inc("config", "device", "add", name, key, expected["type"],
            *(k + "=" + v for k, v in expected.items() if k != "type"))
    elif any(actual.get(k) != v for k, v in expected.items()):
        raise ValueError("Refusing an unexpected " + key + " device on " + name)


def tagged(config, target):
    if config.get(OWNER) != target:
        raise ValueError("Release target ownership differs")


def has_name(items, name):
    return any(item["name"] == name for item in items)


def guest(name, *args, **kwargs):
    return inc("exec", name, "--", *args, **kwargs)


def vite_home(runtime):
    link = runtime / "vite-plus-home"
    launcher = runtime / "bin/vp"
    if not (link.is_symlink() or launcher.is_symlink() or launcher.exists()):
        return None
    home = link.readlink()
    if (home.parent not in VITE_ROOTS or not re.fullmatch(r"[0-9a-f]{12}", home.name)
            or home.resolve() != home or not (home / ".complete").is_file()
            or launcher.resolve(strict=True) != home / "bin/vp"):
        raise ValueError("Refusing an incomplete or unexpected pinned Vite+ home")
    return home


def bind_vite_home(machine, prep, runtime):
    home = vite_home(runtime)
    previous = prep["devices"].get("vite-plus")
    if previous and previous.get("source") != str(home):
        source = Path(previous["source"])
        if (source.parent not in VITE_ROOTS or not re.fullmatch(r"[0-9a-f]{12}", source.name)
                or previous != {"type":"disk","source":str(source),"path":str(source),"readonly":"true"}):
            raise ValueError("Refusing an unexpected preparer Vite+ mount")
        inc("config", "device", "remove", machine, "vite-plus")
    if home:
        ensure_device(machine, "vite-plus", {"type":"disk","source":str(home),"path":str(home),"readonly":"true"})


def refresh_image(machine, target, image, info):
    tagged(info["config"], target)
    if machine != "prepare-" + target or info["config"].get(ROLE) != "prepare" or info["profiles"]:
        raise ValueError("Only the permanent preparation container can refresh its image")
    previous = info["config"].get("volatile.base_image", "")
    if not re.fullmatch(r"[0-9a-f]{64}", previous):
        raise ValueError("Cannot establish the current preparation image")
    if previous == image:
        return info
    if info["config"].get("boot.autostart") != "false" or query("/1.0/instances/" + machine + "/snapshots"):
        raise ValueError("Preparation still has an active build snapshot or autostart")
    # Clear this before replacing the root disk so a crash cannot skip stock guest setup on retry.
    if "user.orbit.release.prepared" in info["config"]:
        inc("config", "unset", machine, "user.orbit.release.prepared")
    inc("rebuild", image, machine, "--force", timeout=900)
    refreshed = query("/1.0/instances/" + machine)
    if refreshed["config"].get("volatile.base_image") != image:
        raise ValueError("Preparation did not adopt the required image")
    return refreshed


def install_browser_files(machine, config):
    """The runtime's headless Chrome finds its libraries and fonts as in a development container (InstanceImage::browser).
    Every release clones this preparer, so its linker cache already lists the mounted runtime's libraries. A config that
    an earlier converge wrote has no browser files, so the preparer installs none until the converge publishes them."""
    files = config.get("browser_files", [])
    if not files:
        return
    for item in files:
        if not re.fullmatch(r"/etc/(?:ld[.]so[.]conf[.]d/zz-orbit-browser[.]conf|fonts/fonts[.]conf)", item["path"]):
            raise ValueError("Unexpected browser file")
        guest(machine, "sh", "-ec", 'install -d -m 0755 "${1%/*}" && cat >"$1" && chmod 0644 "$1"', "sh", item["path"], stdin=item["content"])
    guest(machine, "ldconfig")


def prepare(instance, php):
    target = f"instance-{instance}"
    machine = "prepare-" + target
    directory = ROOT / str(instance)
    directory.mkdir(mode=0o755, exist_ok=True)
    private = directory / "private"
    private.mkdir(mode=0o700, exist_ok=True)
    config = json.loads(Path("/etc/orbit/production-release.json").read_text())
    runtime = Path(config["runtime"])
    if not runtime.is_absolute() or runtime.resolve() != runtime or not (runtime / "php" / php / "bin/frankenphp").is_file():
        raise ValueError("The pinned runtime lacks the selected PHP version")
    pool = config["pool"]
    if pool != PROJECT:
        raise ValueError("Unexpected AppProd storage pool")
    driver = query("/1.0/storage-pools/" + pool)["driver"]
    if driver not in ("zfs", "btrfs"):
        raise ValueError(f"release.pool_driver_unsupported: the release pool uses the {driver} driver; move it to zfs, or btrfs on a nested Node")
    network = config["network"]
    if network != "orbitinst0" or not re.fullmatch(r"http://(?:[0-9]{1,3}[.]){3}[0-9]{1,3}:3128", config["proxy"]):
        raise ValueError("Invalid production release network or proxy")
    volume = target + "-shared"
    if not has_name(json.loads(inc("storage", "volume", "list", pool, "--format", "json")), volume):
        inc("storage", "volume", "create", pool, volume, OWNER + "=" + target)
    record = query(f"/1.0/storage-pools/{pool}/volumes/custom/{volume}")
    tagged(record["config"], target)
    if record["content_type"] != "filesystem":
        raise ValueError("Unexpected production shared volume")
    image = query("/1.0/images/" + query("/1.0/images/aliases/" + STOCK)["target"])
    fingerprint = image["fingerprint"]
    if (not re.fullmatch(r"[0-9a-f]{64}", fingerprint) or image["type"] != "container" or
            image["properties"].get("user.orbit.stock-build") != STOCK):
        raise ValueError("Unrecognized production stock image")
    if not has_name(json.loads(inc("list", machine, "--format", "json")), machine):
        inc("init", fingerprint, machine, "--no-profiles", "-s", pool, "-n", network, "-c", OWNER + "=" + target,
            "-c", ROLE + "=prepare", "-c", "boot.autostart=false", "-c", "security.privileged=false", "-c", "security.nesting=false",
            "-c", "environment.HTTPS_PROXY=" + config["proxy"], "-c", "environment.HTTP_PROXY=" + config["proxy"],
            "-c", "environment.https_proxy=" + config["proxy"], "-c", "environment.http_proxy=" + config["proxy"])
    prep = query("/1.0/instances/" + machine)
    tagged(prep["config"], target)
    if prep["config"].get(ROLE) != "prepare" or prep["config"].get("boot.autostart") != "false" or prep["profiles"]:
        raise ValueError("Unexpected preparation container")
    sql = runpy.run_path("/usr/local/libexec/orbit-instance-release-sql")["approved_devices"](prep)
    if set(prep["expanded_devices"]) - {"root", "eth0", "runtime", "vite-plus", "shared", *sql}:
        raise ValueError("Preparation container has unrecognized devices")
    ensure_device(machine, "root", {"type":"disk","pool":pool,"path":"/"})
    ensure_device(machine, "eth0", {"type":"nic","network":network})
    nic = prep["devices"]["eth0"]
    if any(nic.get(key) != "true" for key in ["security.mac_filtering","security.ipv4_filtering","security.port_isolation"]):
        inc("config", "device", "set", machine, "eth0", "security.mac_filtering=true", "security.ipv4_filtering=true", "security.port_isolation=true")
    ensure_device(machine, "shared", {"type":"disk","pool":pool,"source":volume,"path":"/srv/shared"})
    previous = prep["devices"].get("runtime")
    runtime_device = {"type":"disk","source":str(runtime),"path":"/opt/orbit-runtime/current","readonly":"true"}
    if previous and str(previous.get("source","")) != str(runtime):
        # Retarget only this immutable-preparer device. Existing clones retain their selected runtime.
        old = Path(previous["source"])
        if old.parent != runtime.parent or old.resolve() != old or previous.get("readonly") != "true" or previous.get("path") != runtime_device["path"]:
            raise ValueError("Refusing an unrecognized preparer runtime")
        inc("config","device","set",machine,"runtime","source="+str(runtime))
    ensure_device(machine, "runtime", runtime_device)
    bind_vite_home(machine, prep, runtime)
    prep = refresh_image(machine, target, fingerprint, prep)
    if prep["status"] == "Stopped":
        inc("start", machine)
    if prep["config"].get("user.orbit.release.prepared") != "true":
        guest(machine, "sh", "-ec", GUEST, timeout=1200)
        inc("config", "set", machine, "user.orbit.release.prepared=true")
    # A credentialed incremental fetch never reads application-owned Git objects or configuration.
    guest(machine, "sh", "-ec", 'id orbit-fetch >/dev/null 2>&1 || useradd --system --user-group '
          '--home-dir /var/lib/orbit-release-git --shell /usr/sbin/nologin orbit-fetch; '
          'install -d -m 0755 -o orbit-fetch -g orbit-fetch /var/lib/orbit-release-git')
    # Apply the distro's pending updates only in the off-traffic preparer. Retained releases keep their packages.
    guest(machine, "env", "DEBIAN_FRONTEND=noninteractive", "sh", "-ec",
          'apt-get -qq -o Acquire::Retries=2 update && apt-get -qq -y -o Dpkg::Options::=--force-confold upgrade', timeout=1200)
    install_browser_files(machine, config)
    # Default installation never enables the unit. Candidate readiness and the first verified switch own its service lifecycle.
    guest(machine, "sh", "-ec", 'cat >/etc/systemd/system/orbit-frankenphp.service && systemctl daemon-reload', stdin=UNIT.replace("@PHP@", php))
    guest(machine, "sh", "-ec", 'test -x /opt/orbit-runtime/current/php/"$1"/bin/frankenphp && install -d -o orbit -g orbit -m 0750 /srv/workspace /srv/shared', "sh", php)
    # Both binaries read /etc/orbit/php/php.ini: the build's php.ini-production with the security settings, at every deploy.
    guest(machine, "sh", "-ec", PHP_INI, "sh", php)
    upstream = directory / "upstream.caddy"
    if not upstream.exists():
        with upstream.open("x") as stream:
            stream.write("respond 503\n")
        upstream.chmod(0o644)


GUEST = """set -eu
export DEBIAN_FRONTEND=noninteractive
if command -v cloud-init >/dev/null; then timeout 180 cloud-init status --wait >/dev/null || test "$?" = 2; fi
if getent passwd ubuntu >/dev/null; then test "$(getent passwd ubuntu | cut -d: -f6)" = /home/ubuntu; userdel -r ubuntu; fi
rm -f /etc/sudoers.d/90-cloud-init-users
touch /etc/cloud/cloud-init.disabled
for unit in ssh.socket ssh.service apt-daily.timer apt-daily-upgrade.timer; do
  if systemctl cat "$unit" >/dev/null 2>&1; then systemctl disable --now "$unit"; fi
done
if ! id orbit >/dev/null 2>&1; then useradd -m -U -s /bin/bash orbit; fi
apt-get -qq update
apt-get -qq install --no-install-recommends -y ca-certificates curl git openssh-client python3 rsync unzip
install -d -m0755 /etc/orbit
"""
# PHP's production settings less upload progress and the PHP header; later lines win (docs/reference/php-runtime.md#production-safe-settings).
PHP_INI = """install -d -m0755 /etc/orbit/php
{ cat /opt/orbit-runtime/current/php/"$1"/php.ini-production
  printf '%s\\n' '' '; Orbit' 'zend.exception_ignore_args = 1' 'session.upload_progress.enabled = 0' 'display_errors = 0' \\
    'display_startup_errors = 0' 'expose_php = 0'; } >/etc/orbit/php/php.ini.new
chmod 0644 /etc/orbit/php/php.ini.new
mv /etc/orbit/php/php.ini.new /etc/orbit/php/php.ini
"""
UNIT = """[Unit]
Description=Orbit production Instance web server
After=network-online.target
[Service]
User=orbit
Group=orbit
Environment=HOME=/home/orbit
Environment=XDG_CONFIG_HOME=/home/orbit/.config
Environment=XDG_DATA_HOME=/home/orbit/.local/share
WorkingDirectory=/srv/workspace/current
ExecStart=/opt/orbit-runtime/current/php/@PHP@/bin/frankenphp run --config /etc/orbit/release-web.caddy --adapter caddyfile
Restart=on-failure
[Install]
WantedBy=multi-user.target
"""

if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("instance", type=int)
    parser.add_argument("php")
    args = parser.parse_args()
    if not 0 < args.instance <= 2**48-1 or not re.fullmatch(r"[0-9][.][0-9]{1,2}", args.php):
        sys.exit(64)
    prepare(args.instance, args.php)
