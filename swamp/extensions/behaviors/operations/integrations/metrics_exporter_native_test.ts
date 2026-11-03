import { assert, assertEquals, assertFalse } from "jsr:@std/assert@1.0.19";
import { ROOT } from "../../../../tests/_support/native_harness.ts";
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
Deno.test("exporter enable installs, allows, checks and reloads without restarting", async () => {
  const f = await metricsFixture();
  await using _ = f.repo;
  const { history, state, calls } = await f.run(ENABLE, exporterIntent());
  assertEquals(history.status, "succeeded");
  assertEquals(state.loaded, ENABLED_CONFIG);
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
for (const fail of ["validate", "rule", "reload"]) {
  Deno.test(`exporter fails safely on ${fail}`, async () => {
    const f = await metricsFixture();
    await using _ = f.repo;
    const { history, state, calls } = await f.run(ENABLE, exporterIntent(), fail, false);
    assertEquals(history.status, "failed");
    assertEquals(state.published, fail === "reload" ? ENABLED_CONFIG : "old");
    if (fail !== "rule") assertEquals(state.loaded, "old");
    if (fail === "validate") {
      assertFalse(calls.some((c) => c.step === "reload"));
      assert(calls.some((c) => c.step === "remove_candidate"));
    }
  });
}
Deno.test("exporter end check fails when Prometheus does not scrape it", async () => {
  const f = await metricsFixture();
  await using _ = f.repo;
  const intent = exporterIntent();
  intent.runtime.configuration = DISABLED_CONFIG;
  const { history, calls } = await f.run(ENABLE, intent, "", false);
  assertEquals(history.status, "failed");
  assert(calls.some((c) => c.step === "targets"));
});
Deno.test("exporter children accept the canonical public service intent", async () => {
  const f = await metricsFixture();
  await using _ = f.repo;
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
  assertEquals((await f.run(ENABLE, intent)).history.status, "succeeded");
});
Deno.test("official firewall schema rejects an invalid port before its effect", async () => {
  const f = await metricsFixture();
  await using _ = f.repo;
  const intent = exporterIntent();
  intent.service.rules = intent.service.rules.slice(0, 1);
  intent.service.rules[0].port = 0;
  const { history, state, calls } = await f.run(ENABLE, intent, "", false);
  assertEquals(history.status, "failed");
  assertEquals(state.published, "old");
  assertFalse(calls.some((c) => c.step === "rule"));
});
Deno.test("metrics owner and exporter keep their captured SSH ports", async () => {
  const f = await metricsFixture();
  await using _ = f.repo;
  const intent = exporterIntent();
  intent.runtime.ssh_execution = structuredClone(intent.ssh_execution);
  Object.assign(intent.runtime.ssh_execution.target, { host: "192.0.2.3", user: "metrics", port: 2202 });
  const { calls } = await f.run(ENABLE, intent);
  for (const c of calls.filter((c) => c.type === "@swamp/ssh")) {
    const remote = ["validate", "publish", "reload", "targets"].includes(c.step);
    assertEquals((c.globals.transport as { port: number }).port, remote ? 2202 : 22);
    assertEquals((c.globals.hosts as { address: string }[])[0].address, remote ? "192.0.2.3" : "192.0.2.9");
  }
});
Deno.test("exporter workflows stay within their requested line budget", async () => {
  for (
    const name of [ENABLE, DISABLE, "metrics-exporter-node", "metrics-exporter-allows", "metrics-prometheus-publish"]
  ) {
    assert(
      (await Deno.readTextFile(`${ROOT}/workflows/workflow-${name}.yaml`)).trimEnd().split("\n").length <= 200,
      name,
    );
  }
  let removed = false;
  try {
    await Deno.stat(`${ROOT}/workflows/workflow-operation-metrics-exporter.yaml`);
  } catch (e) {
    if (e instanceof Deno.errors.NotFound) removed = true;
    else throw e;
  }
  assert(removed);
});
Deno.test("server-backed exporter resume recovers after publication failure", async () => {
  const f = await metricsFixture();
  await using _ = f.repo;
  await using server = await nativeServer(f.repo);
  const { history, state } = await f.run(ENABLE, exporterIntent(), "reload", false, server.flags);
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
  assertEquals(f.state().loaded, f.state().published);
});
Deno.test("role composition applies only the exporter Node child", async () => {
  const f = await metricsFixture();
  await using _ = f.repo;
  const { calls } = await f.run("metrics-exporter-node", { ...exporterIntent(), runtime: null });
  assert(calls.some((c) => c.step === "started"));
  assertFalse(calls.some((c) => ["candidate", "validate", "publish", "reload", "targets"].includes(c.step)));
});
