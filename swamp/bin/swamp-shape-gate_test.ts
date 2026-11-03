import { assert, assertEquals, assertNotEquals, assertStringIncludes } from "jsr:@std/assert@1.0.19";
import { command, gitFixture, ok, ROOT } from "../tests/_support/gate_fixture.ts";
import { PHP_ACTIONS, PHP_ENTRY, PHP_RUNTIME } from "../tests/_support/php_host_tool_fixture.ts";

const deno = Deno.execPath();
const gate = (cwd: string, base = "HEAD", extra: string[] = [], env?: Record<string, string>) =>
  command(
    [
      deno,
      "run",
      "-A",
      `${ROOT}/bin/check-swamp-shape.ts`,
      "--base",
      base,
      "--python-analyzer",
      `${ROOT}/bin/swamp-shape-python.py`,
      ...extra,
    ],
    cwd,
    undefined,
    env,
  );

Deno.test("gate preserves its ratchet and metrics from the Swamp subtree", async () => {
  await using repo = await gitFixture();
  const swamp = `${repo.dir}/swamp`;
  const source = `${swamp}/extensions/behaviors/old.ts`;
  await repo.write(
    "swamp/config/agent-command-kinds.json",
    JSON.stringify({ "agent.status": "observer", "cli.install": "action" }),
  );
  await repo.write(
    "swamp/extensions/behaviors/old.ts",
    "export const behavior={type:'@example/old'};\nawait delay(10);\n",
  );
  await repo.write(
    "swamp/extensions/behaviors/gateway.ts",
    "await fetch('https://gateway.orbit/api/v1/nodes');\nawait fetch('https://gateway.orbit/api/v1/operations');\n",
  );
  const workflow = `${swamp}/workflows/clean.yaml`;
  await repo.write(
    "swamp/workflows/clean.yaml",
    "name: clean\njobs:\n- steps:\n  - task: {type: behavior, behaviorType: command/shell, inputs: {run: 'true'}}\n",
  );
  await repo.commit("baseline source");
  const initial = ok(await gate(swamp, "HEAD", ["--write-baseline"]));
  const metrics = JSON.parse(initial.split("\n")[0]);
  assertEquals(metrics.workflows.clean, 1);
  assertEquals(metrics.gateway_client_files, 1);
  assertEquals(initial.split("\n").filter((line) => line.startsWith("SHAPE-GATE ")), [
    "SHAPE-GATE workflows_passing=1/1 grandfathered=2 embedded_lines=0 custom_loops=1 gateway_client_files=1",
  ]);
  await repo.commit("record explicit baseline");
  ok(await gate(swamp));
  await Deno.writeTextFile(source, await Deno.readTextFile(source) + "await delay(20);\n");
  assertNotEquals((await gate(swamp)).code, 0, "Growing existing debt must fail");
  await Deno.writeTextFile(source, "export const behavior={type:'@example/old'};\n");
  assertNotEquals((await gate(swamp)).code, 0, "Resolved debt must remove its allowance");
  ok(await gate(swamp, "HEAD", ["--write-baseline"]));
  const native =
    "python3 - <<'PY'\nimport fcntl\nimport os\n\nfd = os.open('/run/lock/orbit-firewall.lock', os.O_RDWR)\nfcntl.flock(fd, fcntl.LOCK_EX)\nPY\n";
  await Deno.writeTextFile(
    workflow,
    `name: clean\njobs:\n- steps:\n  - task: {type: behavior, behaviorType: command/shell, inputs: {run: ${
      JSON.stringify(native)
    }}}\n`,
  );
  await repo.git("add", ".");
  ok(await gate(swamp));
  await Deno.writeTextFile(workflow, (await Deno.readTextFile(workflow)).replaceAll("orbit-firewall", "workflow"));
  assertNotEquals((await gate(swamp)).code, 0);
  await Deno.writeTextFile(
    workflow,
    (await Deno.readTextFile(workflow)).replaceAll("workflow.lock", "orbit-firewall.lock"),
  );
  for (
    const [name, content] of [["runtime_common.py", PHP_RUNTIME], ["monitor_common.py", PHP_ACTIONS], [
      "stage.py",
      PHP_ENTRY,
    ]]
  ) {
    await repo.write(`swamp/extensions/behaviors/php/scripts/${name}`, content);
  }
  await repo.git("add", ".");
  ok(await gate(swamp));
  const runtime = `${swamp}/extensions/behaviors/php/scripts/runtime_common.py`;
  await Deno.writeTextFile(runtime, PHP_RUNTIME.replaceAll("P('/run/lock')", "P('/tmp')"));
  assertNotEquals((await gate(swamp)).code, 0);
  await Deno.writeTextFile(runtime, PHP_RUNTIME);
  await Deno.rename(source, source.replace("old.ts", "renamed.ts"));
  await Deno.writeTextFile(source.replace("old.ts", "renamed.ts"), "await delay(10);\n");
  await repo.git("add", ".");
  assertNotEquals((await gate(swamp)).code, 0);
});

