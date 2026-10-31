/** Scratch-only native sweep provider; private bytes are checked in memory and never saved. */
export async function fixtureSweepAgent(
  call: import("../_lib/node-agent.ts").NodeCall,
) {
  const state = JSON.parse(await Deno.readTextFile("native-executor.json"));
  state.sweepCalls = [...(state.sweepCalls ?? []), {
    name: call.name,
    input: call.input,
  }];
  let result: Record<string, unknown>;
  if (call.name === "incus.instances") result = { names: state.hostNames };
  else if (call.name === "incus.volumes") result = { names: state.hostVolumes };
  else if (call.name === "incus.inventory") {
    result = {
      instances: state.hostNames.map((name: string) => ({
        project: "orbit-tasks",
        name,
      })),
      volumes: state.hostVolumes.map((name: string) => ({
        project: "orbit-tasks",
        pool: "orbit-tasks",
        name,
      })),
    };
  } else if (call.name === "incus.images") result = { images: {} };
  else if (call.name === "incus.vm") {
    const name = (call.input.vm as { name: string }).name;
    state.hostNames = state.hostNames.filter((value: string) => value !== name);
    result = { ready: true };
  } else if (call.name === "incus.volume") {
    const name = (call.input.operation as { name: string }).name;
    state.hostVolumes = state.hostVolumes.filter((value: string) =>
      value !== name
    );
    result = { name };
  } else throw new Error("Unexpected sweep primitive");
  await Deno.writeTextFile("native-executor.json", JSON.stringify(state));
  return result;
}
export async function fixtureSweepCloud(
  input: import("./sweep.ts").SweepInput,
  gateway: import("./sweep.ts").SweepGateway,
  signal?: AbortSignal,
) {
  const { sweepUpCloud } = await import("./upcloud_sweep.ts");
  const request: import("./upcloud_sweep.ts").CloudRequest = async (
    method,
    path,
  ) => {
    await gateway.withPrivateMaterial((material) => {
      if (
        !Deno.env.get("SWEEP_PRIVATE_FIXTURE") ||
        material.upcloud_token !== Deno.env.get("SWEEP_PRIVATE_FIXTURE")
      ) throw new Error("Missing private fixture");
      return Promise.resolve();
    });
    const state = JSON.parse(await Deno.readTextFile("native-executor.json"));
    state.cloudCalls = [...(state.cloudCalls ?? []), { method, path }];
    let result: Record<string, unknown> | null;
    if (path === "server?label=orbit-task-vm") {
      result = {
        servers: { server: state.cloudServer ? [state.cloudServer] : [] },
      };
    } else if (path === "server?label=orbit-task-image") {
      result = { servers: { server: [] } };
    } else if (path === "storage/template") {
      result = { storages: { storage: [] } };
    } else if (method === "DELETE") {
      state.cloudServer = null;
      result = {};
    } else result = state.cloudServer ? { server: state.cloudServer } : null;
    await Deno.writeTextFile("native-executor.json", JSON.stringify(state));
    return result;
  };
  return await sweepUpCloud(input, gateway, signal, request);
}
