"""Publisher boundary: the real Gateway lock spans load, policy and token proof."""

import base64
import json
import pathlib
import subprocess


def gateway_script(announcement):
    code = pathlib.Path(__file__).parent
    proof = (
        (code / "configured_policy_proof.php").read_text().removeprefix("<?php").strip()
    )
    proof = proof.removeprefix("declare(strict_types=1);").lstrip()
    receipt = base64.b64encode(
        json.dumps({"announcement": announcement}).encode()
    ).decode()
    return (
        (code / "gateway_load.php")
        .read_text()
        .replace("/* POLICY_PROOF */", proof)
        .replace("LOAD_RECEIPT", receipt)
    )


def run(announcement):
    result = subprocess.run(
        [
            "ssh",
            "-T",
            "-o",
            "BatchMode=yes",
            "-o",
            "StrictHostKeyChecking=yes",
            "-o",
            "ConnectTimeout=10",
            "gateway",
            "php",
        ],
        input=gateway_script(announcement),
        check=False,
        capture_output=True,
        text=True,
        timeout=1320,
    )
    if result.returncode:
        raise RuntimeError(
            "gateway_interlocked_load_refused_or_uncertain_inspect_checkpoints"
        )
    return json.loads(result.stdout)