Deno.test("initial import requires preserved history and matching repository identity", async () => {
  await using repo = await gitFixture();
  await repo.write(".swamp.yaml", "repoId: fixture-repository\n");
  await repo.write("extensions/behaviors/read.ts", "export const value=1;\nawait delay(10);\n");
  await Deno.mkdir(`${repo.dir}/config`);
  const original = await repo.commit("original Swamp tree");
  await repo.write("config/agent-command-kinds.json", '{"agent.status":"observer"}');
  await repo.write(
    "config/swamp-shape-baseline.json",
    JSON.stringify({
      schema: 1,
      entries: [{ file: "extensions/behaviors/read.ts", rule: "custom-control", count: 1, evidence: ["delay"] }],
    }),
  );
  await Deno.mkdir(`${repo.dir}/swamp`);
  for (const name of ["config", "extensions", ".swamp.yaml"]) {
    await Deno.rename(`${repo.dir}/${name}`, `${repo.dir}/swamp/${name}`);
  }
  await repo.commit("preserve source under swamp");
  for (const event of ["pull_request", "push"]) {
    ok(await gate(`${repo.dir}/swamp`, original, [], { GITHUB_EVENT_NAME: event }));
  }
  await repo.write("swamp/.swamp.yaml", "repoId: unrelated-repository\n");
  assertNotEquals((await gate(`${repo.dir}/swamp`, original)).code, 0);
});

Deno.test("HTTP move import uses the older unprefixed source, not the gate witness", async () => {
  await using repo = await gitFixture();
  const fixture = JSON.parse(await Deno.readTextFile(`${ROOT}/tests/fixtures/shape/proxy-http-source-move.json`));
  const source = "extensions/behaviors/operations/_lib/node-proxy.ts";
  const target = source.replace("node-proxy.ts", "node-proxy-http.ts");
  const caller = "extensions/behaviors/automatic-deploy/gateway.ts";
  for (
    const [path, content] of [[source, fixture.from], [caller, fixture.caller], [
      source.replace("node-proxy.ts", "node-command-process.ts"),
      "export const commandOutput=()=>{};\n",
    ], [".swamp.yaml", "repoId: source-move-fixture\n"]]
  ) await repo.write(path, content);
  const original = await repo.commit("retained unprefixed source predating the gate");
  await repo.write("bin/check-swamp-shape.ts", "export {};\n");
  const gatedBase = await repo.commit("root source carrying a gate");
  await Deno.remove(`${repo.dir}/bin`, { recursive: true });
  await repo.write("config/agent-command-kinds.json", '{"agent.status":"observer"}');
  await repo.git("add", ".");
  ok(await gate(repo.dir, original, ["--write-baseline"]));
  const ledgerBase = await repo.commit("root source carrying a ledger");
  await repo.write(".witness", "immutable code witness\n");
  const witness = await repo.commit("later immutable source witness with derived ledger");
  const swamp = `${repo.dir}/swamp`;
  await Deno.mkdir(swamp);
  for (const name of ["config", "extensions", ".swamp.yaml"]) {
    await Deno.rename(`${repo.dir}/${name}`, `${swamp}/${name}`);
  }
  await Deno.mkdir(`${swamp}/bin`);
  for (
    const name of [
      "check-swamp-shape.ts",
      "bounded-phase-scripts.ts",
      "swamp-shape-relocations.ts",
      "workflow-placement.ts",
      "swamp-shape-python.py",
      "swamp_shape_host_tools.py",
      "swamp_shape_polling.py",
    ]
  ) await Deno.copyFile(`${ROOT}/bin/${name}`, `${swamp}/bin/${name}`);
  const helper = `${swamp}/bin/swamp-shape-relocations.ts`;
  await Deno.writeTextFile(helper, (await Deno.readTextFile(helper)).replaceAll(fixture.origin, witness));
  const imported = await repo.commit("history-preserving import with protected source proof");
  await repo.write(`swamp/${source}`, "export const proxyLaunch=()=>{};\n");
  await repo.write(`swamp/${target}`, fixture.target);
  await repo.write(
    `swamp/${caller}`,
    fixture.caller.replaceAll(
      'import { proxyGatewayRequest, runtimeNodeProxy } from "../operations/_lib/node-proxy.ts";',
      'import { proxyGatewayRequest } from "../operations/_lib/node-proxy-http.ts";\nimport { runtimeNodeProxy } from "../operations/_lib/node-proxy.ts";',
    ),
  );
  const ledger = `${swamp}/config/swamp-shape-baseline.json`;
  const baseline = JSON.parse(await Deno.readTextFile(ledger));
  baseline.entries = baseline.entries.filter((row: { file: string }) => row.file !== source);
  await Deno.writeTextFile(ledger, JSON.stringify(baseline));
  await repo.commit("exact extraction after protected lower");
  const check = (base: string, event = "pull_request") =>
    command([deno, "run", "-A", "bin/check-swamp-shape.ts", "--base", base], swamp, undefined, {
      GITHUB_EVENT_NAME: event,
    });
  for (const [base, event] of [[original, "pull_request"], [original, "push"], [imported, "pull_request"]]) {
    const report = JSON.parse(ok(await check(base, event)).split("\n")[0]);
    assertEquals(report.relocated_allowances.length, 1);
    assertEquals(report.errors, []);
  }
  for (const earlier of [gatedBase, ledgerBase]) {
    assertStringIncludes((await check(earlier)).stderr, "HTTP source move lacks immutable origin ancestry");
  }
  await repo.git("checkout", "--quiet", "-b", "prefixed-before-origin", original);
  await Deno.mkdir(swamp, { recursive: true });
  for (const name of ["extensions", ".swamp.yaml"]) await Deno.rename(`${repo.dir}/${name}`, `${swamp}/${name}`);
  const prefixed = await repo.commit("unrelated prefixed source before witness");
  await repo.git("checkout", "--quiet", "-");
  assertStringIncludes((await check(prefixed)).stderr, "HTTP source move lacks immutable origin ancestry");
  await Deno.writeTextFile(
    `${swamp}/${target}`,
    (await Deno.readTextFile(`${swamp}/${target}`)).replaceAll("timeoutMs + 1000", "timeoutMs + 2000"),
  );
  const failed = await check(original);
  assert(failed.code !== 0);
  assertStringIncludes(failed.stderr, "gateway-http 1 exceeds 0");
});
