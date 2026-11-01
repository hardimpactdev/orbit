"""Install locked production dependencies and check the release structure."""

from pathlib import Path

from phase_common import context, managed, ready

data, account = context()
managed(
    account,
    [
        "/usr/bin/composer",
        "--working-dir",
        account["release"],
        "install",
        "--no-dev",
        "--no-interaction",
        "--prefer-dist",
        "--optimize-autoloader",
    ],
)
release = Path(account["release"])
if not (release / "artisan").is_file() or not (release / "vendor").is_dir():
    raise ValueError("websocket.release_invalid")
ready()
