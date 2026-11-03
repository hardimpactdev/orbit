/**
 * task_vm.hub_converge against a fake hub behind official SSH; never contacts a fleet Node.
 *
 * The fake `ssh` runs nothing: it logs each argv and stdin and answers the nft listing from the expected rule
 * lines, so the test sees the exact commands, their order and the end check's verdict.
 */
import { assert, assertEquals, assertNotEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { nativeRepo, ROOT } from "../../../../tests/_support/native_harness.ts";
import { readCalls } from "../../../../tests/_support/fake_tool.ts";

const tool = new URL("../../../../tests/_support/fake_tool.ts", import.meta.url).href;
const Name = "operation-task-vm-hub-converge";
const Range = "10.44.0.128/25";
const Routers = ["10.44.0.7", "10.44.0.11"];
const routerComment = (router: string, port: number) => `orbit:task-vm-router-web-${router.replaceAll(".", "-")}-${port}`;
const rule = (source: string, port: number, comment: string) =>
  `ip saddr ${source} ip daddr ${Range} tcp dport ${port} accept comment "${comment}"`;
const Control = rule("10.44.0.15", 22, "orbit:task-vm-control-ssh");
const RouterPorts = Routers.flatMap((router) => [80, 443].map((port) => [router, port] as const));
const Lines = [Control, ...RouterPorts.map(([router, port]) => rule(router, port, routerComment(router, port)))];
const Ufw = ["ufw", "route", "allow", "in", "on", "orbit", "out", "on", "orbit", "proto", "tcp"];

const fake = (calls: string, failure: string) => `
import { logCall, readStdin, shellSplit } from ${JSON.stringify(tool)};
if (Deno.args.includes("-M") || Deno.args.includes("-O")) Deno.exit(0);
let args = shellSplit(Deno.args.at(-1));
const body = await readStdin();
if (args.slice(0, 3).join(" ") !== "sudo -n --") throw new Error(args.join(" "));
args = args.slice(3);
if (args.slice(0, 2).join(" ") !== "timeout 60") throw new Error(args.join(" "));
const rest = args.slice(2);
const action = rest.join(" ") === "/usr/sbin/nft --check --file -" ? "check"
  : rest.slice(0, 2).join(" ") === "sh -c" ? "load" : rest[0] === "ufw" ? "ufw" : "unknown";
logCall(${JSON.stringify(calls)}, { action, argv: args, body });
let failure = "";
try { failure = Deno.readTextFileSync(${JSON.stringify(failure)}); } catch { /* none */ }
if (action === "unknown" || failure === action || (failure === "router-web" && action === "ufw" && args.includes("443"))) Deno.exit(1);
if (action === "load") {
  const lines = ${JSON.stringify(Lines)};
  if (failure === "missing-router") lines.pop();
  if (failure === "narrowed") lines[0] = 'iifname "eth9" ' + lines[0];
  if (failure === "other-source") lines[0] = lines[0].replace("10.44.0.15", "10.44.0.16");
  console.log("table inet orbit_task_vms {\\n\\tchain forward {\\n\\t\\ttype filter hook forward priority filter - 5; policy accept;");
  for (const line of lines) console.log("\\t\\t" + line);
  console.log("\\t\\tip saddr ${Range} drop\\n\\t}\\n}");
}
`;

type Call = { action: string; argv: string[]; body: string };

async function fixture() {
  const repo = await nativeRepo();
  repo.timeoutMs = 120_000;
  await repo.pull("@swamp/ssh");
  const calls = `${repo.dir}/calls.jsonl`;
  const failure = `${repo.dir}/failure`;
  const ssh = await repo.fakeBinary("ssh", fake(calls, failure));
  await Deno.writeTextFile(`${repo.dir}/identity`, "fixture SSH identity");
  await repo.workflow(Name, (workflow) => {
    for (const step of workflow.jobs[0].steps) {
      assertEquals("target" in step, false);
      const task = step.task as { behaviorType?: string; globalArgs?: Record<string, unknown> };
      if (task.behaviorType === "@swamp/ssh") task.globalArgs!.sshBinary = ssh;
    }
  });
  await repo.cli(["workflow", "validate", Name]);
  const intent = {
    node_id: 1,
    source: "10.44.0.15",
    range: Range,
    policy: { routers: Routers },
    ssh: {
      identity_file: `${repo.dir}/identity`,
      target: {
        host: "fixture.invalid",
        user: "orbit",
        port: 22,
        host_keys: ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG"],
      },
    },
    nft: "# Managed by Orbit (task VMs).\ntable inet orbit_task_vms {}\n",
    unit: "[Unit]\nDescription=Orbit task VM filter on the WireGuard hub\n",
    live_rules: Lines,
    control_rule: [...Ufw, "from", "10.44.0.15", "to", Range, "port", "22", "comment", "orbit:task-vm-control-ssh"],
    router_rules: RouterPorts.map(([router, port]) => [
      ...Ufw, "from", router, "to", Range, "port", String(port), "comment", routerComment(router, port),
    ]),
  };
  return {
    repo,
    intent,
    async run(fault?: string) {
      await Deno.remove(calls).catch(() => {});
      if (fault) await Deno.writeTextFile(failure, fault);
      const inputs = { operation_id: 7, node_id: 1, kind: "task_vm.hub_converge", execution_secrets: {}, intent };
      const result = await repo.runWorkflow(Name, inputs, { success: fault === undefined });
      return { ...result, calls: readCalls<Call>(calls) };
    },
    [Symbol.asyncDispose]: () => repo[Symbol.asyncDispose](),
  };
}

Deno.test("success checks, writes, loads, then opens UFW and asserts the exact rules", async () => {
  await using test = await fixture();
  const { run, steps, calls } = await test.run();
  assertEquals(run.status, "succeeded");
  assertEquals(steps["Confirm the filter"], "succeeded");
  assertEquals(calls.map((call) => call.action), ["check", "load", "ufw", "ufw", "ufw", "ufw", "ufw"]);
  assertEquals(calls[0].body, test.intent.nft);
  // Rule 6: both files, daemon-reload, enable and restart are one argv; the file on stdin, the unit as $1.
  const load = calls[1];
  assertEquals(load.body, test.intent.nft);
  assertEquals(load.argv.slice(-2), ["sh", test.intent.unit]);
  for (
    const part of [
      "/dev/stdin /etc/orbit/task-vms/hub.nft",
      "/etc/systemd/system/orbit-task-vms-hub.service",
      "systemctl daemon-reload",
      "systemctl enable orbit-task-vms-hub.service",
      "systemctl restart orbit-task-vms-hub.service",
      "nft list chain inet orbit_task_vms forward",
    ]
  ) assert(load.argv[4].includes(part), part);
  // Rule 8: the UFW rules that make the range reachable come after the filter loads; each one exactly once.
  const sorted = (rules: string[][]) => rules.map((rule) => JSON.stringify(rule)).sort();
  assertEquals(
    sorted(calls.slice(2).map((call) => call.argv.slice(2))),
    sorted([test.intent.control_rule, ...test.intent.router_rules]),
  );
  assert(!calls.some((call) => /swamp|python3/.test(JSON.stringify(call.argv))));
});

Deno.test("UFW rules run one at a time under names by hub", () => {
  const workflow = parse(Deno.readTextFileSync(`${ROOT}/workflows/workflow-${Name}.yaml`)) as {
    jobs: { steps: { name: string; labels: unknown; concurrency?: number; task: { type: string; behaviorName?: string } }[] }[];
  };
  const steps = workflow.jobs[0].steps;
  for (const step of steps) assertEquals(step.labels, { role: "swamp" });
  assertEquals(steps.find((step) => step.name === "Allow 209 SSH, Router web")!.concurrency, 1);
  const names = steps.filter((step) => step.task.type === "behavior").map((step) => step.task.behaviorName!);
  assert(names.every((name) => name.includes("inputs.intent.node_id") && !/run\.id|operation_id/.test(name)));
  assertEquals(new Set(names).size, names.length);
});

Deno.test("a refused ruleset changes nothing", async () => {
  await using test = await fixture();
  const { run, calls } = await test.run("check");
  assertEquals(run.status, "failed");
  assertEquals(calls.map((call) => call.action), ["check"]);
});

Deno.test("a failed load opens no UFW rule", async () => {
  await using test = await fixture();
  const { run, steps, calls } = await test.run("load");
  assertEquals(run.status, "failed");
  assertEquals(calls.map((call) => call.action), ["check", "load"]);
  assertNotEquals(steps["Confirm the filter"], "succeeded");
});

Deno.test("only the exact live rules pass the end check", async (t) => {
  await using test = await fixture();
  for (const failure of ["missing-router", "narrowed", "other-source", "router-web"]) {
    await t.step(failure, async () => {
      const { run, steps } = await test.run(failure);
      assertEquals(run.status, "failed");
      assertNotEquals(steps["Confirm the filter"], "succeeded");
      // The listing differs in one rule only: the native assert itself refuses it.
      if (failure !== "router-web") assertEquals(steps["Confirm the filter"], "failed");
    });
  }
});
