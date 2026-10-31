/** Scratch-only native sweep provider; private bytes are checked in memory and never saved. */
import { fixtureHttp } from "./native-fixture.ts";

export function fixtureSweepHttp(args: { caFile: string }) {
  if (args.caFile !== "/usr/local/share/ca-certificates/orbit-root.crt") {
    throw new Error(
      "The admitted Node worker requires its installed public CA",
    );
  }
  return fixtureHttp;
}

export const fixtureSweepSystem:
  import("../node/system_command.ts").SystemTransport = async (command) => {
    if (
      command.command !== "/usr/bin/sudo" ||
      JSON.stringify(command.args.slice(0, 3)) !==
        JSON.stringify(["-n", "--", "/usr/bin/incus"])
    ) throw new Error("Unexpected native transport");
    const args = command.args.slice(3);
    const state = JSON.parse(await Deno.readTextFile("native-executor.json"));
    state.sweepCalls = [...(state.sweepCalls ?? []), { args }];
    let value: unknown;
    if (args[0] === "query") {
      if (args[2] === "/1.0/instances?all-projects=true") {
        value = state.hostNames.map((name: string) =>
          `/1.0/instances/${name}?project=orbit-tasks`
        );
      } else if (args[2] === "/1.0/storage-pools") {
        value = ["/1.0/storage-pools/orbit-tasks"];
      } else if (
        args[2] ===
          "/1.0/storage-pools/orbit-tasks/volumes/custom?all-projects=true"
      ) {
        value = state.hostVolumes.map((name: string) =>
          `/1.0/storage-pools/orbit-tasks/volumes/custom/${name}?project=orbit-tasks`
        );
      } else throw new Error("Unexpected native query");
    } else {
      if (args[0] !== "--project" || args[1] !== "orbit-tasks") {
        throw new Error("Foreign native project");
      }
      const op = args.slice(2);
      if (op[0] === "list") {
        value = (op.includes("--")
          ? state.hostNames.filter((name: string) =>
            name === op.at(-1)
          )
          : state.hostNames).map((name: string) => ({
            name,
            type: "virtual-machine",
            status: "Stopped",
            config: {},
          }));
      } else if (op[0] === "image" && op[1] === "list") value = [];
      else if (op[0] === "storage" && op[2] === "list") {
        value = state.hostVolumes.map((name: string) => ({
          name,
          type: "custom",
        }));
      } else if (op[0] === "delete") {
        state.hostNames = state.hostNames.filter((name: string) =>
          name !== op.at(-1)
        );
        value = {};
      } else if (op[0] === "storage" && op[2] === "delete") {
        state.hostVolumes = state.hostVolumes.filter((name: string) =>
          name !== op.at(-1)
        );
        value = {};
      } else throw new Error("Unexpected native effect");
    }
    await Deno.writeTextFile("native-executor.json", JSON.stringify(state));
    return { success: true, stdout: JSON.stringify(value) };
  };
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
