import grp
import os
import pwd
import stat


def main(data):
    try:
        root = data["root"]
        if not os.path.isabs(root) or any(
            part in (".", "..") for part in root.split("/")
        ):
            raise ValueError()
        uid, gid = pwd.getpwnam(data["user"]).pw_uid, grp.getgrnam(data["group"]).gr_gid
        current = "/"
        for part in filter(None, root.split("/")):
            current = os.path.join(current, part)
            if os.path.lexists(current):
                if (
                    not stat.S_ISDIR(os.lstat(current).st_mode)
                    or os.path.realpath(current) != current
                ):
                    raise ValueError()
            else:
                if not data["create_missing"]:
                    raise ValueError()
                os.mkdir(current, 0o755)
                os.chown(current, uid, gid)
        metadata = os.lstat(root)
        mode = stat.S_IMODE(metadata.st_mode)
        if (
            metadata.st_uid != uid
            or metadata.st_gid != gid
            or mode & 0o700 != 0o700
            or mode & 0o022
        ):
            raise ValueError()
        return {}
    except (ValueError, KeyError, OSError):
        return {"error_code": "node.storage_root_unusable"}
