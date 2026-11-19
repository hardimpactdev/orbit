"""The Node-local Route withdrawal transaction: two writers, other Routes' sections, previous generations and failed tests or reloads."""

import fcntl
import importlib.util
import json
import multiprocessing
import os
import subprocess
import tempfile
import time
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

SOURCE = Path(__file__).resolve().parents[1] / "ansible/module_utils/orbit_route_withdraw.py"
SPEC = importlib.util.spec_from_file_location("route_withdraw", SOURCE)
WITHDRAW = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(WITHDRAW)

HEADER = "# Managed by Orbit: Node Caddy build\n{\n    auto_https disable_certs\n}\n"
PRODUCTION = "# orbit: app-prod route-501\nhttps://shop.example {\n    reverse_proxy 10.44.0.12:443\n}\n"


def site(instance):
    return f"# orbit: app-dev app-instance-{instance}\nhttps://i{instance}.test {{\n    reverse_proxy 10.253.0.{instance % 200}:8080\n}}\n"


def caddyfile(*blocks):
    return HEADER + ("\n" + "\n".join(blocks) if blocks else "")


def pool(instance):
    return f"[orbit-app-instance-{instance}]\nuser = orbit\ngroup = __ORBIT_NODE_GROUP__\nchdir = /__ORBIT_NODE_HOME__/i{instance}\n"


def records(*names):
    return "# Managed by Orbit.\n" + "".join(f"host-record={name},10.44.0.7\n" for name in names)


def catalog(*names):
    return json.dumps({"records": {name: "10.44.0.7" for name in names}}, separators=(",", ":")) + "\n"


def plan(instance, *, caddy=True, php=False, dns=False):
    """A destroy of one Instance: its captured states hold only its own sections, as the Gateway's sparse capture does."""
    before, after = caddyfile(site(instance)), caddyfile()
    name = f"i{instance}.test"
    return {
        "user": "orbit",
        "caddy": {"chain": [before, after, after, after], "steps": [{"from": 0, "to": 1, "had_served_groups": True}],
                  "version": "v"} if caddy else None,
        "php": {"versions": ["8.4"], "before": {"8.4": pool(instance)}, "after": {}} if php else None,
        "dns": {"chain": [{"records": records(name), "catalog": catalog(name)}] + [{"records": records(), "catalog": catalog()}] * 3,
                "steps": [0]} if dns else None,
        "cleanup": [],
    }


ACCOUNT = SimpleNamespace(pw_gid=1000, pw_dir="/home/orbit")


class Tools:
    """Records argv; a named step can fail or sleep."""

    def __init__(self, fail=(), sleep=0.0):
        self.calls, self.fail, self.sleep = [], set(fail), sleep

    def __call__(self, argv, **kwargs):
        if argv[0] == "flock":
            # The lock is real: util-linux flock on the inherited descriptor, as on a Node.
            done = subprocess.run(argv, pass_fds=kwargs.get("pass_fds", ()), capture_output=True, text=True, check=False, timeout=60)
            return done.returncode, done.stdout, done.stderr
        self.calls.append(argv)
        joined = " ".join(argv)
        if "validate" in argv and self.sleep:
            time.sleep(self.sleep)
        for needle in list(self.fail):
            if needle in joined:
                self.fail.discard(needle) if needle.startswith("once:") else None
                return 1, "", f"{needle} failed"
        return 0, "", ""


