import { assert, assertEquals, assertFalse } from "jsr:@std/assert@1.0.19";
import {
  type Call,
  metricsFixture,
  nativeServer,
  roleIntent,
  sha256,
} from "../../../../tests/_support/metrics_native.ts";

const ENABLE = "operation-metrics-enable", DISABLE = "operation-metrics-disable", CONFIG = "metrics-configuration";
const PROMETHEUS = "/etc/orbit/metrics/prometheus.yml";
const ENABLE_STEPS = [
  "Install Docker",
  "Write the configuration",
  "Start the containers",
  "Add the fleet exporters",
  "Allow the fleet scrapes",
  "Sign metrics.orbit cert",
  "Allow the Gateway in",
  "Publish metrics.orbit",
];
const DISABLE_STEPS = [
  "Withdraw metrics.orbit",
  "Remove the containers",
  "Remove the fleet exporters",
  "Remove the fleet allows",
  "Remove the Grafana rules",
  "Remove the configuration",
  "Check it is gone",
];
const OWNER: Record<string, Record<string, string>> = {
  [ENABLE]: {
    "metrics-docker": ENABLE_STEPS[0],
    [CONFIG]: ENABLE_STEPS[1],
    "metrics-containers": ENABLE_STEPS[2],
    "metrics-exporter-node": ENABLE_STEPS[3],
    "metrics-exporter-allows": ENABLE_STEPS[4],
    "node-role-certificate-publish": ENABLE_STEPS[5],
    "operation-route-caddy": ENABLE_STEPS[7],
    "route-dns-publish": ENABLE_STEPS[7],
    "metrics-publication": ENABLE_STEPS[7],
  },
  [DISABLE]: {
    "metrics-withdrawal": DISABLE_STEPS[0],
    "operation-route-caddy": DISABLE_STEPS[0],
    "route-dns-publish": DISABLE_STEPS[0],
    "metrics-containers-remove": DISABLE_STEPS[1],
    "metrics-exporter-node": DISABLE_STEPS[2],
    "metrics-exporter-allows": DISABLE_STEPS[3],
  },
};
const named = (calls: Call[], workflow: string, step?: string) =>
  calls.filter((c) => c.workflow === workflow && (!step || c.step === step));
const chart = (calls: Call[], parent: string) =>
  calls.map((c) => c.workflow === parent ? c.step : OWNER[parent][c.workflow]).filter((v, i, all) =>
    i === 0 || all[i - 1] !== v
  );

