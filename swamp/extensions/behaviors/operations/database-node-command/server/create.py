"""database:server:create on the Database server's Node, one chart phase per call.

Arguments: the public intent as JSON and the phase. A phase that needs a secret reads it on
stdin: the admin password, or for `acl` the ACL file. Prints one public JSON object; exit 1
carries an error_code. Secrets reach Docker through a 0600 env file, never argv.
"""

import contextlib
import json
import subprocess
import sys
import tempfile

DOCKER = "/usr/bin/docker"
PROBE = {
    "mysql": "mysqladmin --protocol=TCP --host=127.0.0.1 ping --silent",
    "pgsql": "pg_isready --host=127.0.0.1 --username=postgres --dbname=postgres",
    "redis": "valkey-cli PING",
}
CLIENT_PASSWORD = {
    "mysql": "MYSQL_PWD",
    "pgsql": "PGPASSWORD",
    "redis": "REDISCLI_AUTH",
}


class Refused(Exception):
    def __init__(self, code):
        self.code = code


def docker(*argv, stdin=None):
    return subprocess.run(
        [DOCKER, *argv], input=stdin, text=True, capture_output=True, check=False
    )


@contextlib.contextmanager
def env_file(variable, value):
    with tempfile.NamedTemporaryFile(mode="w", prefix="orbit-database-") as handle:
        handle.write(variable + "=" + value + "\n")
        handle.flush()
        yield ["--env-file", handle.name]


def observe(intent):
    reply = docker("container", "inspect", intent["container"])
    if reply.returncode:
        return None
    value = json.loads(reply.stdout)[0]
    labels = value["Config"].get("Labels") or {}
    if labels.get("orbit.process.id") != str(intent["process_id"]):
        raise Refused("database.server_container_foreign")
    return value


def pull(intent, _secret):
    image = intent["container_input"]["image"]
    if docker("image", "inspect", image).returncode == 0:
        return {"pulled": False}
    if docker("pull", image).returncode:
        raise Refused("database.server_image_pull_failed")
    return {"pulled": True}


def acl(intent, secret):
    reply = docker(
        "run",
        "--rm",
        "-i",
        "--network",
        "none",
        "--mount",
        "type=volume,source=" + intent["volume"] + ",target=/data",
        "--entrypoint",
        "sh",
        intent["container_input"]["image"],
        "-c",
        "umask 077 && cat > /data/users.acl.orbit-new && mv -f /data/users.acl.orbit-new /data/users.acl",
        stdin=secret,
    )
    if reply.returncode:
        raise Refused("database.valkey_acl_failed")
    return {"written": True}


def create(intent, secret, first_start):
    container = intent["container_input"]
    argv = [
        "create",
        "--name",
        intent["container"],
        "--label",
        "orbit.managed=true",
        "--label",
        "orbit.container.kind=process",
        "--label",
        "orbit.process.id=" + str(intent["process_id"]),
        "--label",
        "orbit.process.spec="
        + (intent["spec"] if first_start else intent["scrub_spec"]),
        "--restart",
        container["restart"],
        "--health-cmd",
        PROBE[intent["driver"]],
        "--health-interval",
        "2s",
        "--health-timeout",
        "5s",
        "--health-retries",
        "3",
        "--health-start-period",
        "300s",
    ]
    for port in container["ports"]:
        argv += ["--publish", port]
    for mount in container["mounts"]:
        kind = "bind" if mount["source"].startswith("/") else "volume"
        argv += [
            "--mount",
            "type="
            + kind
            + ",source="
            + mount["source"]
            + ",target="
            + mount["target"]
            + (",readonly" if mount["read_only"] else ""),
        ]
    if container["working_directory"]:
        argv += ["--workdir", container["working_directory"]]
    secret_env = (
        env_file(intent["password_variable"], secret)
        if first_start
        else contextlib.nullcontext([])
    )
    with secret_env as extra:
        reply = docker(*argv, *extra, container["image"], *container["command"])
    if reply.returncode:
        raise Refused("database.server_start_failed")
    if docker("start", intent["container"]).returncode:
        raise Refused("database.server_start_failed")


