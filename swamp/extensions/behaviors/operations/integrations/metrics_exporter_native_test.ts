import { assert, assertEquals, assertFalse } from "jsr:@std/assert@1.0.19";
import {
  DISABLED_CONFIG,
  ENABLED_CONFIG,
  exporterIntent,
  metricsFixture,
  nativeServer,
} from "../../../../tests/_support/metrics_native.ts";

const ENABLE = "operation-metrics-exporter-enable", DISABLE = "operation-metrics-exporter-disable";
Deno.test("exporter firewall dispatches on one Node do not overlap", async () => {
  const f = await metricsFixture();
  await using _ = f.repo;
  const { history } = await f.run("metrics-exporter-allows", exporterIntent());
  const rules =
    (history.jobs.flatMap((j) => j.steps) as unknown as { name: string; startedAt: string; completedAt: string }[])
      .filter((s) => s.name.startsWith("rule-")).sort((a, b) => a.startedAt.localeCompare(b.startedAt));
  assertEquals(rules.length, 2);
  assert(rules[0].completedAt <= rules[1].startedAt);
});
Deno.test("exporter disable unpublishes before removing services, packages and allows", async () => {
  const f = await metricsFixture();
  await using _ = f.repo;
  const intent = exporterIntent();
  intent.enabled = false;
  intent.runtime.configuration = DISABLED_CONFIG;
  intent.service.downloads = [];
  intent.service.checks = [];
  for (const unit of intent.service.units) {
    unit.ensure = "stopped";
    unit.enabled = false;
  }
  for (const value of [...intent.service.rules, ...intent.service.files]) value.ensure = "absent";
  const { history, state, calls } = await f.run(DISABLE, intent);
  assertEquals(history.status, "succeeded");
  assertEquals(state.loaded, DISABLED_CONFIG);
  const names = calls.map((c) => c.step);
  for (const [a, b] of [["reload", "stopped"], ["stopped", "package_removed"], ["package_removed", "rule"]]) {
    assert(names.indexOf(a) < names.indexOf(b));
  }
  assertFalse(names.includes("started"));
  assertFalse(names.includes("package"));
  assertEquals(calls.find((c) => c.step === "package_removed")!.globals.ensure, "absent");
  assert(calls.filter((c) => c.step === "rule").every((c) => c.globals.ensure === "absent"));
});
Deno.test("exporter preference alone has no host effects", async () => {
  const f = await metricsFixture();
  await using _ = f.repo;
  const intent = { ...exporterIntent(), runtime: null, service: null };
  const { history, calls } = await f.run(ENABLE, intent);
  assertEquals(history.status, "succeeded");
  assertEquals(calls, []);
});
Deno.test("exporter fails safely on validate", async () => {
  const f = await metricsFixture();
  await using _ = f.repo;
  const { history, state, calls } = await f.run(ENABLE, exporterIntent(), "validate", false);
  assertEquals(history.status, "failed");
  assertEquals(state.published, "old");
  assertEquals(state.loaded, "old");
  assertFalse(calls.some((c) => c.step === "reload"));
  assert(calls.some((c) => c.step === "remove_candidate"));
});
Deno.test("exporter end check fails when Prometheus does not scrape it", async () => {
  const f = await metricsFixture();
  await using _ = f.repo;
  const intent = exporterIntent();
  intent.runtime.configuration = DISABLED_CONFIG;
  const { history, calls } = await f.run(ENABLE, intent, "", false);
  assertEquals(history.status, "failed");
  assert(calls.some((c) => c.step === "targets"));
});
Deno.test("server-backed exporter resume recovers after publication failure", async () => {
  const f = await metricsFixture();
  await using _ = f.repo;
  await using server = await nativeServer(f.repo);
  const intent = exporterIntent();
  const connection = {
    control_node_id: 209,
    host_keys: intent.ssh_execution.target.host_keys,
    connection: intent.service.units[0],
  };
  Object.assign(intent.service, {
    caddy: {
      node_id: 9,
      user: "orbit",
      ...connection,
      caddy: { content: "public config", version: "a".repeat(64), listen_addresses: ["192.0.2.9"], unserved: false },
    },
    pools: [{ node_id: 9, ...connection, php_runtime: { mode: "monitor" } }],
  });
  intent.runtime.ssh_execution = structuredClone(intent.ssh_execution);
  Object.assign(intent.runtime.ssh_execution.target, { host: "192.0.2.3", user: "metrics", port: 2202 });
  const { history, state } = await f.run(ENABLE, intent, "reload", false, server.flags);
  assertEquals(state.loaded, "old");
  assertEquals(state.published, ENABLED_CONFIG);
  f.save({ ...state, fail: "" });
  const resumed = JSON.parse(
    (await f.repo.cli([
      "workflow",
      "resume",
      ENABLE,
      "--run",
      String(history.id),
      "--from",
      "Publish the config",
      ...server.flags,
    ])).stdout,
  );
  assertEquals(resumed.status, "succeeded");
  const { calls, ...saved } = f.state();
  for (const c of calls.filter((c) => c.type === "@swamp/ssh")) {
    const remote = ["validate", "publish", "reload", "targets"].includes(c.step);
    assertEquals((c.globals.transport as { port: number }).port, remote ? 2202 : 22);
    assertEquals((c.globals.hosts as { address: string }[])[0].address, remote ? "192.0.2.3" : "192.0.2.9");
  }
  assertEquals(saved.loaded, ENABLED_CONFIG);
  const names = calls.map((c) => c.step);
  for (
    const [a, b] of [
      ["started", "Check the endpoints"],
      ["rule", "Check the endpoints"],
      ["Check the endpoints", "validate"],
      ["validate", "publish"],
      ["publish", "reload"],
    ]
  ) assert(names.indexOf(a) < names.indexOf(b));
  assertEquals(calls.find((c) => c.step === "reload")!.args.argv, [
    "/usr/bin/docker",
    "kill",
    "--signal=HUP",
    "orbit-metrics-prometheus",
  ]);
  assertFalse(calls.some((c) => c.type === "@swamp/cfgmgmt/docker_container"));
  assertFalse(names.includes("package_removed"));
  const publish = calls.find((c) => c.step === "publish")!.args.argv;
  assertEquals(publish[0], "/usr/bin/cp");
  assertEquals(publish.at(-1), "/etc/orbit/metrics/prometheus.yml");
  assertEquals(saved.loaded, saved.published);
});
Deno.test("role composition applies only the exporter Node child", async () => {
  const f = await metricsFixture();
  await using _ = f.repo;
  const { calls } = await f.run("metrics-exporter-node", { ...exporterIntent(), runtime: null });
  assert(calls.some((c) => c.step === "started"));
  assertFalse(calls.some((c) => ["candidate", "validate", "publish", "reload", "targets"].includes(c.step)));
});
