/** Local Node seams shared by the Instance native workflow tests. No real SSH, sudo or Docker. */
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { nativeRepo } from "./native_harness.ts";
import { exists, firstJson, sha256 } from "../../extensions/behaviors/operations/process-commands/native_fixture.ts";
export { exists, sha256 };
// Native history and editable workflow tasks have schemas owned by the runtime.
// deno-lint-ignore no-explicit-any
export type Data = any;
export const TOOL = new URL("./fake_tool.ts", import.meta.url).href;
export const KEY = "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG";
export const git = (cwd: string, ...args: string[]) => {
  const out = new Deno.Command("git", { args: ["-C", cwd, ...args] }).outputSync();
  assert(out.success, new TextDecoder().decode(out.stderr));
  return new TextDecoder().decode(out.stdout).trim();
};
export async function instanceFixture(...families: string[]) {
  const repo = await nativeRepo();
  repo.timeoutMs = 240_000;
  const node = await Deno.makeTempDir({ prefix: "orbit-instance-node-" });
  const tools = `${repo.dir}/tools`;
  await Deno.mkdir(tools);
  repo.env.PATH = `${tools}:${Deno.env.get("PATH")}`;
  await repo.pull("@swamp/ssh");
  await repo.pull("@swamp/cfgmgmt");
  await repo.copyBehaviors(...families);
  const user = new TextDecoder().decode(new Deno.Command("id", { args: ["-un"] }).outputSync().stdout).trim();
  const ts = (name: string, source: string) => repo.fakeBinary(`tools/${name}`, source);
  for (const name of ["chown", "chgrp"]) await ts(name, "Deno.exit(0);");
  const calls = `${repo.dir}/calls.jsonl`;
  const fail = `${repo.dir}/fail`;
  await ts(
    "sudo",
    `
import { logCall, passThrough, readStdinBytes } from ${JSON.stringify(TOOL)};
let args = Deno.args;
while (args[0]?.startsWith("-") && args[0] !== "--") args = args.slice(["-u", "--user", "-g"].includes(args[0]) ? 2 : 1);
if (args[0] === "--") args = args.slice(1);
if (args[0]?.split("/").at(-1) === "timeout") args = args.slice(2);
const name = args[0]?.split("/").at(-1);
if (["systemctl", "docker", "setfacl", "chgrp"].includes(name!)) {
  logCall(${JSON.stringify(calls)}, [name, ...args.slice(1)]);
  if (args[1] === "show") console.log("LoadState=loaded\\nActiveState=active\\nUnitFileState=enabled");
  if (["is-active", "is-enabled"].includes(args[1])) console.log(args[1] === "is-active" ? "active" : "enabled");
  let failure = ""; try { failure = Deno.readTextFileSync(${
      JSON.stringify(fail)
    }); } catch { /* no injected failure */ }
  Deno.exit(failure && args.includes(failure) ? 1 : 0);
}
if (name === "install") {
  for (const flag of ["-o", "-g"]) { const i = args.indexOf(flag); if (i >= 0) args.splice(i, 2); }
}
await passThrough(args, await readStdinBytes());
`,
  );
  const ssh = await ts(
    "ssh",
    `
import { passThrough, readStdinBytes } from ${JSON.stringify(TOOL)};
if (Deno.args.some(a => ["-M", "-O", "-N", "-MNf"].includes(a))) Deno.exit(0);
const command = Deno.args.at(-1)!.replaceAll("/etc/systemd/system/", ${JSON.stringify(node + "/etc/systemd/system/")})
  .replace(/(?<![\\w/])'?(?:[/]usr[/]bin[/])?sudo'?(?= )/g, ${JSON.stringify(tools + "/sudo")});
await passThrough(["/bin/sh", "-c", command], await readStdinBytes());
`,
  );
  const scp = await ts(
    "scp",
    `
import { copy } from "jsr:@std/fs@1.0.19/copy";
const local = (s: string) => s.includes(":") ? s.slice(s.indexOf(":") + 1) : s;
const [source, destination] = Deno.args.slice(-2).map(local);
let target = destination;
try { if (Deno.statSync(destination).isDirectory && !source.endsWith("/.")) target += "/" + source.split("/").at(-1); } catch { /* new destination */ }
await copy(source, target, { overwrite: true });
`,
  );
  await Deno.writeTextFile(`${repo.dir}/key`, "fixture identity never consumed");
  const connection = {
    identity_file: `${repo.dir}/key`,
    target: { host: "fixture.invalid", user, port: 22, host_keys: [KEY] },
  };
  const workflow = (name: string, edit: (flow: Data) => void = () => {}) =>
    repo.workflow(name, (flow) => {
      delete flow.labels;
      delete flow.target;
      for (const job of flow.jobs) {
        for (const step of job.steps) {
          delete step.target;
          const task = step.task as Data;
          if (task.behaviorType === "@swamp/ssh") Object.assign(task.globalArgs, { sshBinary: ssh, scpBinary: scp });
        }
      }
      edit(flow);
    });
  const run = async (name: string, inputs: unknown, success = true) => {
    await repo.cli(["workflow", "validate", name]);
    const out = await repo.cli(["workflow", "run", name, "--stdin"], { input: JSON.stringify(inputs), success });
    assert(!out.stdout.includes("Invalid expression"), out.stdout + out.stderr);
    assert(out.stdout.trim(), out.stderr);
    const record = firstJson(out.stdout) as Data;
    const history = (await repo.history(record.id)).history;
    const steps: Record<string, Data> = Object.fromEntries(
      history.jobs.flatMap((j: Data) => j.steps).map((s: Data) => [s.name, s]),
    );
    assertEquals(record.status, success ? "succeeded" : "failed", JSON.stringify(history));
    return { run: record, history, steps };
  };
  return {
    repo, node, tools, ts, user, calls, fail, ssh, scp, connection, workflow, run,
    async [Symbol.asyncDispose]() {
      await repo[Symbol.asyncDispose]();
      await Deno.remove(node, { recursive: true });
    },
  };
}