class RouteWithdrawTest(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory(prefix="orbit-withdraw-")
        self.addCleanup(temp.cleanup)
        self.root = Path(temp.name)
        for folder in ("etc/caddy", "etc/php/8.4/fpm/pool.d", "etc/dnsmasq.d", "var/lib/orbit/private-dns", "run/lock"):
            (self.root / folder).mkdir(parents=True)
        self.caddy = self.root / "etc/caddy/Caddyfile"
        self.pool = self.root / "etc/php/8.4/fpm/pool.d/orbit-scopes.conf"
        self.records = self.root / "etc/dnsmasq.d/orbit-records.conf"
        self.catalog = self.root / "var/lib/orbit/private-dns/catalog.json"
        self.previous = self.root / "var/lib/orbit/route-previous"
        self.caddy.write_text(caddyfile(site(7), PRODUCTION, site(8)))
        self.caddy.chmod(0o640)
        account = patch.object(WITHDRAW.pwd, "getpwnam", return_value=ACCOUNT)
        account.start()
        self.addCleanup(account.stop)

    def withdraw(self, value, tools=None):
        return WITHDRAW.withdraw(value, tools or Tools(), root=str(self.root), wait=10)

    def test_a_withdrawal_removes_only_its_own_site_and_keeps_the_previous_generation(self):
        tools = Tools()
        result = self.withdraw(plan(7), tools)
        self.assertEqual(self.caddy.read_text(), caddyfile(PRODUCTION, site(8)))
        self.assertEqual((self.previous / "Caddyfile").read_text(), caddyfile(site(7), PRODUCTION, site(8)))
        self.assertEqual(oct(self.previous.stat().st_mode & 0o777), "0o700")
        self.assertEqual(oct((self.previous / "Caddyfile").stat().st_mode & 0o777), "0o640")
        self.assertEqual(result["previous"], [str(self.previous / "Caddyfile")])
        validate = next(i for i, argv in enumerate(tools.calls) if "validate" in argv)
        reload = tools.calls.index(["timeout", "30", "systemctl", "reload", "caddy"])
        self.assertLess(validate, reload)
        self.assertFalse(list((self.root / "etc/caddy").glob(".orbit-candidate-*")))

    def test_two_withdrawals_of_different_sites_both_land_in_either_order(self):
        self.withdraw(plan(8))
        self.withdraw(plan(7))
        self.assertEqual(self.caddy.read_text(), caddyfile(PRODUCTION))

    def test_two_concurrent_withdrawals_on_one_node_both_land(self):
        context = multiprocessing.get_context("fork")
        processes = [context.Process(target=_child, args=(str(self.root), instance)) for instance in (7, 8)]
        for process in processes:
            process.start()
        for process in processes:
            process.join(20)
            self.assertEqual(process.exitcode, 0)
        self.assertEqual(self.caddy.read_text(), caddyfile(PRODUCTION))

    def test_the_same_route_withdrawn_twice_changes_nothing_the_second_time(self):
        self.withdraw(plan(7))
        tools = Tools()
        result = self.withdraw(plan(7), tools)
        self.assertFalse(result["changed"])
        self.assertEqual(self.caddy.read_text(), caddyfile(PRODUCTION, site(8)))
        self.assertFalse(any("reload" in argv for argv in tools.calls))

    def test_a_merge_that_would_change_a_production_block_is_refused_and_touches_nothing(self):
        value = plan(7)
        changed = PRODUCTION.replace("10.44.0.12", "10.44.0.99")
        # A captured state that disagrees about the production block makes the merge write it; the byte compare refuses.
        value["caddy"]["chain"] = [caddyfile(site(7), PRODUCTION), caddyfile(changed), caddyfile(changed), caddyfile(changed)]
        live = caddyfile(site(7), PRODUCTION, site(8))
        composed = WITHDRAW.compose(value, {"caddy": live, "pools": {}, "dns": None})
        self.assertIn("10.44.0.99", composed["caddy"])
        with self.assertRaises(WITHDRAW.WithdrawFailure) as refused:
            self.withdraw(value)
        self.assertEqual(refused.exception.code, "route.withdraw_foreign_change")
        self.assertIn("route-501", str(refused.exception))
        self.assertEqual(self.caddy.read_text(), live)
        self.assertFalse(self.previous.joinpath("Caddyfile").exists())

    def test_a_production_site_edited_on_the_node_is_kept_byte_for_byte(self):
        edited = PRODUCTION.replace("}\n", "    encode gzip\n}\n")
        self.caddy.write_text(caddyfile(site(7), edited))
        self.withdraw(plan(7))
        self.assertEqual(self.caddy.read_text(), caddyfile(edited))

    def test_an_own_site_in_another_form_is_still_withdrawn(self):
        self.caddy.write_text(caddyfile(site(7).replace("8080", "9090"), PRODUCTION))
        self.withdraw(plan(7))
        self.assertEqual(self.caddy.read_text(), caddyfile(PRODUCTION))

    def beast_3713(self):
        """Beast's live Caddyfile of task-5888 (Instance 3713, 2026-11-19), published before #4020, and the plan that today's rendering
        captures for Route 1439: the same four groups, with the hibernation wake in the `route { forward_auth @orbit_asleep }` form."""
        live = (Path(__file__).parent / "fixtures/route-withdraw/beast-3713-live.caddy").read_text()
        today = live.replace("handle @orbit_asleep {\n    forward_auth https://gateway.orbit {",
                             "route {\n    forward_auth @orbit_asleep https://gateway.orbit {")
        self.assertNotEqual(today, live)
        header, _ = WITHDRAW.caddy_sections(live)
        value = plan(3713)
        value["caddy"]["chain"] = [today, header, header, header]
        return live, header, value

    def test_the_beast_3713_blocks_rendered_by_an_older_orbit_are_withdrawn(self):
        live, header, value = self.beast_3713()
        self.caddy.write_text(live + "\n" + PRODUCTION)
        result = self.withdraw(value)
        self.assertTrue(result["caddy"])
        self.assertEqual(self.caddy.read_text(), header + "\n" + PRODUCTION)

    def test_the_beast_3713_blocks_without_the_dev_bridge_group_are_withdrawn(self):
        live, header, value = self.beast_3713()
        self.caddy.write_text(live[:live.index("\n# orbit: app-dev app-instance-3713-dev-bridge")] + "\n\n" + PRODUCTION)
        self.withdraw(value)
        self.assertEqual(self.caddy.read_text(), header + "\n" + PRODUCTION)

    def test_a_group_of_the_withdrawn_scope_that_the_plan_does_not_name_is_withdrawn(self):
        """An older rendering may hold a group of the scope under a name today's rendering no longer captures."""
        live, header, value = self.beast_3713()
        value["caddy"]["chain"][0] = value["caddy"]["chain"][0][:value["caddy"]["chain"][0].index("\n# orbit: app-dev app-instance-3713-bridge")]
        other = "# orbit: app-dev app-instance-37130-bridge\nhttps://other.test {\n    reverse_proxy 10.253.0.9:8080\n}\n"
        self.caddy.write_text(live + "\n" + other)
        self.withdraw(value)
        self.assertEqual(self.caddy.read_text(), header + "\n" + other)

    def test_a_withdrawal_after_a_node_converge_rebuilt_the_global_options_keeps_them(self):
        live, header, value = self.beast_3713()
        rebuilt = header.replace("    order abort first\n", "    order abort first\n    servers {\n        trusted_proxies static private_ranges\n    }\n")
        self.caddy.write_text(rebuilt + live[len(header):])
        self.withdraw(value)
        self.assertEqual(self.caddy.read_text(), rebuilt)

    def test_a_retry_after_the_route_is_gone_changes_nothing(self):
        live, header, value = self.beast_3713()
        self.caddy.write_text(live + "\n" + PRODUCTION)
        self.withdraw(value)
        tools = Tools()
        self.assertFalse(self.withdraw(value, tools)["changed"])
        self.assertEqual(self.caddy.read_text(), header + "\n" + PRODUCTION)
        self.assertFalse(any("reload" in argv for argv in tools.calls))

    def test_a_concurrent_change_to_a_foreign_site_still_refuses_and_touches_nothing(self):
        live, _, value = self.beast_3713()
        self.caddy.write_text(live + "\n" + PRODUCTION)
        edited = (live + "\n" + PRODUCTION).replace("10.44.0.12", "10.44.0.99")
        tools = Tools()

        def run(argv, **kwargs):
            if "validate" in argv:
                self.caddy.write_text(edited)
            return tools(argv, **kwargs)

        with self.assertRaises(WITHDRAW.WithdrawFailure) as refused:
            WITHDRAW.withdraw(value, run, root=str(self.root), wait=10)
        self.assertEqual(refused.exception.code, "route.live_changed")
        self.assertEqual(self.caddy.read_text(), edited)

    def test_a_failed_validation_leaves_every_file(self):
        self.pool.write_text(pool(7).replace("__ORBIT_NODE_GROUP__", "1000").replace("/__ORBIT_NODE_HOME__", "/home/orbit") + pool(9))
        before = (self.caddy.read_text(), self.pool.read_text())
        with self.assertRaises(WITHDRAW.WithdrawFailure) as refused:
            self.withdraw(plan(7, php=True), Tools(fail=["validate"]))
        self.assertEqual(refused.exception.code, "caddy.validation_failed")
        self.assertEqual((self.caddy.read_text(), self.pool.read_text()), before)

    def test_a_failed_php_test_puts_the_pool_back_before_any_reload(self):
        self.pool.write_text(pool(7).replace("__ORBIT_NODE_GROUP__", "1000").replace("/__ORBIT_NODE_HOME__", "/home/orbit"))
        before = self.pool.read_text()
        tools = Tools(fail=["php-fpm8.4 -t"])
        with self.assertRaises(WITHDRAW.WithdrawFailure) as refused:
            self.withdraw(plan(7, php=True), tools)
        self.assertEqual(refused.exception.code, "php.test_failed")
        self.assertEqual(self.pool.read_text(), before)
        self.assertFalse(any("reload" in " ".join(argv) for argv in tools.calls))

    def test_a_failed_test_keeps_no_previous_copy(self):
        self.pool.write_text(pool(7).replace("__ORBIT_NODE_GROUP__", "1000").replace("/__ORBIT_NODE_HOME__", "/home/orbit"))
        with self.assertRaises(WITHDRAW.WithdrawFailure):
            self.withdraw(plan(7, php=True), Tools(fail=["php-fpm8.4 -t"]))
        self.assertEqual(list(self.previous.iterdir()), [])

    def test_missing_private_dns_files_refuse_by_name_and_put_the_pools_back(self):
        resolved = pool(7).replace("__ORBIT_NODE_GROUP__", "1000").replace("/__ORBIT_NODE_HOME__", "/home/orbit")
        self.pool.write_text(resolved)
        with self.assertRaises(WITHDRAW.WithdrawFailure) as refused:
            self.withdraw(plan(7, caddy=False, php=True, dns=True))
        self.assertEqual(refused.exception.code, "dns.current_config_missing")
        self.assertEqual(self.pool.read_text(), resolved)

    def resolved_pool(self, *ids):
        text = "".join(pool(i).replace("__ORBIT_NODE_GROUP__", "1000").replace("/__ORBIT_NODE_HOME__", "/home/orbit") for i in ids)
        self.pool.write_text(text)
        return text

    def test_a_failed_dns_test_puts_back_the_pools_and_the_dns_files(self):
        pools = self.resolved_pool(7, 9)
        self.records.write_text(records("i7.test", "shop.example"))
        self.catalog.write_text(catalog("i7.test", "shop.example"))
        tools = Tools(fail=["dnsmasq --test"])
        with self.assertRaises(WITHDRAW.WithdrawFailure) as refused:
            self.withdraw(plan(7, caddy=False, php=True, dns=True), tools)
        self.assertEqual(refused.exception.code, "dns.test_failed")
        self.assertEqual((self.pool.read_text(), self.records.read_text()), (pools, records("i7.test", "shop.example")))
        self.assertFalse(any("reload" in " ".join(argv) or "restart" in " ".join(argv) for argv in tools.calls))

    def test_a_dns_refusal_after_the_pools_were_written_puts_them_back(self):
        pools = self.resolved_pool(7)
        self.records.write_text(records("i7.test").replace("10.44.0.7", "10.44.0.99"))
        self.catalog.write_text(catalog("i7.test"))
        with self.assertRaises(WITHDRAW.WithdrawFailure) as refused:
            self.withdraw(plan(7, caddy=False, php=True, dns=True))
        self.assertEqual(refused.exception.code, "dns.owned_revision_conflict")
        self.assertEqual(self.pool.read_text(), pools)

    def test_an_unexpected_error_before_the_commit_puts_every_written_file_back(self):
        pools = self.resolved_pool(7, 9)
        with patch.object(WITHDRAW._File, "keep_previous", side_effect=OSError("No space left on device")), self.assertRaises(OSError):
            self.withdraw(plan(7, php=True))
        self.assertEqual(self.pool.read_text(), pools)
        self.assertEqual(self.caddy.read_text(), caddyfile(site(7), PRODUCTION, site(8)))

    def test_a_failed_php_reload_puts_back_the_pools_and_the_dns_files(self):
        pools = self.resolved_pool(7, 9)
        self.records.write_text(records("i7.test", "shop.example"))
        self.catalog.write_text(catalog("i7.test", "shop.example"))
        with self.assertRaises(WITHDRAW.WithdrawFailure) as refused:
            self.withdraw(plan(7, caddy=False, php=True, dns=True), Tools(fail=["try-reload-or-restart php8.4-fpm"]))
        self.assertEqual(refused.exception.code, "php.reload_failed")
        self.assertEqual((self.pool.read_text(), self.records.read_text()), (pools, records("i7.test", "shop.example")))

    def test_a_pool_keeps_the_other_scopes_and_an_emptied_pool_goes(self):
        resolved = lambda i: pool(i).replace("__ORBIT_NODE_GROUP__", "1000").replace("/__ORBIT_NODE_HOME__", "/home/orbit")
        self.pool.write_text(resolved(7) + resolved(9))
        self.withdraw(plan(7, php=True))
        self.assertEqual(self.pool.read_text(), resolved(9))
        self.assertEqual((self.previous / "php-8.4-orbit-scopes.conf").read_text(), resolved(7) + resolved(9))
        self.withdraw(plan(9, php=True, caddy=False))
        self.assertFalse(self.pool.exists())

    # A publisher before route-create-site wrote the account's primary group by name; FPM reads it as the gid.
    def scope(self, instance, group):
        return pool(instance).replace("__ORBIT_NODE_GROUP__", group).replace("/__ORBIT_NODE_HOME__", "/home/orbit")

    def named_group(self, name="orbit"):
        lookup = patch("grp.getgrgid", return_value=SimpleNamespace(gr_gid=ACCOUNT.pw_gid, gr_name=name))
        lookup.start()
        self.addCleanup(lookup.stop)

    def test_a_scope_with_the_group_name_is_withdrawn_and_the_other_keeps_its_bytes(self):
        self.named_group()
        live = self.scope(7, "orbit") + self.scope(9, "orbit")
        self.pool.write_text(live)
        self.withdraw(plan(7, php=True))
        self.assertEqual(self.pool.read_text(), self.scope(9, "orbit"))
        self.assertEqual((self.previous / "php-8.4-orbit-scopes.conf").read_text(), live)

    def test_a_captured_scope_that_stays_keeps_its_group_name(self):
        self.named_group()
        value = plan(7, php=True)
        value["php"].update(before={"8.4": pool(7) + pool(9)}, after={"8.4": pool(9)})
        self.pool.write_text(self.scope(7, "orbit") + self.scope(9, "orbit"))
        self.withdraw(value)
        self.assertEqual(self.pool.read_text(), self.scope(9, "orbit"))

    def test_a_scope_with_the_gid_is_withdrawn_beside_one_with_the_group_name(self):
        self.named_group()
        self.pool.write_text(self.scope(7, "1000") + self.scope(9, "orbit"))
        self.withdraw(plan(7, php=True))
        self.assertEqual(self.pool.read_text(), self.scope(9, "orbit"))

    def test_a_scope_with_the_group_name_that_differs_otherwise_is_a_revision_conflict(self):
        self.named_group()
        live = self.scope(7, "orbit").replace("user = orbit", "user = www-data") + self.scope(9, "orbit")
        self.pool.write_text(live)
        with self.assertRaises(WITHDRAW.WithdrawFailure) as refused:
            self.withdraw(plan(7, php=True))
        self.assertEqual(refused.exception.code, "php.owned_revision_conflict")
        self.assertEqual(self.pool.read_text(), live)

    def test_another_group_name_is_a_revision_conflict(self):
        self.named_group("staff")
        live = self.scope(7, "orbit")
        self.pool.write_text(live)
        with self.assertRaises(WITHDRAW.WithdrawFailure) as refused:
            self.withdraw(plan(7, php=True))
        self.assertEqual(refused.exception.code, "php.owned_revision_conflict")
        self.assertEqual(self.pool.read_text(), live)

    def test_a_failed_caddy_reload_puts_the_previous_caddyfile_back_and_reloads_again(self):
        live = self.caddy.read_text()
        tools = Tools(fail=["once:systemctl reload caddy"])
        tools.fail = {"systemctl reload caddy"}

        def run(argv, **kwargs):
            rc = tools(argv, **kwargs)
            if " ".join(argv).endswith("reload caddy"):
                tools.fail.clear()
            return rc

        with self.assertRaises(WITHDRAW.WithdrawFailure) as refused:
            WITHDRAW.withdraw(plan(7), run, root=str(self.root), wait=10)
        self.assertEqual(refused.exception.code, "caddy.reload_failed")
        self.assertEqual(self.caddy.read_text(), live)
        self.assertEqual(sum(argv[-2:] == ["reload", "caddy"] for argv in tools.calls), 2)

    def test_dns_keeps_other_records_and_catalog_entries(self):
        self.records.write_text(records("i7.test", "shop.example"))
        self.catalog.write_text(catalog("i7.test", "shop.example"))
        self.withdraw(plan(7, caddy=False, dns=True))
        self.assertEqual(self.records.read_text(), records("shop.example"))
        self.assertEqual(json.loads(self.catalog.read_text()), {"records": {"shop.example": "10.44.0.7"}})
        self.assertEqual((self.previous / "orbit-records.conf").read_text(), records("i7.test", "shop.example"))

    def test_equal_dns_states_write_nothing_so_the_workflow_skips_that_visit(self):
        # An in-TLD Route captures four equal DNS states (no line of its own; NativeRoutePublicationArtifacts::scope). Applied, they
        # change no byte and restart nothing, also when the live file still holds a line an older render wrote for the Route
        # (a Route published before release 2091 on a Node that no whole write has rewritten): a withdrawal only takes back lines its
        # states change. So route-withdraw does not visit the Node for them, and that changes nothing on it.
        empty = {"records": records(), "catalog": catalog()}
        equal = {**plan(7, caddy=False), "dns": {"chain": [empty] * 4, "steps": [0]}}
        for live in (("shop.example",), ("i7.test", "shop.example")):
            self.records.write_text(records(*live))
            self.catalog.write_text(catalog(*live))
            tools = Tools()
            result = self.withdraw(equal, tools)
            self.assertEqual(self.records.read_text(), records(*live))
            self.assertEqual(self.catalog.read_text(), catalog(*live))
            self.assertFalse(result["dns"])
            self.assertFalse([argv for argv in tools.calls if "dnsmasq" in argv or "restart" in argv])
        self.assertFalse((self.previous / "orbit-records.conf").exists())

    def test_a_failed_flock_refuses_by_name_and_touches_nothing(self):
        live = self.caddy.read_text()

        def run(argv, **kwargs):
            return (1, "", "flock: bad file descriptor") if argv[0] == "flock" else Tools()(argv, **kwargs)

        with self.assertRaises(WITHDRAW.WithdrawFailure) as refused:
            WITHDRAW.withdraw(plan(7), run, root=str(self.root), wait=10)
        self.assertEqual(refused.exception.code, "route.publish_lock_failed")
        self.assertEqual(self.caddy.read_text(), live)

    def test_the_dns_lock_is_the_one_every_private_dns_writer_takes(self):
        self.records.write_text(records("i7.test"))
        self.catalog.write_text(catalog("i7.test"))
        lock = self.root / "run/lock/orbit-dnsmasq.lock"
        # publish-dns.py and DnsmasqPrivateDnsManager create it with the default umask: a 0644 lock of the publisher is accepted.
        lock.touch(mode=0o644)
        lock.chmod(0o644)
        descriptor = os.open(lock, os.O_RDWR)
        self.addCleanup(os.close, descriptor)
        fcntl.flock(descriptor, fcntl.LOCK_EX)
        with self.assertRaises(WITHDRAW.WithdrawFailure) as refused:
            WITHDRAW.withdraw(plan(7, caddy=False, dns=True), Tools(), root=str(self.root), wait=1)
        self.assertEqual(refused.exception.code, "dns.publish_busy")
        fcntl.flock(descriptor, fcntl.LOCK_UN)
        self.withdraw(plan(7, caddy=False, dns=True))
        self.assertNotIn("i7.test", self.records.read_text())
        lock.chmod(0o666)
        with self.assertRaises(WITHDRAW.WithdrawFailure) as refused:
            self.withdraw(plan(7, caddy=False, dns=True))
        self.assertEqual(refused.exception.code, "route.publish_lock_invalid")

    def test_a_missing_account_or_a_file_that_is_not_utf8_refuses_by_name(self):
        with patch.object(WITHDRAW.pwd, "getpwnam", side_effect=KeyError("orbit")), self.assertRaises(WITHDRAW.WithdrawFailure) as refused:
            self.withdraw(plan(7, php=True))
        self.assertEqual(refused.exception.code, "php.account_missing")
        self.caddy.write_bytes(b"# Managed by Orbit: Node Caddy build\n\xff\n")
        with self.assertRaises(WITHDRAW.WithdrawFailure) as refused:
            self.withdraw(plan(7))
        self.assertEqual(refused.exception.code, "route.file_not_utf8")

    def test_a_writer_without_the_lock_that_changes_the_caddyfile_meanwhile_is_never_overwritten(self):
        pools = self.resolved_pool(7, 9)
        other = caddyfile(site(7), PRODUCTION, site(8), site(9))

        def run(argv, **kwargs):
            # A whole-file build of another writer lands while the candidate is validated.
            if "validate" in argv:
                self.caddy.write_text(other)
            return Tools()(argv, **kwargs)

        with self.assertRaises(WITHDRAW.WithdrawFailure) as refused:
            WITHDRAW.withdraw(plan(7, php=True), run, root=str(self.root), wait=10)
        self.assertEqual(refused.exception.code, "route.live_changed")
        self.assertEqual((self.caddy.read_text(), self.pool.read_text()), (other, pools))

    def test_a_lock_another_writer_created_becomes_private(self):
        lock = self.root / "etc/caddy/.orbit-publish.lock"
        lock.touch(mode=0o644)
        lock.chmod(0o644)
        self.withdraw(plan(7))
        self.assertEqual(oct(lock.stat().st_mode & 0o777), "0o600")

    def test_a_held_lock_times_out_with_publish_busy(self):
        lock = self.root / "etc/caddy/.orbit-publish.lock"
        descriptor = os.open(lock, os.O_RDWR | os.O_CREAT, 0o600)
        self.addCleanup(os.close, descriptor)
        fcntl.flock(descriptor, fcntl.LOCK_EX)
        live = self.caddy.read_text()
        context = multiprocessing.get_context("fork")
        queue = context.Queue()
        child = context.Process(target=_busy, args=(str(self.root), queue))
        child.start()
        child.join(10)
        self.assertEqual(queue.get(timeout=1), "caddy.publish_busy")
        self.assertEqual(self.caddy.read_text(), live)


def _child(root, instance):
    with patch.object(WITHDRAW.pwd, "getpwnam", return_value=ACCOUNT):
        WITHDRAW.withdraw(plan(instance), Tools(sleep=0.5), root=root, wait=10)


def _busy(root, queue):
    try:
        WITHDRAW.withdraw(plan(7), Tools(), root=root, wait=1)
        queue.put("published")
    except WITHDRAW.WithdrawFailure as error:
        queue.put(error.code)


if __name__ == "__main__":
    unittest.main()
