"""Publish the admitted ACL file into its named volume with private Docker stdin."""

from server_common import DOCKER, SOCKET, Refused, command, main


def write(intent, material):
    reply = command(
        [
            DOCKER,
            SOCKET,
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
        ],
        material["acl"],
    )
    if reply.returncode:
        raise Refused("database.valkey_acl_failed")
    return {"ready": True}


if __name__ == "__main__":
    main(write)
