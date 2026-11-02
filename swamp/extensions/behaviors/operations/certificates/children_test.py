"""The certificate children change Nodes only through official steps; 209's signer is the one shipped script."""

import unittest
from pathlib import Path

import yaml

WORKFLOWS = Path(__file__).resolve().parents[4] / "workflows"
OFFICIAL = {
    "@swamp/ssh",
    "@swamp/cfgmgmt/directory",
    "@swamp/cfgmgmt/file",
    "@swamp/cfgmgmt/link",
}


def steps(name):
    workflow = yaml.safe_load((WORKFLOWS / f"workflow-{name}.yaml").read_text())
    return [step for job in workflow["jobs"] for step in job["steps"]]


class CertificateChildrenTest(unittest.TestCase):
    def assert_official(self, name, labels):
        rows = steps(name)
        self.assertEqual(labels, [step["name"] for step in rows])
        for step in rows:
            task = step["task"]
            self.assertEqual({"role": "swamp"}, step["labels"])
            if task["behaviorType"] == "@swamp/ssh":
                self.assertEqual("exec", task["methodName"], step["name"])
            elif task["behaviorType"] == "@nckrtl/certificate-sign":
                self.assertEqual("Sign the CSR", step["name"])
                self.assertEqual("sign", task["methodName"])
            else:
                self.assertIn(task["behaviorType"], OFFICIAL, step["name"])

    def test_route_publish_installs_each_leaf_and_the_account_copy(self):
        self.assert_official(
            "route-certificate-publish",
            [
                "Read the account",
                "Make the leaf folder",
                "Make a CSR on the Node",
                "Protect the key",
                "Sign the CSR",
                "Install the certificate",
                "Select the certificate",
                "Copy the account pair",
                "Select the account pair",
                "Trust the Orbit root",
                "Refresh the trust store",
            ],
        )

    def test_route_remove_deletes_both_folders(self):
        self.assert_official(
            "route-certificate-remove",
            ["Read the account", "Remove the Caddy pair", "Remove the account pair"],
        )
        self.assertEqual(
            ["absent", "absent"],
            [
                step["task"]["globalArgs"]["ensure"]
                for step in steps("route-certificate-remove")[1:]
            ],
        )

    def test_role_publish_reloads_caddy_before_removing_the_pending_key(self):
        self.assert_official(
            "node-role-certificate-publish",
            [
                "Make the key folder",
                "Make a CSR on the Node",
                "Sign the CSR",
                "Make the certificate folder",
                "Install the key",
                "Install the certificate",
                "Install the Orbit root",
                "Reload Caddy",
                "Remove the pending key",
            ],
        )


if __name__ == "__main__":
    unittest.main()