Deno.test("metrics enable runs the chart in order on the captured Nodes", async () => {
  const f = await metricsFixture(true);
  await using _ = f.repo;
  const { history, calls } = await f.run(ENABLE, roleIntent());
  assertEquals(history.status, "succeeded");
  assertEquals(chart(calls, ENABLE), ENABLE_STEPS);
  const leaves = named(calls, "node-role-certificate-publish");
  assertEquals(leaves.map((c) => c.args.node_id), [9]);
  for (const c of leaves) assert(("role-cert-work-gone-" + c.args.ssh_name).length <= 64);
  const firewall = named(calls, ENABLE, "Allow the Gateway in")[0];
  assertEquals(firewall.globals.ensure, "present");
  assertEquals(firewall.globals.port, 3000);
  assertEquals(named(calls, "operation-route-caddy").map((c) => c.args.node_id), [2]);
  for (const c of [...named(calls, "metrics-exporter-node"), ...named(calls, "metrics-exporter-allows")]) {
    assertEquals(c.globals.nodeHost ?? "192.0.2.10", "192.0.2.10");
  }
});
Deno.test("metrics enable validates before publishing in place and cleans the candidate", async () => {
  const f = await metricsFixture(true);
  await using _ = f.repo;
  const { calls } = await f.run(ENABLE, roleIntent());
  const index = (step: string) => calls.findIndex((c) => c.workflow === CONFIG && c.step === step);
  assert(index("files") < index("validate"));
  assert(index("validate") < index("publish"));
  assert(index("publish") < index("password"));
  const validate = named(calls, CONFIG, "validate")[0].args.argv;
  assertEquals(validate[0], "/usr/bin/docker");
  assert(validate.includes("/bin/promtool"));
  const candidate = named(calls, CONFIG, "files").find((c) =>
    String(c.globals.path).startsWith("/etc/orbit/metrics/prometheus-")
  )!.globals.path as string;
  assert(validate.includes(candidate + ":/etc/prometheus/prometheus.yml:ro"));
  assertFalse(named(calls, CONFIG, "files").some((c) => c.globals.path === PROMETHEUS));
  assertEquals(named(calls, CONFIG, "publish")[0].args.argv, [
    "/usr/bin/cp",
    "--no-preserve=all",
    candidate,
    PROMETHEUS,
  ]);
  assertEquals(named(calls, CONFIG, "remove_candidate").map((c) => [c.globals.path, c.globals.ensure]), [[
    candidate,
    "absent",
  ]]);
});
Deno.test("metrics enable delivers its password only on stdin", async () => {
  const f = await metricsFixture(true);
  await using _ = f.repo;
  const { calls } = await f.run(ENABLE, roleIntent());
  const password = named(calls, CONFIG, "password");
  assertEquals(password.length, 1);
  assertEquals(password[0].args.argv, [
    "/usr/bin/install",
    "-m",
    "0400",
    "-o",
    "472",
    "-g",
    "472",
    "/dev/stdin",
    "/etc/orbit/metrics/grafana/admin-password",
  ]);
  assertEquals(password[0].stdinSha256, await sha256(f.secret));
  assertEquals(calls.filter((c) => c.stdinSha256 !== null).map((c) => c.step), ["password"]);
  assertFalse(JSON.stringify(calls).includes(f.secret));
});
Deno.test("metrics enable waits use one bounded command per check", async () => {
  const f = await metricsFixture(true);
  await using _ = f.repo;
  const { calls } = await f.run(ENABLE, roleIntent());
  for (
    const [workflow, step] of [["metrics-containers", "prometheus_ready"], ["metrics-containers", "grafana_login"], [
      "metrics-publication",
      "answers",
    ]]
  ) {
    const waits = named(calls, workflow, step);
    assertEquals(waits.length, 1);
    assertEquals(waits[0].args.argv.slice(0, 4), ["timeout", "60", "sh", "-c"]);
    assertEquals(waits[0].args.timeoutSec, 70);
  }
  assertEquals(named(calls, "metrics-publication", "answers")[0].args.sudo, true);
  const containers = named(calls, "metrics-containers");
  assert(
    Math.max(...containers.flatMap((c, i) => c.step === "started" ? [i] : [])) <
      containers.findIndex((c) => ["prometheus_ready", "grafana_login"].includes(c.step)),
  );
});
Deno.test("failed promtool validation stops publish and removes the candidate", async () => {
  const f = await metricsFixture(true);
  await using _ = f.repo;
  const { history, calls } = await f.run(ENABLE, roleIntent(), [CONFIG + ":validate"], false);
  assertEquals(history.status, "failed");
  const steps = named(calls, CONFIG).map((c) => c.step);
  assert(steps.includes("validate"));
  assert(steps.includes("remove_candidate"));
  assertFalse(steps.includes("publish"));
  assertFalse(steps.includes("password"));
  assertEquals(chart(calls, ENABLE), ENABLE_STEPS.slice(0, 2));
  for (
    const name of [
      "metrics-containers",
      "metrics-publication",
      "operation-route-caddy",
      "route-dns-publish",
      "node-role-certificate-publish",
    ]
  ) assertEquals(named(calls, name), []);
});
Deno.test("metrics disable withdraws first and keeps volumes without purge", async () => {
  const f = await metricsFixture(true);
  await using _ = f.repo;
  const { history, calls } = await f.run(DISABLE, roleIntent(false));
  assertEquals(history.status, "succeeded");
  assertEquals(chart(calls, DISABLE), DISABLE_STEPS);
  assertEquals(named(calls, DISABLE, "Delete the volumes"), []);
  assertEquals(calls.filter((c) => OWNER[DISABLE][c.workflow] === DISABLE_STEPS[0]).map((c) => c.workflow), [
    "operation-route-caddy",
    "route-dns-publish",
    "metrics-withdrawal",
  ]);
  assertEquals(named(calls, DISABLE, "Remove the Grafana rules")[0].globals.ensure, "absent");
  const config = named(calls, DISABLE, "Remove the configuration")[0];
  assertEquals([config.globals.path, config.globals.ensure], ["/etc/orbit/metrics", "absent"]);
  const containers = named(calls, "metrics-containers-remove", "container");
  assertEquals(containers.map((c) => c.globals.name).sort(), ["orbit-metrics-grafana", "orbit-metrics-prometheus"]);
  assert(containers.every((c) => c.globals.ensure === "absent"));
  assertFalse(calls.some((c) => c.stdinSha256));
  assertEquals(named(calls, CONFIG), []);
});
Deno.test("metrics disable with purge removes each volume before checking", async () => {
  const f = await metricsFixture(true);
  await using _ = f.repo;
  const { history, calls } = await f.run(DISABLE, roleIntent(false, true));
  assertEquals(history.status, "succeeded");
  assertEquals(chart(calls, DISABLE), [...DISABLE_STEPS.slice(0, -1), "Delete the volumes", "Check it is gone"]);
  assertEquals(named(calls, DISABLE, "Delete the volumes").map((c) => c.args.argv).sort(), [
    ["/usr/bin/docker", "volume", "rm", "orbit-metrics-grafana-data"],
    ["/usr/bin/docker", "volume", "rm", "orbit-metrics-prometheus-data"],
  ]);
});
Deno.test("metrics disable fails when its end check finds remaining metrics", async () => {
  const f = await metricsFixture(true);
  await using _ = f.repo;
  const { history, calls } = await f.run(DISABLE, roleIntent(false), [DISABLE + ":Check it is gone"], false);
  assertEquals(history.status, "failed");
  assertEquals(chart(calls, DISABLE), DISABLE_STEPS);
});
Deno.test("server-backed metrics resume does not deliver the password twice", async () => {
  const f = await metricsFixture(true);
  await using _ = f.repo;
  await using server = await nativeServer(f.repo);
  const { history } = await f.run(ENABLE, roleIntent(), ["metrics-containers:grafana_login"], false, server.flags);
  assertEquals(history.status, "failed");
  f.save({ fail: [] });
  const resumed = JSON.parse(
    (await f.repo.cli([
      "workflow",
      "resume",
      ENABLE,
      "--run",
      String(history.id),
      "--from",
      "Start the containers",
      ...server.flags,
    ])).stdout,
  );
  assertEquals(resumed.status, "succeeded");
  const calls = f.recorded();
  assertEquals(named(calls, CONFIG, "password").length, 1);
  assertEquals(named(calls, "metrics-containers", "grafana_login").length, 2);
  assertEquals(named(calls, "metrics-publication", "answers").length, 1);
});
