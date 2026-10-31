import type { Http } from "../../automatic-deploy/gateway.ts";
import type { inspect } from "../../node-worker/canary.ts";
import type { SystemTransport } from "../node/system_command.ts";
const path = "image-reaper-fixture.json";
let selected = 0;
const read = async () => JSON.parse(await Deno.readTextFile(path));
const save = async (state: unknown) =>
  await Deno.writeTextFile(path, JSON.stringify(state));
const node = (id: number) => ({
  id,
  user: "orbit",
  status: "active",
  settings: { task_vms: { project: "orbit-tasks", pool: "orbit-tasks" } },
});
export const fixtureIdentity: typeof inspect = (input) =>
  Promise.resolve({
    ...input,
    hostname: "fixture-node",
    effective_user: input.user,
    sudo_ready: true,
  });
export const fixtureHttp: Http = async (_method, url) => {
  const state = await read();
  state.api.push(url);
  await save(state);
  if (url === "/api/v1/nodes") {
    return { status: 200, body: { data: [node(9), node(13)] } };
  }
  const host = /^\/api\/v1\/nodes\/(9|13)$/.exec(url);
  if (host) {
    selected = Number(host[1]);
    return { status: 200, body: { data: node(selected) } };
  }
  const status = new URL("https://gateway.orbit" + url).searchParams.get(
    "status",
  );
  if (state.unavailable) {
    return { status: 503, body: { error: "fixture refusal" } };
  }
  return {
    status: 200,
    body: {
      data: status === "running"
        ? [9, 13].map((id) => ({
          id: id + 10,
          kind: "task_vm.image_build",
          status: "running",
          input: {
            build_id: id + 1,
            host_node_id: id,
            project: "orbit-tasks",
            name: `tvm-image-${id + 1}`,
          },
        }))
        : [],
    },
  };
};
export const fixtureSystem: SystemTransport = async (command) => {
  const state = await read();
  const args = [...command.args.slice(5)];
  state.commands.push({ node_id: selected, args });
  const name = args.at(-1)!;
  const vms = state.vms[String(selected)];
  if (args[0] === "list") {
    await save(state);
    return {
      success: true,
      stdout: JSON.stringify(
        Object.values(vms).filter((vm: unknown) =>
          !args.includes("--") || (vm as { name: string }).name === name
        ),
      ),
    };
  }
  if (args[0] === "delete") delete vms[name];
  if (args[0] === "stop") vms[name].status = "Stopped";
  await save(state);
  return { success: true, stdout: args[0] === "storage" ? "[]" : "" };
};