def start(intent, secret):
    current = observe(intent)
    if current is not None:
        spec = current["Config"]["Labels"].get("orbit.process.spec")
        if spec in (intent["spec"], intent["scrub_spec"]):
            running = current["State"]["Running"]
            # A running Valkey reads the ACL file the step before rewrote only when it starts again.
            if running and intent["driver"] != "redis":
                return {"created": False}
            action = ["restart", "--time", "30"] if running else ["start"]
            if docker(*action, intent["container"]).returncode:
                raise Refused("database.server_start_failed")
            return {"created": False}
        if docker("rm", "--force", intent["container"]).returncode:
            raise Refused("database.server_start_failed")
    create(intent, secret, intent["initialize"])
    return {"created": True}


def healthy(intent, _secret):
    current = observe(intent)
    if current is None or not current["State"]["Running"]:
        raise Refused("database.server_start_failed")
    status = current["State"].get("Health", {}).get("Status")
    if status == "healthy":
        return {"healthy": True}
    # Docker owns the probe: read its health events since this start; the step timeout bounds the wait.
    events = subprocess.Popen(
        [
            DOCKER,
            "events",
            "--since",
            current["State"]["StartedAt"],
            "--filter",
            "container=" + current["Id"],
            "--filter",
            "event=health_status",
            "--filter",
            "event=die",
            "--format",
            "{{.Action}}",
        ],
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
        text=True,
    )
    try:
        for line in events.stdout:
            if line.strip() == "health_status: healthy":
                return {"healthy": True}
            if line.strip() in ("health_status: unhealthy", "die"):
                break
    finally:
        events.kill()
    raise Refused("database.server_unhealthy")


def login(intent, secret):
    driver = intent["driver"]
    argv = {
        "mysql": [
            "mysql",
            "--protocol=TCP",
            "--host=127.0.0.1",
            "--user=root",
            "--batch",
            "--skip-column-names",
            "--execute=SELECT 1",
        ],
        "pgsql": [
            "psql",
            "--no-psqlrc",
            "--host=127.0.0.1",
            "--username=postgres",
            "--dbname=postgres",
            "--tuples-only",
            "--no-align",
            "--command=SELECT 1",
        ],
        "redis": ["valkey-cli", "--user", "default", "--no-auth-warning", "PING"],
    }[driver]
    with env_file(CLIENT_PASSWORD[driver], secret) as extra:
        reply = docker("exec", *extra, intent["container"], *argv)
    if reply.returncode or reply.stdout.strip() != (
        "PONG" if driver == "redis" else "1"
    ):
        raise Refused("database.server_login_failed")
    return {"login": True}


def scrub(intent, secret):
    current = observe(intent)
    variables = [
        item.split("=", 1)[0]
        for item in (current or {}).get("Config", {}).get("Env") or []
    ]
    if current is not None and intent["password_variable"] not in variables:
        return login(intent, secret)
    if current is not None and (
        docker("stop", "--time", "60", intent["container"]).returncode
        or docker("rm", intent["container"]).returncode
    ):
        raise Refused("database.server_scrub_failed")
    create(intent, secret, False)
    healthy(intent, secret)
    return login(intent, secret)


if __name__ == "__main__":
    phase = sys.argv[2]
    try:
        value = json.loads(sys.argv[1])
        secret = sys.stdin.read() if phase in ("acl", "start", "login", "scrub") else ""
        secret = secret if phase == "acl" else secret.rstrip("\n")
        result = {
            "pull": pull,
            "acl": acl,
            "start": start,
            "healthy": healthy,
            "login": login,
            "scrub": scrub,
        }[phase](value, secret)
    except Refused as error:
        print(json.dumps({"error_code": error.code}))
        raise SystemExit(1) from None
    print(json.dumps(result))
