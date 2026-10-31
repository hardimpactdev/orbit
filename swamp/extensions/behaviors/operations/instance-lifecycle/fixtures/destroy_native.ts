import type { Http } from "../../../automatic-deploy/gateway.ts";
import { fixture, member } from "./destroy.ts";
import { runDestroyLocalStep } from "../destroy_steps.ts";
import { DestroyDatabase, removeDestroyDatabase } from "../destroy_database.ts";
const file = "destroy-fixture.json";
const read = () => JSON.parse(Deno.readTextFileSync(file));
function event(value: unknown) {
  const state = read();
  state.events.push(value);
  Deno.writeTextFileSync(file, JSON.stringify(state));
}
function localInput(state: Record<string, unknown>) {
  const local = {
    ...fixture(),
    database: DestroyDatabase.parse(fixture().database),
  };
  if (state.retryTest) local.members.push(member(2, "worktree"));
  if (state.foreignDatabase) {
    local.database = {
      owner_instance_id: 1,
      intent_id: 4321,
      connection_ids: [11],
      servers: [{
        server_id: 5,
        node_id: 4,
        intent: {
          driver: "mysql",
          container: "orbit-process-5-mysql",
          host: "127.0.0.1",
          port: 3306,
          default_open: false,
          databases: ["own1"],
          users: ["own1"],
          test_prefixes: [],
          owners: { own1: "own1" },
        },
      }],
      sqlite: [],
    };
  }
  return local;
}
const node = {
  node_id: 9,
  user: "orbit",
  agent_sha256: "d".repeat(64),
  caddy: {
    content: "",
    version: "e3b0c44298fc1c149afbf4c8996fb924",
    listen_addresses: [],
    unserved: true,
  },
  certificate_scopes: ["app-instance-1"],
  lan_route_ids: [],
};
export const fixtureHttp: Http = (method, path, body) => {
  event({ method, path });
  const state = read();
  const local = localInput(state);
  if (method === "POST") {
    if (path.endsWith("/failure")) {
      if (!["succeeded", "failed", "cancelled"].includes(state.status)) {
        state.status = "failed";
        state.result = {};
        state.error_code = "operation.native_failed";
      }
    } else if (!["succeeded", "failed", "cancelled"].includes(state.status)) {
      const data = body as Record<string, unknown>;
      state.status = data.status;
      state.result = data.result ?? null;
      state.error_code = data.error_code ?? null;
    }
    Deno.writeTextFileSync(file, JSON.stringify(state));
  }
  if (path.includes("private-material")) {
    return Promise.resolve({
      status: 200,
      body: {
        data: {
          php: { node_id: 9, user: "orbit", configurations: {} },
          database_material: {
            passwords: { "5": "synthetic-private-password" },
          },
          git_read: { github_token: null },
        },
      },
    });
  }
  return Promise.resolve({
    status: 200,
    body: {
      data: {
        id: 7,
        kind: "instance.destroy",
        node_id: 9,
        status: state.status,
        cancel_requested: false,
        input: {
          instance_id: 1,
          project_id: 12,
          node_id: 9,
          environment: "development",
          participant_node_ids: state.foreignDatabase ? [9, 4] : [9],
          removal_id: "0c4f71cf-84bd-4b80-8da4-8cd1b39cb8b6",
          direct_destroy: {
            ...local,
            node_user: "orbit",
            teardown: local.members.map((m) => ({
              instance_id: m.instance_id,
              checkout: m.source.checkout,
              user: "orbit",
              steps: [],
            })),
            database: state.currentParticipant === 4
              ? {
                owner_instance_id: 1,
                intent_id: 4321,
                servers: local.database.servers,
                sqlite: [],
              }
              : local.database,
            routes: local.members.map((m) => ({
              schema: "route.withdrawal.v1",
              instance_id: m.instance_id,
              route_ids: [],
              nodes: [{
                ...node,
                certificate_scopes: [`app-instance-${m.instance_id}`],
              }],
              dns: [],
            })),
          },
          withdrawal_publications: [{ nodes: [node], dns: [] }],
          caddy_publication: node,
        },
      },
    },
  });
};
export async function fixtureStep(
  ...args: Parameters<typeof runDestroyLocalStep>
) {
  const [stage, rootArgs, gateway] = args;
  event({ stage });
  const state = read();
  if (state.fail === stage) throw new Error("bounded fixture refusal");
  if (state.retryTest && stage === "finalize_sources" && !state.secondRun) {
    state.completedPeer = true;
    Deno.writeTextFileSync(file, JSON.stringify(state));
    throw new Error("Interrupted after first member finalization");
  }
  if (
    state.retryTest && ["prepare_sources", "verify_sources"].includes(stage)
  ) {
    return await runDestroyLocalStep(stage, rootArgs, gateway, {
      source: (_command, input) =>
        Promise.resolve({
          state: read().completedPeer && input.member === "2"
            ? "completed"
            : "present",
        }),
      verifySource: (raw) => {
        const input = raw as Record<string, unknown>;
        event({
          effect: "verified-source",
          checkout: input.checkout,
          worktrees: input.worktrees,
        });
        return Promise.resolve({
          absent: false,
          physical: String(input.checkout),
          admin: "",
        });
      },
    });
  }
  return {};
}
export async function fixtureDatabase(
  ...args: Parameters<typeof removeDestroyDatabase>
) {
  event({ stage: "databases" });
  if (read().fail === "databases") throw new Error("database.in_use");
  if (read().foreignDatabase) {
    const selected = args[2] as Record<string, unknown>;
    if ("connection_ids" in selected) {
      throw new Error("Foreign DB view leaked complete row inventory");
    }
    return await removeDestroyDatabase(
      args[0],
      args[1],
      args[2],
      args[3],
      args[4],
      async (_work, use) => {
        await use((raw) => {
          const value = raw as {
            payload: { passwords: Record<string, string> };
          };
          if (
            Object.keys(value.payload.passwords).join(",") !== "5"
          ) throw new Error("Foreign credentials exposed");
          event({ effect: "database-owned-private-read" });
          return Promise.resolve();
        });
        return { backend: "absent" };
      },
    );
  }
  return { backend: "absent" as const };
}
export function fixtureSetParticipant(nodeId: number | null) {
  const state = read();
  state.currentParticipant = nodeId;
  Deno.writeTextFileSync(file, JSON.stringify(state));
}
export const fixtureCleanup = {
  certificates: () => {
    event({ effect: "certificate.remove-leaf" });
    return Promise.resolve({ ready: true });
  },
  lan: () => {
    event({ effect: "lan.remove" });
    return Promise.resolve({ ready: true });
  },
};
export function fixtureAccount() {
  return Promise.resolve({
    user: "orbit",
    group: "orbit",
    home: "/home/orbit",
  });
}
export function fixtureInvoke() {
  event({ effect: "caddy.publish" });
  if (read().fail === "caddy") throw new Error("caddy.publication_failed");
  return Promise.resolve({ result: "unchanged" });
}
export function fixturePhp() {
  event({ effect: "php.converge-shared-pools" });
  return Promise.resolve({ ready: true });
}
export function fixtureEngineRefused() {
  return read().engineRefused === true;
}
