"""A development Route's Caddy publication as a Node-local transaction: its own groups go into what the Node holds under the Node lock,
beside production, Ingress and Gateway sites, other publications and withdrawals (docs/reference/routes.md#coordinate-publication)."""

import importlib.util
import json
import multiprocessing
import os
import tempfile
import threading
import time
import unittest
from pathlib import Path
from unittest.mock import patch

HERE = Path(__file__).resolve().parent
SOURCE = HERE.parent / "ansible/module_utils/orbit_route_withdraw.py"
SPEC = importlib.util.spec_from_file_location("route_publish", SOURCE)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)
RECORDED = json.loads((HERE / "fixtures/route-withdraw/recorded-destroys.json").read_text())

# The Gateway's global options as a live Node holds them (recorded on Node 9, 14 November 2026).
HEADER = "# Managed by Orbit: Node Caddy build\n{\n    auto_https disable_certs\n    order abort first\n    metrics {\n        per_host\n    }\n}\n"
PRODUCTION = "# orbit: app-prod route-501\nhttps://shop.example {\n    reverse_proxy 10.44.0.12:443\n}\n"
INGRESS = "# orbit: ingress route-77\nhttps://nckrtl.example {\n    reverse_proxy 10.44.0.3:443\n}\n"
GATEWAY = "# orbit: gateway gateway-web\nhttps://gateway.orbit {\n    reverse_proxy 127.0.0.1:8000\n}\n"
RELOAD = ["timeout", "30", "systemctl", "reload", "caddy"]


def caddyfile(*blocks, header=HEADER):
    return header + ("\n" + "\n".join(blocks) if blocks else "")


def own(instance, port=8080):
    """A clone's four groups, as RouteCaddySiteSource names them."""
    return [f"# orbit: app-dev app-instance-{instance}{suffix}\nhttps://i{instance}.test {{\n    reverse_proxy 10.253.0.{instance % 200}:{port}\n}}\n"
            for suffix in ("", "-dev", "-bridge", "-dev-bridge")]


def plan(instance, port=8080, served=True):
    """A create of one clone: sparse states that hold only its own groups, as the Gateway captures them."""
    before, after = caddyfile(), caddyfile(*own(instance, port))
    return {"chain": [before, before, before, after], "had_served_groups": served}


class Tools:
    """Records argv; the lock is real util-linux flock. A named step fails, or a callback runs during validate."""

    def __init__(self, fail=(), during_validate=None, sleep=0.0):
        self.calls, self.fail, self.during_validate, self.sleep = [], set(fail), during_validate, sleep

    def __call__(self, argv, **kwargs):
        if argv[0] == "flock":
            import subprocess

            done = subprocess.run(argv, pass_fds=kwargs.get("pass_fds", ()), capture_output=True, text=True, check=False, timeout=60)
            return done.returncode, done.stdout, done.stderr
        self.calls.append(argv)
        if "validate" in argv:
            if self.sleep:
                time.sleep(self.sleep)
            if self.during_validate:
                self.during_validate()
        joined = " ".join(argv)
        for needle in list(self.fail):
            if needle in joined:
                if needle.startswith("reload"):
                    self.fail.discard(needle)
                return 1, "", f"{needle} failed"
        return 0, "", ""


