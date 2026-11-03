/** Frozen VM receipt, real source/setup children and a local guest for Tasks provisioning. */
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { hostKey, type Json, repository, ROOT, text, tool, write, yamlWrite } from "./task_repository.ts";
import { readCalls } from "./fake_tool.ts";

export async function provision() {
  const t = await repository();
  const { repo, workspace, inputs } = t;
  repo.timeoutMs = 150_000;
  await repo.copyBehaviors("operations/task-vm-workspace");
  for (
    const relative of [
      "instance-lifecycle/source_profile.ts",
      "tools/constraints.ts",
    ]
  ) {
    const dest = `${repo.dir}/extensions/behaviors/operations/${relative}`;
    await Deno.mkdir(dest.slice(0, dest.lastIndexOf("/")), {
      recursive: true,
    });
    await Deno.copyFile(
      `${ROOT}/extensions/behaviors/operations/${relative}`,
      dest,
    );
  }
  await t.git("switch", "-C", "main");
  await write(`${workspace}/code`, "fixture\n");
  await t.git("add", ".");
  await t.git("commit", "-qm", "Source");
  const start = await t.git("rev-parse", "HEAD");
  const checkout = `${repo.dir}/guest/checkout`;
  Object.assign(inputs, {
    operation_id: 7,
    node_id: 14,
    kind: "tasks.provision",
    execution_secrets: {},
    intent: {
      task_vm_id: 10,
      guest_node_id: 20,
      group_id: 30,
      project_id: 40,
      instance_id: 50,
      wireguard_ip: "10.44.0.20",
      eligible: true,
      proof_club: null,
      git_authorization: null,
      route: null,
      route_secrets: {},
      processes: [],
      ssh_identity: {
        control_node_id: 209,
        identity_file: "/dev/null",
        public_key: "ssh-ed25519 PUBLIC",
      },
      workspace: {
        checkout_path: checkout,
        repository: workspace,
        repository_identity: "github:acme/test",
        default_branch: "main",
        start_commit: start,
        branch: "task-30",
        user: "orbit",
        project_type: "monorepo",
        entry_point: "artisan",
        skip_profile: true,
        php_versions: ["8.5"],
        setup: [],
      },
      fixture_vm: {
        task_vm_id: 10,
        guest_node_id: 20,
        ready: true,
        provider: {
          address: "192.0.2.20",
          provider_id: null,
          running: true,
          settled: true,
        },
        enrollment: {
          ready: true,
          node: {
            ssh_host_key_type: "ssh-ed25519",
            ssh_host_key: hostKey.split(" ")[1],
          },
          process: { process_id: 60, status: "active" },
        },
      },
    },
  });
  await write(
    `${repo.dir}/extensions/models/receipt.ts`,
    `import {z} from "npm:zod@4.4.3";
export const model={type:"@nckrtl/test-vm-receipt",version:"2026.11.01.1",resources:{receipt:{schema:z.record(z.string(),z.unknown()),lifetime:"infinite",garbageCollection:10}},methods:{read:{description:"Frozen public receipt",arguments:z.object({receipt:z.record(z.string(),z.unknown()),operation_id:z.number(),kind:z.string(),execution_secrets:z.record(z.string(),z.unknown())}),execute:async(args,ctx)=>({dataHandles:[await ctx.writeResource("receipt","receipt",{...args.receipt,operation_id:args.operation_id,root_kind:args.kind,vm_copy_keys:Object.keys(args.execution_secrets)})]})}}};`,
  );
  const child = parse(
    await text(`${ROOT}/workflows/workflow-task-vm-provision.yaml`),
  ) as Json;
  await yamlWrite(`${repo.dir}/workflows/workflow-task-vm-provision.yaml`, {
    id: child.id,
    name: "task-vm-provision",
    inputs: child.inputs,
    jobs: [{
      name: "vm",
      steps: [{
        name: "result",
        task: {
          type: "behavior",
          behaviorType: "@nckrtl/test-vm-receipt",
          behaviorName: "vm-fixture",
          methodName: "read",
          inputs: {
            receipt: "${{ inputs.intent.fixture_vm }}",
            operation_id: "${{ inputs.operation_id }}",
            kind: "${{ inputs.kind }}",
            execution_secrets: "${{ inputs.execution_secrets }}",
          },
        },
      }],
    }],
  });
  for (
    const name of [
      "operation-task-provision",
      "task-workspace-source",
      "task-vm-workspace",
    ]
  ) {
    await t.flow(name, (flow) =>
      Object.assign(
        flow,
        JSON.parse(
          JSON.stringify(flow).replaceAll(
            "/home/orbit/.cache/orbit-task-provision/",
            `${repo.dir}/control/`,
          ).replaceAll(
            "/tmp/orbit-task-provision-",
            `${repo.dir}/assets-`,
          ),
        ),
      ));
  }
  const privateHomes: string[] = [];
  const resultOf = (steps: Json) =>
    JSON.parse(
      steps["Check the workspace"].status === "succeeded"
        ? steps["Check the workspace"].outputs.stdout
        : steps["Set up the workspace"].outputs.result.result_json,
    );
  const execute = async (success = true) => {
    await repo.cli(["workflow", "validate", "operation-task-provision"]);
    const r = await t.run("operation-task-provision", inputs, success);
    return { ...r, result: resultOf(r.steps) };
  };
  return {
    ...t,
    start,
    checkout,
    execute,
    setup(commands: string[]) {
      inputs.intent.workspace.setup = commands.map((command, i) => ({
        name: `setup-${i}`,
        command,
        timeout_seconds: 30,
        input_json: JSON.stringify({ command }),
      }));
    },
    async proofDelivery(fail = false) {
      const secret = Array.from(
        crypto.getRandomValues(new Uint8Array(32)),
        (b) => b.toString(16).padStart(2, "0"),
      ).join("");
      repo.secrets = [secret];
      await repo.cli([
        "vault",
        "create",
        "local_encryption",
        "orbit-control-inputs",
      ]);
      await repo.cli([
        "vault",
        "put",
        "orbit-control-inputs",
        "swamp-proof-club-api-key",
      ], { input: secret });
      Object.assign(inputs.intent, {
        project_id: 62,
        proof_club_required: true,
        proof_club: {
          vault: "orbit-control-inputs",
          key: "swamp-proof-club-api-key",
        },
      });
      const home = await Deno.makeTempDir({
        prefix: "orbit-proof-club-",
      });
      privateHomes.push(home);
      await Deno.mkdir(`${home}/.config/swamp`, { recursive: true });
      await Deno.symlink(
        `${home}/public`,
        `${home}/.config/swamp/club-api-key`,
      );
      await write(`${home}/public`, "untouched");
      const calls = `${repo.dir}/proof-club-calls.jsonl`;
      await repo.fakeBinary(
        "tools/ssh",
        `import {shellSplit,readStdinBytes,passThrough,logCall} from ${JSON.stringify(tool)};
if(Deno.args.includes("-M")||Deno.args.includes("-O"))Deno.exit(0);
let argv=shellSplit(Deno.args.at(-1));const material=await readStdinBytes();
if(argv.some(v=>v.includes("/home/orbit/.config/swamp"))){
if(argv.slice(0,3).join(" ")!=="sudo -n --")throw Error(JSON.stringify(argv));argv=argv.slice(3);
for(const flag of ["-o","-g"])while(argv.includes(flag))argv.splice(argv.indexOf(flag),2);
argv=argv.map(v=>v.replaceAll("/home/orbit",${JSON.stringify(home)}));logCall(${
          JSON.stringify(calls)
        },{argv,stdin:material.length>0});
if(${fail}&&argv.includes("/dev/stdin")){console.error("fixture private destination refused");Deno.exit(1);}}
await passThrough(argv,material);`,
      );
      const r = await execute(!fail);
      assertEquals(
        r.steps["Create and prepare the VM"].outputs.result
          .vm_copy_keys,
        [],
      );
      const events = readCalls<{ argv: string[]; stdin: boolean }>(calls);
      assertEquals(events.map((e) => e.stdin), [false, false, true]);
      assertEquals(events[2].argv.slice(2), [
        "install",
        "-m",
        "0600",
        "/dev/stdin",
        `${home}/.config/swamp/club-api-key`,
      ]);
      assert(!JSON.stringify(events).includes(secret));
      assertEquals(await text(`${home}/public`), "untouched");
      assertEquals(
        (await Deno.stat(`${home}/.config/swamp`)).mode! & 0o777,
        0o700,
      );
      const logs = await repo.cli([
        "workflow",
        "history",
        "logs",
        r.run.id,
      ]);
      assert(!(logs.stdout + logs.stderr).includes(secret));
      return { ...r, home, secret };
    },
    async [Symbol.asyncDispose]() {
      for (const home of privateHomes) {
        await Deno.remove(home, {
          recursive: true,
        });
      }
      await t[Symbol.asyncDispose]();
    },
  };
}
