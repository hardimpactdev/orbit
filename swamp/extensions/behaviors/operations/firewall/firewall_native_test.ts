/** operation-firewall-direct against a ufw double behind official SSH; never contacts a Node. */
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { nativeRepo, ROOT } from "../../../../tests/_support/native_harness.ts";
import type { Row } from "./plan.ts";
import { Trust, Trust6 } from "./native_fixture.ts";

const double = new URL("./native_fixture.ts", import.meta.url).href;
const tool = new URL("../../../../tests/_support/fake_tool.ts", import.meta.url).href;

async function fixture(rows: Row[] = [Trust, Trust6], active = true) {
  const repo = await nativeRepo();
  await repo.copyBehaviors("operations/firewall");
  await repo.pull("@swamp/ssh");
  const tracked = parse(await Deno.readTextFile(`${ROOT}/workflows/workflow-operation-firewall-direct.yaml`)) as {
    jobs: { steps: { labels: unknown }[] }[];
  };
  for (const step of tracked.jobs[0].steps) assertEquals(step.labels, { role: "swamp" });
  const state = `${repo.dir}/state.json`;
  await Deno.writeTextFile(state, JSON.stringify({ rows, active, fail: false }));
  const ssh = await repo.fakeBinary(
    "ssh",
    `import { UfwDouble } from ${JSON.stringify(double)};
import { logCall, shellSplit } from ${JSON.stringify(tool)};
if (Deno.args.some((arg) => ["-O", "-N", "-MNf", "-M"].includes(arg))) Deno.exit(0);
let command = Deno.args.at(-1);
if (command.includes("__swamp_sudo_ok__")) {
  console.log("__swamp_sudo_ok__");
  command = shellSplit(command).at(-1).split("exec </dev/null; ").at(-1);
}
let argv = shellSplit(command);
if (argv.slice(0, 3).join(" ") === "sudo -n --") argv = argv.slice(3);
logCall(${JSON.stringify(`${repo.dir}/calls.jsonl`)}, argv);
const saved = JSON.parse(Deno.readTextFileSync(${JSON.stringify(state)}));
const ufw = Object.assign(new UfwDouble(saved.rows), { active: saved.active, fail: saved.fail });
if (argv.join(" ") === "timeout 30 /usr/sbin/ufw status numbered") console.log(ufw.status());
else {
  try { ufw.apply(argv); } catch (error) { console.error("ERROR: " + error.message); Deno.exit(1); }
  Deno.writeTextFileSync(${JSON.stringify(state)}, JSON.stringify({ ...saved, rows: ufw.rows }));
}
`,
  );
  await repo.workflow("operation-firewall-direct", (flow) => {
    for (const step of flow.jobs.flatMap((job) => job.steps)) {
      const task = step.task as { behaviorType?: string; globalArgs: Record<string, unknown> };
      if (task.behaviorType === "@swamp/ssh") task.globalArgs.sshBinary = ssh;
    }
  });
  const run = async (kind: string, rule: Record<string, unknown>, ensure = "present", success = true) => {
    const intent = {
      ssh: {
        identity_file: "/fixture/key",
        target: {
          host: "10.44.0.9",
          user: "orbit",
          port: 22,
          host_keys: ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEB"],
        },
      },
      rule: { node_id: 9, comment: "orbit db", ...rule },
      ensure,
    };
    const { steps, history } = await repo.runWorkflow(
      "operation-firewall-direct",
      { operation_id: 17, node_id: 9, kind, intent, execution_secrets: {} },
      { success },
    );
    const check = history.jobs[0].steps.find((step) => step.name === "Check the rules");
    return { steps, terminal: check?.outputs ? JSON.parse(check.outputs.stdout as string) : null };
  };
  const rowsNow = async () => JSON.parse(await Deno.readTextFile(state)).rows as Row[];
  const writeState = (value: Record<string, unknown>) => Deno.writeTextFile(state, JSON.stringify(value));
  return { repo, run, rowsNow, writeState, [Symbol.asyncDispose]: () => repo[Symbol.asyncDispose]() };
}

const deny = { action: "deny", port: "5432", protocol: "tcp", source: "any" };
const allow = { action: "allow", port: "5432", protocol: "tcp", source: "192.0.2.10" };

Deno.test("allow, deny, narrow-first order and remove through the native root", async () => {
  await using f = await fixture();
  await f.repo.cli(["workflow", "validate", "operation-firewall-direct"]);
  assertEquals((await f.run("firewall.deny", deny)).terminal, { backend: "active" });
  const { steps, terminal } = await f.run("firewall.allow", allow);
  assertEquals([terminal, steps.accepted], [{ backend: "active" }, "succeeded"]);
  assertEquals((await f.rowsNow()).slice(0, 2).map((r) => [r.to, r.action, r.source]), [
    ["5432/tcp", "allow", "192.0.2.10"],
    ["5432/tcp", "deny", "Anywhere"],
  ]);
  const again = await f.run("firewall.allow", allow);
  assertEquals(again.steps["Change the rules-0"], "skipped");
  assertEquals((await f.run("firewall.remove", { ...allow, retain: false }, "absent")).terminal, { backend: "absent" });
  assert(!(await f.rowsNow()).some((r) => r.source === "192.0.2.10"));
});

Deno.test("an inactive firewall is the terminal refusal and changes nothing", async () => {
  await using f = await fixture([], false);
  const { steps, terminal } = await f.run("firewall.allow", { ...allow, protocol: "any", source: "any" }, "present", false);
  assertEquals(terminal, { error_code: "firewall.backend_inactive" });
  assertEquals([steps["Change the rules-0"], steps.accepted], ["skipped", "failed"]);
  assertEquals(await f.rowsNow(), []);
});

Deno.test("a ufw failure fails the run with the check's verdict", async () => {
  await using f = await fixture();
  await f.writeState({ rows: [Trust, Trust6], active: true, fail: true });
  const { steps, terminal } = await f.run("firewall.allow", allow, "present", false);
  assertEquals(steps["Change the rules-0"], "failed");
  assertEquals(terminal, { error_code: "firewall.verify_failed" });
  assertEquals(steps.accepted, "failed");
});
