"""The Node-local Caddyfile compare, validate, rename and reload transaction."""

import hashlib
import os
import re
import stat
import tempfile
from pathlib import Path


class PublicationFailure(RuntimeError):
    """A public refusal or tool failure with its publication state."""

    def __init__(self, code, detail, *, changed=False, **facts):
        super().__init__(f"{code}: {detail}")
        self.code = code
        self.changed = changed
        self.facts = facts


def publish(content, composed_from_sha256, run, *, live=Path("/etc/caddy/Caddyfile"),
            uid=0, gid=0, mode=0o640):
    """Run wholly on the Node; run is AnsibleModule.run_command."""
    if not re.fullmatch(r"[a-f0-9]{64}", composed_from_sha256):
        raise PublicationFailure("caddy.composed_from_invalid", "A composed-from SHA-256 is required.")
    lock_path = live.parent / ".orbit-publish.lock"
    try:
        descriptor = os.open(lock_path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
    except OSError as error:
        raise PublicationFailure("caddy.publish_lock_invalid", str(error)) from error
    with os.fdopen(descriptor, "rb") as lock:
        identity = os.fstat(lock.fileno())
        if (not stat.S_ISREG(identity.st_mode) or identity.st_nlink != 1
                or identity.st_uid != os.geteuid() or stat.S_IMODE(identity.st_mode) != 0o600):
            raise PublicationFailure("caddy.publish_lock_invalid", "The lock must be a private regular file owned by the publisher.")
        # flock and this process share the open file description. Its successful
        # lock survives the utility's exit until this context closes the last fd.
        rc, _, stderr = run(["flock", "--exclusive", "--timeout", "5", "--conflict-exit-code", "75",
                             str(lock.fileno())], pass_fds=(lock.fileno(),))
        if rc != 0:
            code = "caddy.publish_busy" if rc == 75 else "caddy.publish_lock_failed"
            raise PublicationFailure(code, "Node publish lock wait exceeded 5 seconds." if rc == 75 else stderr)
        try:
            base = live.read_bytes()
        except FileNotFoundError:
            base = b""
        live_sha256 = hashlib.sha256(base).hexdigest()
        if live_sha256 != composed_from_sha256:
            raise PublicationFailure(
                "caddy.composed_from_live_mismatch",
                f"composed-from SHA-256 {composed_from_sha256}; live SHA-256 {live_sha256}.",
                composed_from_sha256=composed_from_sha256, live_sha256=live_sha256)
        descriptor, name = tempfile.mkstemp(prefix=".orbit-candidate-", dir=live.parent)
        candidate = Path(name)
        published = False
        try:
            with os.fdopen(descriptor, "wb") as stream:
                os.fchown(stream.fileno(), uid, gid)
                os.fchmod(stream.fileno(), mode)
                stream.write(content.encode())
            rc, _, stderr = run(["timeout", "60", "runuser", "-u", "caddy", "--", "caddy", "validate",
                                 "--config", str(candidate), "--adapter", "caddyfile"])
            if rc != 0:
                raise PublicationFailure("caddy.validation_failed", stderr)
            # Keep the exact validated inode and metadata, including when live is a symlink.
            current = live.lstat() if live.exists() or live.is_symlink() else None
            if (current is None or not stat.S_ISREG(current.st_mode) or base != content.encode()
                    or (current.st_uid, current.st_gid, stat.S_IMODE(current.st_mode)) != (uid, gid, mode)):
                os.replace(candidate, live)
                published = True
            rc, _, stderr = run(["timeout", "60", "systemctl", "reload", "caddy"])
            if rc != 0:
                raise PublicationFailure("caddy.reload_failed", stderr, changed=published)
            return {"changed": True, "published": published, "composed_from_sha256": composed_from_sha256,
                    "live_sha256": hashlib.sha256(content.encode()).hexdigest()}
        finally:
            candidate.unlink(missing_ok=True)
