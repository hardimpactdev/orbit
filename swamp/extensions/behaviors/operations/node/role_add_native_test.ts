import { assert, assertEquals, assertFalse } from "jsr:@std/assert@1.0.19";
import { type Flow, nodeFixture, sshInput } from "../../../../tests/_support/node_host.ts";

async function fixture() {
  const f = await nodeFixture();
  f.repo.timeoutMs = 180_000;
  await f.install("operation-node-role-add");
  const intent = {
    node_id: 701,
    assignment_id: 101,
    role: "database",
    ssh: sshInput(),
    releases: ["resolute"],
    packages: [],
    caddy_package: null,
    bun: null,
    directories: [],
    files: [],
    php_packages: [],
    php: [],
    managers: [],
    certificates: { leaves: [], withdrawals: [] },
    publications: [],
    exporters: [],
    prometheus: null,
    services: [{ name: "docker.service", index: 0 }],
    firewall: { desired: [], remove: [] },
    runtime: {
      directories: [],
      files: [],
      secret_files: [],
      units: [],
      probes: [],
      groups: [],
      users: [],
      websocket: null,
      metrics: null,
      analytics: null,
      gateway_dns: null,
      swamp: null,
      ci_host: false,
    },
  };
  const run = async (success = true, kind = "node.role_add") => {
    await f.repo.cli(["workflow", "validate", "operation-node-role-add"]);
    return await f.repo.runWorkflow("operation-node-role-add", {
      operation_id: 9001,
      node_id: 701,
      kind,
      intent,
      execution_secrets: {},
    }, { success });
  };
  const file = (path: string, content: string) => ({ path, content, owner: f.user, group: f.group, mode: "0644" });
  return { ...f, intent, run, file };
}

Deno.test("role add runs the common runtime and installs prerequisites safely", async () => {
  const f = await fixture();
  await using _ = f.repo;
  f.intent.files.push(f.file(`${f.host}/role.conf`, "managed role\n") as never);
  f.intent.packages.push("fixture-prerequisite" as never);
  const { steps } = await f.run();
  assert(f.state().active);
  assert(f.state().enabled);
  assertEquals(steps["Check the role"], "succeeded");
  assertEquals(steps["Check role files-0"], "succeeded");
  assertEquals(await Deno.readTextFile(`${f.host}/role.conf`), "managed role\n");
  const call = f.state().calls.find((c) => c[0] === "apt-get" && c.includes("install"))!;
  for (const arg of ["--no-remove", "--no-upgrade", "DPkg::Lock::Timeout=60"]) assert(call.includes(arg));
});
for (const [flag, step] of [["FAIL_START", "Start role services-0"], ["DRIFT", "Check the role"]]) {
  Deno.test(`role add fails on ${flag}`, async () => {
    const f = await fixture();
    await using _ = f.repo;
    f.repo.env[flag] = "1";
    const { steps } = await f.run(false);
    assertEquals(steps[step], "failed");
    assertFalse(steps["Check the role"] === "succeeded");
  });
}
Deno.test("healthy Docker CE satisfies the container prerequisite", async () => {
  const f = await fixture();
  await using _ = f.repo;
  f.repo.env.DOCKER_CE = "1";
  f.save({ active: true, enabled: true, calls: [] });
  f.intent.packages.push("docker.io" as never);
  const { steps } = await f.run();
  assertEquals(steps["Check the role"], "succeeded");
  assertEquals(steps["Install prerequisites"], "skipped");
  assertFalse(f.state().calls.some((c) => c.includes("docker.io")));
});
Deno.test("role converge repairs its file without installing the observer", async () => {
  const f = await fixture();
  await using _ = f.repo;
  await Deno.writeTextFile(`${f.host}/role.conf`, "drift\n");
  f.intent.files.push(f.file(`${f.host}/role.conf`, "desired role\n") as never);
  const { steps } = await f.run(true, "node.role_converge");
  assertEquals(await Deno.readTextFile(`${f.host}/role.conf`), "desired role\n");
  assertEquals(steps["Check the role"], "succeeded");
  assertFalse("Install the Node agent" in steps);
});
Deno.test("Check exporter endpoints dispatches on one Node do not overlap", async () => {
  const f = await fixture();
  await using _ = f.repo;
  await f.repo.workflow("operation-node-role-add", (flow) => {
    const job = (flow as unknown as Flow).jobs.find((j) => j.steps.some((s) => s.name === "Check exporter endpoints"))!;
    delete job.dependsOn;
    flow.jobs = [job];
    for (const step of job.steps) {
      if (step.task.behaviorType === "@swamp/ssh") step.task.globalArgs!.sshBinary = `${f.tools}/ssh`;
    }
  });
  await f.ts("curl", "await new Promise((r) => setTimeout(r, 100));");
  Object.assign(f.intent, {
    prometheus: {
      checks: [0, 1].map((index) => ({ node_id: 701, index, ssh: f.intent.ssh, url: `http://fixture/${index}` })),
    },
  });
  const { history } = await f.run();
  const checks = (history.jobs.flatMap((j) =>
    j.steps
  ) as unknown as { status: string; startedAt: string; completedAt: string }[]).sort((a, b) =>
    a.startedAt.localeCompare(b.startedAt)
  );
  assertEquals(checks.length, 2);
  assert(checks.every((s) => s.status === "succeeded"));
  assert(checks[0].completedAt <= checks[1].startedAt);
});
