"""Release bootstrap identities, capacity and the private, rename-safe env projection."""
import hashlib
import importlib.util
import json
import os
import subprocess
import tempfile
import unittest
from importlib.machinery import SourceFileLoader
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]


def module(name, path):
    loader = SourceFileLoader(name, str(ROOT / path))
    spec = importlib.util.spec_from_loader(name, loader)
    result = importlib.util.module_from_spec(spec)
    loader.exec_module(result)
    return result


prep = module("prepare", "bin/gateway-container-prepare")
environment = module("environment", "bin/gateway-container-environment")
recovery = module("recovery", "bin/gateway-container-rollback")
guest = module("guest", "swamp/ansible/files/gateway-release-guest.py")
infra = module("infra", "swamp/ansible/files/gateway-release-infrastructure.py")


class BootstrapTest(unittest.TestCase):
    def test_network_must_be_private_and_disjoint_except_its_existing_bridge(self):
        interfaces = [{"ifname": "wg0", "addr_info": [{"family": "inet", "scope": "global", "local": "10.45.0.1"}]},
                      {"ifname": "ens3", "addr_info": []}]
        def network(address, routes):
            return prep.network_plan(address, "wg0", "ens3", routes, interfaces)
        self.assertEqual(network("10.8.0.1/24", [{"dst": "10.8.0.0/24", "dev": "orbitgw0"}])["subnet"], "10.8.0.0/24")
        for address in ("10.8.0.0/24", "8.8.8.1/24", "127.0.0.1/24", "169.254.16.1/24", "10.8.0.1/30"):
            with self.subTest(address=address), self.assertRaises(ValueError):
                network(address, [])
        with self.assertRaisesRegex(ValueError, "overlaps"):
            network("10.8.0.1/24", [{"dst": "10.8.0.12", "dev": "ens3"}])

    def test_storage_requires_space_for_the_observed_build_and_active_free_swap(self):
        free = "Filename Type Size Used Priority\n/swap file 4096 2 -2"
        self.assertEqual(prep.validate_storage("tank/orbit", 5120, free, 4096), "tank/orbit/orbit-gateway-releases")
        for dataset, space, swap in (("tank/../x", 5120, free), ("tank/orbit", 2048, free),
                                     ("tank/orbit", 5120, free.splitlines()[0]), ("tank/orbit", 5120, free.replace(" 2 ", " 4096 "))):
            with self.assertRaises(ValueError):
                prep.validate_storage(dataset, space, swap, 4096)

    def test_only_a_complete_stock_archive_matches_its_admitted_digest(self):
        with tempfile.TemporaryDirectory() as root:
            archive = Path(root) / "stock"
            archive.write_bytes(b"admitted")
            digest = hashlib.sha256(archive.read_bytes()).hexdigest()
            prep.verify_archive(archive, digest)
            archive.write_bytes(b"changed")
            with self.assertRaisesRegex(ValueError, "fingerprint"):
                prep.verify_archive(archive, digest)

    def test_runtime_uses_the_repositorys_pinned_arch_specific_builds(self):
        recipe = json.loads((ROOT / "apps/gateway/resources/machine-software/recipe.json").read_text())
        plan = prep.runtime_plan(recipe)
        self.assertEqual(len(plan["incus"]["packages"]), 3)
        self.assertEqual(plan["php"]["sha256"], recipe["instances"]["php"]["versions"]["8.5"]["sha256"])
        recipe["instances"]["runtime"]["composer"]["artifacts"]["amd64"]["sha256"] = "0" * 64
        self.assertNotEqual(plan["runtime_dir"], prep.runtime_plan(recipe)["runtime_dir"])

    def test_runtime_selection_updates_only_the_owned_preparer_and_verifies_the_active_mount(self):
        old = "/opt/orbit-gateway-runtime/" + "a" * 16
        new = "/opt/orbit-gateway-runtime/" + "b" * 16
        record = {"config": {"user.orbit.release.role": "prepare", "user.orbit.release.target": "gateway",
                             "user.orbit.release.operation": "17"},
                  "devices": {"runtime": {"path": guest.RUNTIME, "type": "disk", "readonly": "true", "source": old}}}
        with patch.object(guest, "info", return_value=record), patch.object(guest, "incus") as incus, \
                patch.object(guest, "guest", return_value="a  tool") as read, patch("subprocess.check_output", return_value="a  tool"):
            with self.assertRaisesRegex(ValueError, "preparation_changed"):
                guest.select_runtime("16", new)
            incus.assert_not_called()
            guest.select_runtime("17", new)
            incus.assert_called_once_with("config", "device", "set", "prepare-gateway", "runtime", "source=" + new)
            self.assertEqual(read.call_count, 3)
            incus.reset_mock()
            record["devices"]["runtime"]["source"] = new
            read.return_value = "b  tool"
            with self.assertRaisesRegex(ValueError, "did not mount"):
                guest.select_runtime("17", new)
            incus.assert_not_called()

    def test_host_loopback_queue_is_rejected_before_guest_provisioning(self):
        for ip in ("127.0.0.1", "::1", "0.0.0.0"):
            with self.assertRaisesRegex(ValueError, "loopback"):
                prep.verify_queue(ip)
        prep.verify_queue("10.45.0.9")

    def test_stock_and_existing_resources_cannot_be_adopted_with_wrong_ownership(self):
        for config in ({}, {"user.orbit.purpose": "development"}):
            with patch.object(infra, "existing", return_value={"config": config}), patch.object(infra, "output") as command:
                with self.assertRaisesRegex(ValueError, "unexpected"):
                    infra.create("projects", "orbit-releases", ["project", "create", "orbit-releases"], infra.OWNER)
                command.assert_not_called()
        with patch.object(guest, "info", return_value={"devices": {"state": {"source": "/other"}}}), patch.object(guest, "incus") as command:
            with self.assertRaisesRegex(ValueError, "different"):
                guest.disk("state", guest.HOME_STATE, guest.HOME_STATE, True, False)
            command.assert_not_called()

    def test_a_failed_guest_account_check_cannot_expose_any_private_device(self):
        machine = {"status": "Running", "config": {}, "devices": {"root": {}, "eth0": {}}}
        with patch.object(guest, "info", return_value=machine), patch.object(guest, "guest", side_effect=ValueError("account")), \
                patch.object(guest, "disk") as attach, patch.object(guest, "incus") as incus:
            with self.assertRaisesRegex(ValueError, "account"):
                guest.main({"uid": 1000, "gid": 1000})
            attach.assert_not_called()
            incus.assert_not_called()

    def test_preparation_fences_an_open_switch_before_first_inventory(self):
        with tempfile.TemporaryDirectory() as temp:
            home = Path(temp) / "home"
            state = home / ".orbit"
            state.mkdir(parents=True)
            (state / "gateway-release.lock").touch()
            journal = Path(temp) / "journal"
            journal.mkdir()
            operation = journal / "operation"
            operation.write_text("42\n")
            with patch.object(prep, "SERVICE_HOME", home), patch.object(prep, "JOURNAL", journal):
                with self.assertRaisesRegex(ValueError, "--recover"), prep.admission_lock():
                    self.fail("preparation reached the helper installation")
                operation.unlink()
                with prep.admission_lock():
                    pass

    def test_offline_recovery_selects_the_journaled_live_or_host_program(self):
        with tempfile.TemporaryDirectory() as temp:
            journal = Path(temp)
            inventory = journal / "inventory"
            host = journal / "installed-host-rollback"
            select = lambda action: recovery.implementation(inventory, journal, host, action)
            self.assertEqual(select("abc"), host)
            inventory.write_text(json.dumps({"version": 1, "live": "a"*40, "old": "b"*40}))
            for action in ("--host", "b"*12, ""):
                with self.assertRaisesRegex(ValueError, "fix forward"):
                    select(action)
            (journal / "operation").write_text("42")
            self.assertEqual(select("--recover"), host)
            with self.assertRaisesRegex(ValueError, "--recover"):
                select("abc")

    def test_release_checkout_keeps_only_admitted_history_for_cold_fleet_cli_resolution(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            mirror, release = root / "source", root / "release"
            mirror.mkdir()
            release.mkdir()

            def git(directory, *args):
                return subprocess.check_output(["git", "-C", directory, *args], text=True).strip()

            git(mirror, "init", "-q")
            git(mirror, "config", "user.name", "Fixture")
            git(mirror, "config", "user.email", "fixture@example.invalid")
            git(mirror, "remote", "add", "origin", "https://fixture.invalid/private-origin")
            git(mirror, "commit", "-qm", "parent", "--allow-empty")
            parent = git(mirror, "rev-parse", "HEAD")
            (mirror / "README").write_text("release")
            git(mirror, "add", "README")
            git(mirror, "commit", "-qm", "release")
            commit = git(mirror, "rev-parse", "HEAD")
            git(mirror, "checkout", "-qb", "unrelated", parent)
            (mirror / "private-ref").write_text("outside the admitted commit")
            git(mirror, "add", "private-ref")
            git(mirror, "commit", "-qm", "unrelated")
            other = git(mirror, "rev-parse", "HEAD")
            excluded_blob = git(mirror, "rev-parse", "HEAD:private-ref")
            git(mirror, "gc", "--quiet")

            script = ROOT / "swamp/scripts/gateway-release-checkout.sh"
            abandoned = root / ".gateway-previous"
            abandoned.mkdir()
            (abandoned / "private-history").write_text("old build")
            (root / ".gateway-next").symlink_to(mirror, target_is_directory=True)
            subprocess.run(["bash", script, mirror, commit, release], check=True)
            self.assertFalse(abandoned.exists())
            self.assertFalse((root / ".gateway-next").is_symlink())
            self.assertTrue(mirror.is_dir())
            self.assertEqual((release / "README").read_text(), "release")
            self.assertEqual(git(release, "rev-parse", "HEAD"), commit)
            self.assertEqual(git(release, "rev-list", "--count", commit), "2")
            self.assertEqual(git(release, "rev-list", "--first-parent", commit).splitlines(), [commit, parent])
            git(release, "diff", "--quiet", parent, commit, "--", "apps/cli")
            self.assertEqual(git(release, "remote"), "")
            self.assertEqual(git(release, "for-each-ref"), "")
            for obj in [other, excluded_blob]:
                self.assertNotEqual(subprocess.run(["git", "-C", release, "cat-file", "-e", obj], check=False).returncode, 0)

            # Exercise the upgrade from a checkout with separate CLI and SDK installs.
            git(mirror, "checkout", "-q", "--detach", commit)
            for package in ("apps/cli", "apps/gateway", "packages/php-sdk"):
                (mirror / package).mkdir(parents=True)
                (mirror / package / "composer.json").write_text("{}")
            git(mirror, "add", "apps", "packages")
            git(mirror, "commit", "-qm", "package roots")
            first = git(mirror, "rev-parse", "HEAD")
            subprocess.run(["bash", script, mirror, first, release], check=True)
            repo_inode = (release / ".git").stat().st_ino
            packs = set((release / ".git/objects/pack").glob("*.pack"))
            gateway_vendor = release / "apps/gateway/vendor"
            source_packages = [release / "apps/cli", release / "packages/php-sdk"]
            vendors = [gateway_vendor, *(package / "vendor" for package in source_packages)]
            for vendor in vendors:
                vendor.mkdir()
                (vendor / "installed").write_text("complete")
            (release / "REVISION").write_text(first)
            (release / "stale-build-output").write_text("discard")
            (mirror / "README").write_text("next release")
            git(mirror, "commit", "-qam", "normal release")
            second = git(mirror, "rev-parse", "HEAD")
            subprocess.run(["bash", script, mirror, second, release], check=True)
            self.assertEqual((release / ".git").stat().st_ino, repo_inode)
            self.assertTrue(packs and packs.issubset((release / ".git/objects/pack").glob("*.pack")))
            self.assertEqual((release / "README").read_text(), "next release")
            self.assertFalse((release / "stale-build-output").exists())
            self.assertEqual((gateway_vendor / "installed").read_text(), "complete")
            for package in source_packages:
                self.assertTrue((package / "composer.json").is_file())
                self.assertFalse((package / "vendor").exists())
            self.assertFalse((release / "REVISION").exists())

            # Interrupt before the seal. The retry must discard partial Composer state.
            (gateway_vendor / "installed").write_text("partial")
            (release / ".git/index.lock").touch()
            subprocess.run(["bash", script, mirror, second, release], check=True)
            self.assertTrue(all(not vendor.exists() for vendor in vendors))
            self.assertEqual(git(release, "rev-parse", "HEAD"), second)
            # A forced switch across histories cannot expose the discarded branch's objects.
            (release / "REVISION").write_text(second)
            subprocess.run(["bash", script, mirror, other, release], check=True)
            self.assertNotEqual(subprocess.run(["git", "-C", release, "cat-file", "-e", second], check=False).returncode, 0)
            self.assertEqual(git(release, "for-each-ref"), "")

    def test_env_projection_tracks_atomic_edits_without_exposing_the_bare_repo(self):
        with tempfile.TemporaryDirectory() as root:
            shared = Path(root)
            source, projection = shared / "gateway.env", shared / ".view"
            (shared / "orbit.git").mkdir()
            source.write_text("first")
            source.chmod(0o600)
            environment.publish(projection, source)
            source.rename(shared / "previous")
            source.write_text("replacement")
            source.chmod(0o600)
            environment.publish(projection, source)
            self.assertEqual([p.name for p in projection.iterdir()], ["gateway.env"])
            self.assertTrue(os.path.samefile(source, projection / "gateway.env"))
            source.chmod(0o644)
            with self.assertRaisesRegex(ValueError, "private file"):
                environment.publish(projection, source)


if __name__ == "__main__":
    unittest.main()
