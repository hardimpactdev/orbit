/** Disposable Node tools shared by role and removal workflow tests. No tool contacts a host. */
import { nativeRepo } from "./native_harness.ts";
export { exists, sha256 } from "../../extensions/behaviors/operations/process-commands/native_fixture.ts";
export type Task = {
  type?: string;
  behaviorType?: string;
  behaviorName?: string;
  methodName?: string;
  globalArgs?: Record<string, unknown>;
  inputs?: Record<string, unknown>;
  workflowIdOrName?: string;
};
export type Step = { name: string; task: Task; labels?: unknown; writes?: unknown; [key: string]: unknown };
export type Flow = {
  id?: string;
  name?: string;
  inputs?: unknown;
  labels?: unknown;
  jobs: { name: string; dependsOn?: unknown; labels?: unknown; steps: Step[] }[];
};
export const PIN = "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEB";
export const sshInput = (host = "fixture", user = "fixture") => ({
  identity_file: "/fixture/key",
  target: { host, user, port: 22, host_keys: [PIN] },
});

export async function nodeFixture(removal = false) {
  const repo = await nativeRepo();
  repo.timeoutMs = 120_000;
  await repo.copyBehaviors("operations", "node-worker", "automatic-deploy");
  await repo.pull("@swamp/ssh");
  await repo.pull("@swamp/cfgmgmt");
  const host = `${repo.dir}/host`, tools = `${repo.dir}/tools`, statePath = `${host}/state.json`;
  await Deno.mkdir(host);
  await Deno.mkdir(tools);
  repo.env = { PATH: `${tools}:${Deno.env.get("PATH")}`, STATE: statePath, HOST: host, AUDIT: `${host}/calls` };
  const save = (state: Record<string, unknown>) => Deno.writeTextFileSync(statePath, JSON.stringify(state));
  const state = () =>
    JSON.parse(Deno.readTextFileSync(statePath)) as { active: boolean; enabled: boolean; calls: string[][] };
  save({ active: false, enabled: false, calls: [] });
  const ts = (name: string, source: string) => repo.fakeBinary(`tools/${name}`, source);
  const tool = new URL("./fake_tool.ts", import.meta.url).href;
  const passthrough = `import {passThrough, readStdinBytes} from ${JSON.stringify(tool)};\n`;
  await ts(
    "ssh",
    passthrough + `
if (Deno.args.includes("-M") || Deno.args.includes("-O")) Deno.exit(0);
if (Deno.env.get("OFFLINE") === "1") Deno.exit(255);
await passThrough(["/bin/sh", "-c", Deno.args.at(-1)!], await readStdinBytes());`,
  );
  await ts("scp", `Deno.copyFileSync(Deno.args.at(-2)!, Deno.args.at(-1)!.split(":").slice(1).join(":"));`);
  await ts(
    "sudo",
    passthrough + `await passThrough(Deno.args.slice(Deno.args.indexOf("--") + 1), await readStdinBytes());`,
  );
  for (const name of ["chown", "chgrp"]) await ts(name, "");
  await ts(
    "systemctl",
    `
const a = Deno.args;
if (${removal}) {
  Deno.writeTextFileSync(Deno.env.get("AUDIT")!, "systemctl " + a.join(" ") + "\\n", {append: true});
  if (a[0] === "stop" && Deno.env.get("FAIL_STOP") === "1") Deno.exit(1);
  if (a[0] === "show") console.log(a.includes("--value") ? "loaded" : "LoadState=loaded\\nActiveState=inactive\\nUnitFileState=disabled");
  Deno.exit(0);
}
const path = Deno.env.get("STATE")!, s = JSON.parse(Deno.readTextFileSync(path));
s.calls.push(a);
if (a[0] === "enable") s.enabled = true;
if (a[0] === "start") s.active = true;
Deno.writeTextFileSync(path, JSON.stringify(s));
if (a[0] === "start" && Deno.env.get("FAIL_START") === "1") Deno.exit(1);
if (a[0] === "show") {
  if (a.includes("--value")) console.log("loaded");
  else console.log("LoadState=loaded\\nActiveState=" + (s.active && Deno.env.get("DRIFT") !== "1" ? "active" : "inactive") + "\\nUnitFileState=" + (s.enabled ? "enabled" : "disabled"));
}`,
  );
  await ts(
    "cat",
    `if (Deno.args.join(" ") === "/etc/os-release") console.log("ID=ubuntu\\nVERSION_CODENAME=resolute");
else {const r = await new Deno.Command("/bin/cat", {args: Deno.args, stdout: "inherit", stderr: "inherit"}).output(); Deno.exit(r.code);}`,
  );
  await ts(
    "dpkg-query",
    `
const pkg = Deno.args.at(-1)!, s = JSON.parse(Deno.readTextFileSync(Deno.env.get("STATE")!));
if ((s.packages ?? []).includes(pkg)) {console.log("install ok installed 1.0"); Deno.exit(0);}
if (Deno.env.get("DOCKER_CE") === "1") {
 if (["docker-ce", "docker-ce-cli", "containerd.io"].includes(pkg)) {console.log("install ok installed 29.0.0"); Deno.exit(0);}
 if (pkg === "docker.io") Deno.exit(1);
}
const r = await new Deno.Command("/usr/bin/dpkg-query", {args: Deno.args, stdout: "inherit", stderr: "inherit"}).output(); Deno.exit(r.code);`,
  );
  await ts(
    "apt-get",
    `const a = Deno.args;
if (${removal}) {
  Deno.writeTextFileSync(Deno.env.get("AUDIT")!, "systemctl " + a.join(" ") + "\\n", {append: true});
  if (a[0] === "stop" && Deno.env.get("FAIL_STOP") === "1") Deno.exit(1);
  if (a[0] === "show") console.log(a.includes("--value") ? "loaded" : "LoadState=loaded\\nActiveState=inactive\\nUnitFileState=disabled");
  Deno.exit(0);
}
const path = Deno.env.get("STATE")!, s = JSON.parse(Deno.readTextFileSync(path));
s.calls.push(["apt-get", ...a]);
if (a.includes("install")) s.packages = a.slice(a.indexOf("install") + 1);
Deno.writeTextFileSync(path, JSON.stringify(s));
if (Deno.env.get("DOCKER_CE") === "1" && a.includes("docker.io")) Deno.exit(1);`,
  );
  await ts(
    "getent",
    `if (Deno.args.join(" ") !== "passwd fixture") Deno.exit(2); console.log("fixture:x:1000:1000::" + Deno.env.get("HOST") + "/home:/bin/sh");`,
  );
  await ts(
    "incus",
    `if (Deno.env.get("CI_MISSING") === "1") {console.error("Error: Project not found"); Deno.exit(1);} console.log(Deno.env.get("CI_GUESTS") ?? "[]");`,
  );
  await ts(
    "ufw",
    `Deno.writeTextFileSync(Deno.env.get("AUDIT")!, "ufw " + Deno.args.join(" ") + "\\n", {append: true});
if (Deno.env.get("FIREWALL_ERROR") === "1") {console.log("ERROR: permission denied"); Deno.exit(1);}
if (Deno.args.includes("delete")) {console.log("Could not delete non-existent rule"); Deno.exit(1);}`,
  );
  const install = async (name: string) =>
    await repo.workflow(name, (flow) => {
      delete (flow as { labels?: unknown }).labels;
      for (const step of flow.jobs.flatMap((j) => j.steps)) {
        const task = step.task as Task;
        if (task.behaviorType === "@swamp/ssh") task.globalArgs!.sshBinary = `${tools}/ssh`;
      }
    });
  const id = async (flag: string) =>
    new TextDecoder().decode((await new Deno.Command("id", { args: [flag] }).output()).stdout).trim();
  const user = await id("-un"), group = await id("-gn");
  return { repo, host, tools, state, save, ts, install, user, group };
}
