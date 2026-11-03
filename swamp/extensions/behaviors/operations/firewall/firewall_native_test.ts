import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { nativeRepo, ROOT } from "../../../../tests/_support/native_harness.ts";
import { scriptPath, ufwDouble } from "./native_fixture.ts";
async function fixture() {
  const repo = await nativeRepo();
  await repo.copyBehaviors("operations/firewall");
  await repo.pull("@swamp/ssh");
  const source = parse(
    await Deno.readTextFile(
      `${ROOT}/workflows/workflow-operation-firewall-direct.yaml`,
    ),
  ) as { jobs: { steps: { labels: unknown }[] }[] };
  for (const step of source.jobs[0].steps) {
    assertEquals(step.labels, { role: "swamp" });
  }
  const state = `${repo.dir}/state.json`;
  await Deno.writeTextFile(state, JSON.stringify({ rows: [], active: true }));
  const program = String.raw`import json,sys,shlex,os
from pathlib import Path
SCRIPT_PATH=` + JSON.stringify(scriptPath) + "\n" + ufwDouble + String.raw`
args=json.load(sys.stdin)
if any(x in args for x in ['-O','-N','-MNf','-M']):raise SystemExit(0)
command=args[-1]
if '__swamp_sudo_ok__' in command:
 print('__swamp_sudo_ok__')
 command=shlex.split(command)[-1].split('exec </dev/null; ',1)[-1]
args=shlex.split(command);index=len(args)-1-args[::-1].index('-c')
namespace={'__name__':'native_fixture'};exec(args[index+1],namespace)
state=Path(os.environ['FIREWALL_STATE']);saved=json.loads(state.read_text());ufw=Ufw(saved['rows']);ufw.active=saved['active'];namespace['ufw']=ufw
try:print(json.dumps(namespace['converge'](json.loads(args[index+2]))))
except RuntimeError as error:
 print(json.dumps({'error_code':str(error)}));raise SystemExit(1)
finally:state.write_text(json.dumps({'rows':ufw.rows,'active':ufw.active}))
`;
  const ssh = await repo.fakeBinary(
    "ssh",
    `const child=new Deno.Command('python3',{args:['-I','-c',${
      JSON.stringify(program)
    }],stdin:'piped',stdout:'piped',stderr:'piped'}).spawn();const writer=child.stdin.getWriter();await writer.write(new TextEncoder().encode(JSON.stringify(Deno.args)));await writer.close();const result=await child.output();await Deno.stdout.write(result.stdout);await Deno.stderr.write(result.stderr);Deno.exit(result.code);`,
  );
  repo.env.FIREWALL_STATE = state;
  await repo.workflow("operation-firewall-direct", (flow) => {
    for (const job of flow.jobs) {
      for (const step of job.steps) {
        const task = step.task as {
          behaviorType?: string;
          globalArgs: Record<string, unknown>;
        };
        if (task.behaviorType === "@swamp/ssh") {
          task.globalArgs.sshBinary = ssh;
        }
      }
    }
  });
  const run = async (
    kind: string,
    rule: Record<string, unknown>,
    ensure = "present",
    success = true,
  ) => {
    const intent = {
      ssh: {
        identity_file: "/fixture/key",
        target: {
          host: "10.44.0.9",
          user: "orbit",
          port: 22,
          host_keys: [
            "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEB",
          ],
        },
      },
      rule: { node_id: 9, comment: "orbit db", ...rule },
      ensure,
    };
    const { history } = await repo.runWorkflow(
      "operation-firewall-direct",
      {
        operation_id: 17,
        node_id: 9,
        kind,
        intent,
        execution_secrets: {},
      },
      { success },
    );
    const steps = Object.fromEntries(
      history.jobs[0].steps.map((s) => [s.name, s]),
    ) as unknown as Record<
      string,
      { status: string; outputs: { stdout: string; exitCode: number } }
    >;
    return { steps, terminal: JSON.parse(steps.apply.outputs.stdout) };
  };
  return {
    repo,
    state,
    run,
    async [Symbol.asyncDispose]() {
      await repo[Symbol.asyncDispose]();
    },
  };
}
Deno.test("allow deny order and remove through the native root", async () => {
  await using f = await fixture();
  await f.repo.cli(["workflow", "validate", "operation-firewall-direct"]);
  const deny = {
      action: "deny",
      port: "5432",
      protocol: "tcp",
      source: "any",
    },
    allow = {
      action: "allow",
      port: "5432",
      protocol: "tcp",
      source: "192.0.2.10",
    };
  assertEquals((await f.run("firewall.deny", deny)).terminal, {
    backend: "active",
  });
  const { steps, terminal } = await f.run("firewall.allow", allow);
  assertEquals([terminal, steps.accepted.status], [
    { backend: "active" },
    "succeeded",
  ]);
  const rows = JSON.parse(await Deno.readTextFile(f.state)).rows;
  assertEquals(
    rows.slice(0, 2).map((
      r: { to: string; action: string; source: string },
    ) => [r.to, r.action, r.source]),
    [["5432/tcp", "allow", "192.0.2.10"], ["5432/tcp", "deny", "Anywhere"]],
  );
  assertEquals(
    (await f.run("firewall.remove", { ...allow, retain: false }, "absent"))
      .terminal,
    { backend: "absent" },
  );
  assert(
    !JSON.parse(await Deno.readTextFile(f.state)).rows.some((
      r: { source: string },
    ) => r.source === "192.0.2.10"),
  );
});
Deno.test("refusal is the terminal output and fails the run", async () => {
  await using f = await fixture();
  await Deno.writeTextFile(
    f.state,
    JSON.stringify({ rows: [], active: false }),
  );
  const { steps, terminal } = await f.run(
    "firewall.allow",
    { action: "allow", port: "25", protocol: "any", source: "any" },
    "present",
    false,
  );
  assertEquals([steps.apply.status, steps.apply.outputs.exitCode], [
    "succeeded",
    1,
  ]);
  assertEquals(terminal, { error_code: "firewall.backend_inactive" });
  assertEquals(steps.accepted.status, "failed");
});