class RoutePublishTest(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory(prefix="orbit-publish-")
        self.addCleanup(temp.cleanup)
        self.root = Path(temp.name)
        (self.root / "etc/caddy").mkdir(parents=True)
        self.caddy = self.root / "etc/caddy/Caddyfile"
        self.previous = self.root / "var/lib/orbit/route-previous"
        self.live = caddyfile(PRODUCTION, *own(7), INGRESS, GATEWAY)
        self.caddy.write_text(self.live)
        self.caddy.chmod(0o640)

    def publish(self, value, tools=None, wait=10):
        return MODULE.publish(value, tools or Tools(), root=str(self.root), wait=wait)

    def refused(self, value, tools=None, wait=10):
        with self.assertRaises(MODULE.WithdrawFailure) as refusal:
            self.publish(value, tools, wait)
        self.assertEqual(self.caddy.read_text(), self.live, "a refusal leaves the Caddyfile as it was read")
        self.assertFalse(list((self.root / "etc/caddy").glob(".orbit-candidate-*")))
        return refusal.exception.code

    def test_a_publication_adds_only_its_own_groups_and_keeps_every_other_byte_and_order(self):
        tools = Tools()
        result = self.publish(plan(8), tools)
        self.assertEqual(self.caddy.read_text(), caddyfile(PRODUCTION, *own(7), INGRESS, GATEWAY, *own(8)))
        self.assertTrue(self.caddy.read_text().startswith(HEADER))
        self.assertEqual(result["owned"], [f"app-dev app-instance-8{s}" for s in ("", "-dev", "-bridge", "-dev-bridge")])
        self.assertEqual((self.previous / "Caddyfile").read_text(), self.live)
        self.assertEqual(oct(self.previous.stat().st_mode & 0o777), "0o700")
        self.assertEqual(oct(self.caddy.stat().st_mode & 0o777), "0o640")
        validate = next(i for i, argv in enumerate(tools.calls) if "validate" in argv)
        self.assertLess(validate, tools.calls.index(RELOAD))

    def test_recorded_clone_sites_publish_beside_production_ingress_and_gateway_sites(self):
        # The 15 recorded destroys of 14 November, read the other way: each removed clone's real groups published again.
        clones = [record for record in RECORDED if "before" in record["caddy"]]
        self.assertGreaterEqual(len(clones), 5)
        for record in clones:
            with self.subTest(operation=record["operation_id"]):
                states = [record["caddy"][name] for name in ("after", "withdrawn", "prepared", "before")]
                value = {"chain": [state["content"] for state in states], "had_served_groups": True}
                self.caddy.write_text(self.live)
                result = self.publish(value)
                composed = MODULE.caddy_sections(self.caddy.read_text())
                read = MODULE.caddy_sections(self.live)
                self.assertEqual(composed[0], read[0])
                for key, blocks in read[1].items():
                    self.assertEqual(composed[1][key], blocks)
                expected = MODULE.caddy_sections(states[-1]["content"])[1]
                for key in expected:
                    self.assertEqual(composed[1][key], expected[key])
                self.assertTrue(all(key.startswith("app-dev ") for key in result["owned"]))
                self.assertEqual(list(composed[1])[:len(read[1])], list(read[1]))

    def test_seven_publications_and_a_withdrawal_of_another_route_on_one_node_all_land(self):
        context = multiprocessing.get_context("fork")
        processes = [context.Process(target=_publish_child, args=(str(self.root), instance)) for instance in range(10, 17)]
        processes.append(context.Process(target=_withdraw_child, args=(str(self.root),)))
        for process in processes:
            process.start()
        for process in processes:
            process.join(60)
            self.assertEqual(process.exitcode, 0)
        sections = MODULE.caddy_sections(self.caddy.read_text())
        self.assertEqual(sections[0], MODULE.caddy_sections(self.live)[0])
        for instance in range(10, 17):
            for block in own(instance):
                self.assertIn(block, self.caddy.read_text())
        self.assertNotIn("app-instance-7\n", self.caddy.read_text())
        for block in (PRODUCTION, INGRESS, GATEWAY):
            self.assertIn(block, self.caddy.read_text())
        foreign = [key for key in sections[1] if not key.startswith("app-dev")]
        self.assertEqual(foreign, ["app-prod\0route-501", "ingress\0route-77", "gateway\0gateway-web"])

    def test_a_state_that_changes_a_production_ingress_or_gateway_site_is_refused(self):
        for block in (PRODUCTION, INGRESS, GATEWAY):
            with self.subTest(block=block.split("\n")[0]):
                value = plan(8)
                value["chain"][-1] = caddyfile(*own(8), block.replace("443", "444").replace("8000", "8001"))
                self.assertEqual(self.refused(value), "route.publish_foreign_change")

    def test_a_publication_never_changes_the_global_options_it_holds(self):
        # The Gateway's render carries other global options than the Node holds: the Node's stay, and no publication is refused for it.
        value = plan(8)
        value["chain"] = [caddyfile(header=HEADER.replace("per_host", "per_host\n        foo")) if index < 3 else
                          caddyfile(*own(8), header=HEADER.replace("per_host", "per_host\n        foo")) for index in range(4)]
        self.publish(value)
        self.assertTrue(self.caddy.read_text().startswith(HEADER + "\n"))
        self.assertNotIn("foo", self.caddy.read_text())

    def test_a_retry_replaces_its_own_live_groups_instead_of_refusing(self):
        # A Retry of a failed create, or a stale own group from an earlier attempt: the publisher owns its groups and replaces them.
        self.caddy.write_text(caddyfile(PRODUCTION, *own(8, port=9090), INGRESS))
        self.live = self.caddy.read_text()
        self.publish(plan(8))
        self.assertEqual(self.caddy.read_text(), caddyfile(PRODUCTION, *own(8), INGRESS))

    def test_the_same_publication_twice_changes_nothing_but_still_reloads_for_new_certificates(self):
        self.publish(plan(8))
        written = self.caddy.read_text()
        tools = Tools()
        result = self.publish(plan(8), tools)
        self.assertFalse(result["changed"])
        self.assertEqual(self.caddy.read_text(), written)
        self.assertFalse(any("validate" in argv for argv in tools.calls))
        self.assertIn(RELOAD, tools.calls)

    def test_a_second_create_of_the_same_name_after_a_destroy_adds_its_new_instance(self):
        # A new Instance has a new id, so its groups are new keys; the destroyed one's are gone or, if left, foreign and kept.
        self.publish(plan(9))
        self.assertIn(own(9)[0], self.caddy.read_text())
        for block in own(7):
            self.assertIn(block, self.caddy.read_text())

    def test_a_held_lock_refuses_busy_and_changes_nothing(self):
        lock = self.root / "etc/caddy/.orbit-publish.lock"
        descriptor = os.open(lock, os.O_RDWR | os.O_CREAT, 0o600)
        self.addCleanup(os.close, descriptor)
        import fcntl

        fcntl.flock(descriptor, fcntl.LOCK_EX)
        self.assertEqual(self.refused(plan(8), wait=1), "caddy.publish_busy")

    def test_a_whole_file_build_between_read_and_write_refuses_live_changed(self):
        built = caddyfile(PRODUCTION, INGRESS)

        def build():
            self.caddy.write_text(built)

        with self.assertRaises(MODULE.WithdrawFailure) as refusal:
            self.publish(plan(8), Tools(during_validate=build))
        self.assertEqual(refusal.exception.code, "route.live_changed")
        self.assertEqual(self.caddy.read_text(), built, "the other writer's file stays")
        self.assertFalse(list((self.root / "etc/caddy").glob(".orbit-candidate-*")))

    def test_a_failed_validation_changes_nothing_and_keeps_no_copy(self):
        tools = Tools(fail=["validate"])
        self.assertEqual(self.refused(plan(8), tools), "caddy.validation_failed")
        self.assertNotIn(RELOAD, tools.calls)
        self.assertFalse((self.previous / "Caddyfile").exists())

    def test_a_failed_reload_puts_the_file_back_and_reloads_again(self):
        tools = Tools(fail=["reload"])
        self.assertEqual(self.refused(plan(8), tools), "caddy.reload_failed")
        self.assertEqual(tools.calls.count(RELOAD), 2)

    def test_a_file_without_the_orbit_header_is_refused(self):
        self.caddy.write_text("example.com {\n}\n")
        self.live = self.caddy.read_text()
        self.assertEqual(self.refused(plan(8)), "caddy.foreign_config")

    def test_a_missing_file_on_a_node_that_served_sites_is_refused_and_an_empty_node_gets_its_first(self):
        self.caddy.unlink()
        with self.assertRaises(MODULE.WithdrawFailure) as refusal:
            self.publish(plan(8))
        self.assertEqual(refusal.exception.code, "caddy.current_config_missing")
        self.assertFalse(self.caddy.exists())
        self.publish(plan(8, served=False))
        self.assertEqual(self.caddy.read_text(), caddyfile(*own(8)))

    def test_the_check_refuses_a_foreign_change_a_header_change_and_a_reorder_on_its_own(self):
        # The check works out the own groups from the plan, so a composition that went wrong is still caught.
        value = plan(8)
        for composed in (caddyfile(PRODUCTION.replace("443", "444"), *own(7), INGRESS, GATEWAY, *own(8)),
                         caddyfile(PRODUCTION, *own(7), INGRESS, GATEWAY, *own(8), header=HEADER.replace("per_host", "per_ip")),
                         caddyfile(INGRESS, PRODUCTION, *own(7), GATEWAY, *own(8)),
                         caddyfile(PRODUCTION, *own(7), INGRESS, *own(8))):
            with self.subTest(composed=composed[-60:]):
                with self.assertRaises(MODULE.WithdrawFailure) as refusal:
                    MODULE.check_published(value, self.live, composed)
                self.assertEqual(refusal.exception.code, "route.publish_foreign_change")
        MODULE.check_published(value, self.live, caddyfile(PRODUCTION, *own(7), INGRESS, GATEWAY, *own(8)))

    def test_a_repeated_key_keeps_every_block_where_the_node_holds_it(self):
        # An Instance with two Routes renders one key twice, sorted by domain: a production block of it may sit between them.
        twin = own(7)[0].replace("i7.test", "z7.test")
        self.caddy.write_text(caddyfile(own(7)[0], PRODUCTION, twin, GATEWAY))
        self.live = self.caddy.read_text()
        self.publish(plan(8))
        self.assertEqual(self.caddy.read_text(), caddyfile(own(7)[0], PRODUCTION, twin, GATEWAY, *own(8)))

    def test_the_plan_comes_from_the_captured_publication_of_the_node(self):
        artifacts = [{"content": caddyfile(), "had_served_groups": True}, {"content": caddyfile(*own(8))}]
        row = {"node_id": 9, "serves_routes": True, **{name: {"caddy": 0} for name in ("before", "prepared", "withdrawn")}, "after": {"caddy": 1}}
        mutations = {"artifacts": {"caddy": artifacts}, "restore": {"publications": [row, {**row, "node_id": 10, "serves_routes": False}]}}
        self.assertEqual(MODULE.publication_plan(mutations, 9),
                         {"chain": [caddyfile(), caddyfile(), caddyfile(), caddyfile(*own(8))], "had_served_groups": True})
        self.assertIsNone(MODULE.publication_plan(mutations, 10))
        self.assertIsNone(MODULE.publication_plan({}, 9))


def _publish_child(root, instance):
    MODULE.publish(plan(instance), Tools(sleep=0.2), root=root, wait=30)


def _withdraw_child(root):
    before, after = caddyfile(*own(7)), caddyfile()
    value = {"user": "orbit", "caddy": {"chain": [before, after, after, after], "steps": [{"from": 0, "to": 1, "had_served_groups": True}]},
             "php": None, "dns": None, "cleanup": []}
    MODULE.withdraw(value, Tools(sleep=0.2), root=root, wait=30)


if __name__ == "__main__":
    unittest.main()
