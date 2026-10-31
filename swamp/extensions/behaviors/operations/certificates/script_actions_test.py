"""Run the existing real OpenSSL contract against the shipped Python functions."""

import importlib.util
import unittest
from pathlib import Path

BASE = Path(__file__).parent
SPEC = importlib.util.spec_from_file_location(
    "original_certificate_contract", BASE / "system_test.py"
)
original = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(original)


class CertificateScriptsContractTest(original.CertificateSystemTest):
    program = (
        (BASE / "scripts/leaf.py").read_text()
        + """
def execute(action, raw):
    data, account = admitted(raw)
    if action == "certificate.request-leaf":
        return request(data, account)
    if action == "certificate.publish-leaf":
        return publish(data, account)
    root, caddy = roots(data)
    if action == "certificate.inspect-leaf":
        return {"present": (root / "current/cert.pem").is_file() and (caddy / "current/cert.pem").is_file()}
    if action == "certificate.remove-leaf":
        for path in (root, caddy):
            if path.exists():
                shutil.rmtree(path)
        return {}
    raise ValueError("unexpected fixture action")
"""
    )


if __name__ == "__main__":
    unittest.main()
