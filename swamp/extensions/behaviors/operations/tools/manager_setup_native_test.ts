import { assert, assertEquals, assertFalse } from "jsr:@std/assert@1.0.19";
import { nativeRepo } from "../../../../tests/_support/native_harness.ts";
import { exists } from "../../../../tests/_support/route_fixture.ts";
import { readCalls } from "../../../../tests/_support/fake_tool.ts";
const TOOL = new URL("../../../../tests/_support/fake_tool.ts", import.meta.url).href;
async function fixture() {
  const repo = await nativeRepo();
  repo.timeoutMs = 120_000;
  await repo.pull("@swamp/ssh");
  await repo.pull("@swamp/cfgmgmt");
  await repo.copyBehaviors("operations/tools", "operations/node-bootstrap");
  const host = `${repo.dir}/host`,
    scope = `${host}/composer`,
    state = `${repo.dir}/installed`,
    calls = `${repo.dir}/calls`,
    tools = `${repo.dir}/tools`;
  await Deno.mkdir(host);
  await Deno.mkdir(tools);
  Object.assign(repo.env, { PATH: `${tools}:${Deno.env.get("PATH")}`, STATE: state, CALLS: calls });
  const user = new TextDecoder().decode((await new Deno.Command("id", { args: ["-un"] }).output()).stdout).trim();
  const ssh = await repo.fakeBinary(
    "tools/ssh",
    `import {passThrough,readStdinBytes} from ${JSON.stringify(TOOL)};
if(Deno.args.includes("-M")||Deno.args.includes("-O"))Deno.exit(0);
const cmd=Deno.args.at(-1)!.replaceAll("/opt/orbit/composer",${
      JSON.stringify(scope)
    }).replaceAll("/var/cache/orbit/tool-setup",${JSON.stringify(host + "/cache")}).replaceAll("/usr/bin/composer",${
      JSON.stringify(tools + "/composer")
    });
await passThrough(["/bin/sh","-c",cmd],await readStdinBytes());`,
  );
  await repo.fakeBinary(
    "tools/scp",
    `const [src,dst]=Deno.args.slice(-2);await Deno.copyFile(src,dst.split(":").slice(1).join(":"));`,
  );
  // This executable is a local argv adapter, never the host's privileged command.
  await repo.fakeBinary(
    "tools/sudo",
    `import {passThrough,readStdinBytes} from ${
      JSON.stringify(TOOL)
    };const a=Deno.args;await passThrough(a.slice(a.indexOf("--")+1),await readStdinBytes());`,
  );
  for (const name of ["chown", "chgrp"]) await repo.fakeBinary(`tools/${name}`, "");
  await repo.fakeBinary(
    "tools/composer",
    `try{Deno.statSync(Deno.env.get("STATE")!);}catch{Deno.exit(127);}console.log("Composer version 2.9.5");`,
  );
  await repo.fakeBinary(
    "tools/apt-get",
    `import {logCall} from ${
      JSON.stringify(TOOL)
    };logCall(Deno.env.get("CALLS")!,Deno.args);if(Deno.env.get("APT_FAIL")==="1")Deno.exit(1);if(Deno.args.includes("install"))Deno.writeTextFileSync(Deno.env.get("STATE")!,"2.9.5-1");`,
  );
  await repo.fakeBinary(
    "tools/dpkg-query",
    `try{console.log("install ok installed "+Deno.readTextFileSync(Deno.env.get("STATE")!));}catch{Deno.exit(1);}`,
  );
  const name = "operation-tool-manager-setup";
  await repo.workflow(name, (flow) => {
    for (const job of flow.jobs) {
      for (const step of job.steps) {
        const task = step.task as { behaviorType: string; globalArgs: Record<string, unknown> };
        if (task.behaviorType === "@swamp/ssh") task.globalArgs.sshBinary = ssh;
      }
    }
  });
  const inputs = {
    operation_id: 9901,
    node_id: 701,
    converge: false,
    ssh: {
      target: {
        host: "fixture",
        user,
        port: 22,
        host_keys: ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEB"],
      },
      identity_file: "/fixture/key",
    },
    intent: {
      manager: "composer",
      user,
      platform: "linux",
      architecture: "x86_64",
      setup: {
        manager: "composer",
        input: { user, version: "2.9.5-1" },
        apt: {
          snapshot: "20260101T000000Z",
          packages: [{ name: "composer", version: "2.9.5-1", artifact: null as unknown }],
        },
        packages: [],
      },
    },
  };
  return { repo, scope, state, calls, inputs, run: (success = true) => repo.runWorkflow(name, inputs, { success }) };
}
Deno.test("first manager use installs exact packages and creates a managed manifest", async () => {
  const f = await fixture();
  await using _ = f.repo;
  await f.run();
  assertEquals(JSON.parse(await Deno.readTextFile(`${f.scope}/composer.json`)), { require: {} });
  assert((await Deno.stat(`${f.scope}/vendor/bin`)).isDirectory);
  const install = readCalls<string[]>(f.calls).find((a) => a.includes("install"))!;
  assertEquals(install.at(-1), "composer=2.9.5-1");
  assert(install.includes("--no-remove"));
  assert(install.includes("APT::Get::List-Cleanup=false"));
});
Deno.test("existing Composer requirements survive first use recovery", async () => {
  const f = await fixture();
  await using _ = f.repo;
  await Deno.mkdir(f.scope);
  const original = '{"require":{"vendor/existing-tool":"^2.0"}}\n';
  await Deno.writeTextFile(`${f.scope}/composer.json`, original);
  await f.run();
  assertEquals(await Deno.readTextFile(`${f.scope}/composer.json`), original);
});
Deno.test("failed package install never publishes manager readiness", async () => {
  const f = await fixture();
  await using _ = f.repo;
  f.repo.env.APT_FAIL = "1";
  assertEquals((await f.run(false)).run.status, "failed");
  assertFalse(exists(`${f.scope}/composer.json`));
});
Deno.test("ready manager needs no install or scope rewrite", async () => {
  const f = await fixture();
  await using _ = f.repo;
  await Deno.writeTextFile(f.state, "2.9.5-1");
  await f.run();
  assertFalse(exists(f.calls));
  assertFalse(exists(f.scope));
});
Deno.test("convergence keeps matching packages and repairs the manager scope serially", async () => {
  const f = await fixture();
  await using _ = f.repo;
  await Deno.writeTextFile(f.state, "2.9.5-1");
  f.inputs.converge = true;
  f.inputs.intent.setup.apt.packages.push({
    name: "git",
    version: "2.9.5-1",
    artifact: { url: "https://example.invalid/already-installed.deb", algorithm: "sha256", digest: "a".repeat(64) },
  });
  const { history } = await f.run();
  const folders = history.jobs.flatMap((j) => j.steps).filter((s) =>
    s.name.startsWith("Prepare manager folders-")
  ) as unknown as { startedAt: string; completedAt: string }[];
  folders.sort((a, b) => a.startedAt.localeCompare(b.startedAt));
  assert(folders.length >= 2);
  for (let i = 1; i < folders.length; i++) assert(folders[i - 1].completedAt <= folders[i].startedAt);
  assertFalse(exists(f.calls));
  assertEquals(JSON.parse(await Deno.readTextFile(`${f.scope}/composer.json`)), { require: {} });
  assert((await Deno.stat(`${f.scope}/vendor/bin`)).isDirectory);
});
