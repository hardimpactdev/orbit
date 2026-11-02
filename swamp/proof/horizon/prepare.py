"""A PHP process fixture for Horizon's graceful CLI boundary; no Redis/job engine in minimal."""

import subprocess

subprocess.run(
    [
        "incus",
        "exec",
        "target",
        "--",
        "install",
        "-d",
        "-o",
        "orbit",
        "-g",
        "orbit",
        "-m",
        "0755",
        "/opt/proof-horizon",
    ],
    check=True,
)
subprocess.run(
    ["incus", "exec", "target", "--", "tee", "/opt/proof-horizon/artisan"],
    input=b"""<?php
if (($argv[1] ?? '') === 'horizon:terminate') {
    file_put_contents('/opt/proof-horizon/terminated', 'accepted');
    exit(0);
}
while (true) { sleep(1); }
""",
    stdout=subprocess.DEVNULL,
    check=True,
)
subprocess.run(
    [
        "incus",
        "exec",
        "target",
        "--",
        "chown",
        "orbit:orbit",
        "/opt/proof-horizon/artisan",
    ],
    check=True,
)
subprocess.run(
    ["incus", "exec", "target", "--", "rm", "-f", "/opt/proof-horizon/terminated"],
    check=True,
)
