import { assert, assertEquals, assertFalse } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { nativeRepo, ROOT } from "../../../../tests/_support/native_harness.ts";
import { readCalls, shellJoin } from "../../../../tests/_support/fake_tool.ts";
import { type Flow, sha256 } from "../../../../tests/_support/node_host.ts";

const NAME = "operation-node-role-relocate";
const SECRET = "APP_KEY=base64:relocate-fixture-private-value\nREVERB_REGISTRY_TOKEN=relocate-fixture-private-token\n";
type Role = "websocket" | "gateway" | "metrics";
const PIN = "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAINqVuK+N4gL/sDvGNKnGrYhEtEFnZx9hvH+134PsQgND";
const WEBSOCKET = {
  user: "orbit",
  install_path: "/opt/orbit/websocket",
  releases: "/opt/orbit/websocket.releases",
  release: "/opt/orbit/websocket.releases/release." + "a".repeat(32),
  repository: "https://github.com/hardimpactdev/orbit-reverb.git",
  ref: "f15545c04a30ef26cf3a11dd675668ced217f394",
  port: 8790,
  unit: { path: "/etc/systemd/system/orbit-websocket.service", content: "[Unit]\nDescription=Orbit websocket role\n" },
};
function roleIntent(role: Role) {
  return {
    schema_version: 1,
    role,
    assignment_id: 5,
    target_node_id: 12,
    source_node_id: 7,
    ssh: {
      identity_file: "/fixture/key",
      target: { name: "target", host: "fixture.invalid", user: "orbit", port: 22, host_keys: [PIN] },
    },
    metrics: role === "metrics" ? { enabled: true } : null,
    websocket: role === "websocket" ? WEBSOCKET : null,
    gateway: role === "gateway"
      ? {
        drop_in: {
          path: "/etc/systemd/system/wg-quick@orbit.service.d/orbit-gateway-dns.conf",
          content: "# Managed by Orbit.\n[Service]\n",
        },
        route: [["resolvectl", "domain", "orbit", "~orbit"], ["resolvectl", "default-route", "orbit", "false"], [
          "resolvectl",
          "dns",
          "orbit",
          "10.44.0.1",
        ]],
      }
      : null,
    certificates: {
      leaves: role === "websocket"
        ? [{ node_id: 12, scope: "role-websocket", ssh: { name: "relocate-certificate-12" } }]
        : [],
      withdrawals: [],
    },
    firewall: {
      desired: role === "metrics" ? [] : [[
        "ufw",
        "allow",
        "in",
        "on",
        "orbit",
        "to",
        "any",
        "port",
        "443",
        "proto",
        "tcp",
        "comment",
        `orbit:${role}-https`,
      ]],
      remove: role === "metrics" ? [] : [["ufw", "--force", "delete", "allow", "22/tcp"]],
    },
    publications: role === "metrics" ? [] : [{
      node_id: 12,
      control_node_id: 209,
      host_keys: [PIN],
      connection: { nodeUser: "orbit" },
      before: { caddy: null, php: null, dns: { records: [] } },
      after: { caddy: { content: "site" }, php: null, dns: { records: ["reverb.orbit"] } },
    }],
    grace: role === "websocket" ? [{ seconds: 31 }] : [],
    metrics_converge: null,
    source: { node_id: 7, ssh: { target: { host: "192.0.2.7" } } },
  };
}
const CHILDREN = [
  "operation-metrics-enable",
  "node-role-certificate-publish",
  "operation-route-caddy",
  "route-dns-publish",
  "route-dns-grace",
  "operation-node-role-remove-direct",
];
const tool = new URL("../../../../tests/_support/fake_tool.ts", import.meta.url).href;
type Call = { argv: string[]; sudo: boolean; stdin: string | null };
async function fixture(role: Role, failure = "", failedChild = "") {
  const repo = await nativeRepo();
  repo.timeoutMs = 180_000;
  await repo.pull("@swamp/ssh");
  const calls = `${repo.dir}/calls.jsonl`, children = `${repo.dir}/children`, targets = `${repo.dir}/targets`;
  const ssh = await repo.fakeBinary(
    "ssh",
    `
import {shellSplit, readStdinBytes, logCall} from ${JSON.stringify(tool)};
if (Deno.args.includes("-M") || Deno.args.includes("-O")) Deno.exit(0);
let argv = shellSplit(Deno.args.at(-1)!); const body = await readStdinBytes();
const sudo = argv.slice(0,3).join(" ") === "sudo -n --"; if (sudo) argv = argv.slice(3);
if (argv[0] !== "timeout") throw new Error("Missing remote timeout");
const stdin = body.length ? Array.from(new Uint8Array(await crypto.subtle.digest("SHA-256", body)), (b) => b.toString(16).padStart(2,"0")).join("") : null;
logCall(${JSON.stringify(calls)}, {argv: argv.slice(2), sudo, stdin});
if (${JSON.stringify(failure)} && argv.slice(2).includes(${JSON.stringify(failure)})) Deno.exit(1);
if (argv[2] === "curl") Deno.stdout.writeSync(new TextEncoder().encode(${
      JSON.stringify(failure)
    } === "silent" ? "000" : "426"));
`,
  );
  await repo.workflow(NAME, (flow) => {
    for (const step of (flow as unknown as Flow).jobs.flatMap((j) => j.steps)) {
      if (step.task.behaviorType === "@swamp/ssh") step.task.globalArgs!.sshBinary = ssh;
    }
  });
  for (const child of CHILDREN) {
    let command = shellJoin(["printf", "%s\n", child]) + " >> " + shellJoin([children]);
    if (child === "operation-metrics-enable" || child === "operation-node-role-remove-direct") {
      const record = child === "operation-metrics-enable"
        ? "${{ string(inputs.node_id) }}"
        : "${{ string(inputs.node_id) }} ${{ string(inputs.intent.node_id) }} ${{ inputs.intent.ssh.target.host }}";
      command = shellJoin(["printf", "%s\n", child + " " + record]) + " >> " + shellJoin([targets]) + " && " + command;
    }
    await repo.stubWorkflow(child, command + (child === failedChild ? " && false" : ""));
  }
  await repo.cli(["vault", "create", "local_encryption", "orbit-control-inputs"]);
  await repo.cli(["vault", "put", "orbit-control-inputs", "node-role-relocate-operation-12"], { input: SECRET });
  repo.secrets = [SECRET.trim().split("\n")[0], "relocate-fixture-private-token"];
  const intent = roleIntent(role);
  const run = async (success = true) => {
    await repo.cli(["workflow", "validate", NAME]);
    const result = await repo.runWorkflow(NAME, {
      operation_id: 12,
      node_id: 12,
      kind: "node.role_relocate",
      intent,
      execution_secrets: role === "websocket"
        ? { websocket_env: { vault: "orbit-control-inputs", key: "node-role-relocate-operation-12" } }
        : {},
    }, { success });
    let childCalls: string[] = [];
    try {
      childCalls = (await Deno.readTextFile(children)).trim().split("\n");
    } catch (e) {
      if (!(e instanceof Deno.errors.NotFound)) throw e;
    }
    return { ...result, calls: readCalls<Call>(calls), children: childCalls };
  };
  return { repo, intent, run, targets };
}
Deno.test("websocket release is built on its target before anything leaves the source", async () => {
  const f = await fixture("websocket");
  await using _ = f.repo;
  const { run, steps, calls, children } = await f.run();
  assertEquals(run.status, "succeeded");
  assertEquals(steps["Confirm the move"], "succeeded");
  assertEquals(steps["Apply the Metrics role"], "skipped");
  const ws = WEBSOCKET;
  assertEquals(calls.map((c) => c.argv.slice(0, 2)), [
    ["install", "-d"],
    ["git", "clone"],
    ["git", "-C"],
    ["composer", "install"],
    ["install", "-m"],
    ["install", "-m"],
    ["ln", "-sfnT"],
    ["systemctl", "daemon-reload"],
    ["systemctl", "enable"],
    ["systemctl", "restart"],
    ["ufw", "allow"],
    ["ufw", "--force"],
    ["curl", "--silent"],
  ]);
  assertEquals(calls[1].argv.at(-1), ws.release);
  assertEquals(calls[2].argv.at(-1), ws.ref);
  assertEquals(calls.slice(0, 7).map((c) => c.sudo), [true, false, false, false, false, true, true]);
  assertEquals(calls[4].argv.at(-1), ws.release + "/.env");
  assertEquals(calls[4].stdin, await sha256(SECRET));
  assertEquals(calls[5].stdin, await sha256(ws.unit.content));
  assertEquals(calls[6].argv.slice(-2), [ws.release, "/opt/orbit/websocket"]);
  assertEquals(children, CHILDREN.slice(1));
});
Deno.test("a failed release leaves the source serving", async () => {
  const f = await fixture("websocket", "composer");
  await using _ = f.repo;
  const { run, steps, calls, children } = await f.run(false);
  assertEquals(run.status, "failed");
  assertEquals(Object.entries(steps).filter(([k]) => k.startsWith("Install Reverb packages")).map(([, v]) => v), [
    "failed",
  ]);
  assertEquals(calls.at(-1)!.argv[0], "composer");
  assertFalse(children.includes(CHILDREN.at(-1)!));
  assertFalse(steps["Confirm the move"] === "succeeded");
});
Deno.test("a silent Reverb fails the move end check", async () => {
  const f = await fixture("websocket", "silent");
  await using _ = f.repo;
  const { run, steps, children } = await f.run(false);
  assertEquals(run.status, "failed");
  assert(children.includes(CHILDREN.at(-1)!));
  assertEquals(steps["Confirm the move"], "failed");
});
Deno.test("gateway routes the private domain and treats a failed resolver as best effort", async () => {
  const f = await fixture("gateway", "default-route");
  await using _ = f.repo;
  const { run, steps, calls, children } = await f.run();
  assertEquals(run.status, "succeeded");
  assertEquals(steps["Confirm the move"], "succeeded");
  assertEquals(calls[0].argv.slice(0, 4), ["install", "-D", "-m", "0644"]);
  assertEquals(calls[1].argv, ["systemctl", "daemon-reload"]);
  assertEquals(calls.slice(2, 5).map((c) => c.argv[1]), ["domain", "default-route", "dns"]);
  assert(Object.entries(steps).some(([k, v]) => k.startsWith("Route the private domain") && v === "failed"));
  assertFalse(children.includes("route-dns-grace"));
  assertEquals(children.at(-1), CHILDREN.at(-1));
});
Deno.test("metrics relocation uses its child workflow and removes only the source", async () => {
  const f = await fixture("metrics");
  await using _ = f.repo;
  const { run, calls, children } = await f.run();
  assertEquals(run.status, "succeeded");
  assertEquals(calls, []);
  assertEquals(children, [CHILDREN[0], CHILDREN.at(-1)]);
  assertEquals((await Deno.readTextFile(f.targets)).trim().split("\n"), [
    "operation-metrics-enable 12",
    "operation-node-role-remove-direct 7 7 192.0.2.7",
  ]);
});
Deno.test("failed source removal fails the role move", async () => {
  const f = await fixture("metrics", "", CHILDREN.at(-1));
  await using _ = f.repo;
  const { run, steps } = await f.run(false);
  assertEquals(run.status, "failed");
  assertEquals(steps["Remove from the source"], "failed");
  assertFalse(steps["Confirm the move"] === "succeeded");
});
Deno.test("relocation behaviors name their target and every step runs on swamp", async () => {
  const flow = parse(await Deno.readTextFile(`${ROOT}/workflows/workflow-${NAME}.yaml`)) as Flow;
  const names: string[] = [];
  for (const step of flow.jobs.flatMap((j) => j.steps)) {
    assertEquals(step.labels, { role: "swamp" });
    assertFalse("target" in step);
    if (step.task.behaviorType === "@swamp/ssh") {
      names.push(step.task.behaviorName!);
      assert(step.writes || step.name === "Check Reverb");
    }
  }
  assert(
    names.every((s) =>
      s.includes("inputs.intent.target_node_id") && !s.includes("run.id") && !s.includes("operation_id")
    ),
  );
  assertEquals(new Set(names).size, names.length);
});
