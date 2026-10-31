import { z } from "npm:zod@4.4.3";
const file = "native-target.json";
export async function event(name: string, node?: number) {
  let lock: Deno.FsFile | undefined;
  for (let tries = 0; tries < 200 && !lock; tries++) {
    try {
      lock = await Deno.open(file + ".lock", { createNew: true, write: true });
    } catch (error) {
      if (!(error instanceof Deno.errors.AlreadyExists)) throw error;
      await new Promise((resolve) => setTimeout(resolve, 5));
    }
  }
  if (!lock) throw new Error("Fixture lock deadline");
  try {
    const state = JSON.parse(await Deno.readTextFile(file));
    state.events.push({ name, node });
    const fail = state.fail === name &&
      (!state.failAfterCommit || state.associated) && !state.didFail;
    if (fail) state.didFail = true;
    await Deno.writeTextFile(file, JSON.stringify(state));
    if (fail) throw new Error("fixture." + name + "_failed");
    return state;
  } finally {
    lock.close();
    await Deno.remove(file + ".lock");
  }
}

export function fixtureHttp(args: Record<string, unknown>) {
  return async (method: string, path: string, raw?: unknown) => {
    const state = JSON.parse(await Deno.readTextFile(file));
    const body = raw as Record<string, unknown>;
    let data: unknown;
    if (method === "GET") {
      const node = args.target_node_id ?? args.node_id;
      const target = state.operation.input.target;
      data = path.includes("private-material")
        ? {
          ["env_" + node]: [{
            instance_id: node === 2 ? 8 : 9,
            name: "instance.write-environment",
            input: { content: Deno.env.get("ORBIT_TARGET_TEST_PRIVATE") },
          }],
        }
        : {
          ...state.operation,
          input: {
            target,
            environment_fields: { "2": "env_2", "3": "env_3" },
            publication: { node_id: node },
            workloads: target.prepared.filter((v: { node_id: number }) =>
              v.node_id === node
            ),
            probes: node === 9
              ? [{
                protocol: "tls",
                agent_sha256: "a".repeat(64),
                probe: { address: "10.0.0.2", port: 443, domain: "pool.test" },
              }]
              : [],
          },
        };
    } else {
      const result = body.result as Record<string, unknown> | undefined;
      if (result?.checkpoint) {
        state.events.push({ name: result.checkpoint, node: args.node_id });
        if (result.checkpoint === "database-committed") state.associated = true;
        if (result.checkpoint === "rollback-associations") {
          state.associated = false;
        }
      }
      state.operation.status = path.endsWith("/failure")
        ? (state.operation.cancel_requested ? "cancelled" : "failed")
        : body.status;
      if (["succeeded", "failed", "cancelled"].includes(String(body.status))) {
        state.operation.result = result;
        state.events.push({ name: "finish", node: args.node_id });
      }
      data = {};
    }
    if (method !== "GET") await Deno.writeTextFile(file, JSON.stringify(state));
    return { status: 200, body: { data } };
  };
}
export const fixtureWorkloads = {
  prepareRuntime: async (_raw: unknown) => {
    const state = await event("runtime");
    if (state.fail === "runtime") throw new Error("fixture.runtime_failed");
    return { ready: true };
  },
  grantSourceAccess: async (_raw: unknown) => {
    await event("source-access");
    return { ready: true };
  },
  synchronizeEnvironment: async (raw: unknown) => {
    const writes = raw as { input: { content: string } }[];
    if (writes[0].input.content !== Deno.env.get("ORBIT_TARGET_TEST_PRIVATE")) {
      throw new Error("Wrong private callback");
    }
    await event("environment");
    return { ready: true };
  },
};
export const fixtureLan = async (_raw: unknown) => {
  await event("lan");
  return { ready: true };
};
export const fixtureVerify = async (_raw: unknown) => {
  await event("verify");
  return { ready: true };
};
const Obj = z.record(z.string(), z.unknown());
type Ctx = {
  writeResource: (
    spec: string,
    name: string,
    value: unknown,
  ) => Promise<unknown>;
};
const resource = (schema: unknown) => ({
  schema,
  lifetime: "1d",
  garbageCollection: 1,
});
export const certificateFixture = {
  version: "2026.10.31.1",
  globalArguments: z.object({}),
  resources: {
    requests: resource(z.object({ requests: z.array(Obj) })),
    signedBatch: resource(z.object({ certificates: z.array(Obj) })),
  },
  methods: {
    requestAll: {
      description: "Public requests",
      arguments: Obj,
      execute: async (args: Record<string, unknown>, ctx: Ctx) => {
        await event("request", Number(args.target_node_id));
        const requests = (args.leaves as Record<string, unknown>[]).map(
          (leaf) => ({
            node_id: leaf.node_id,
            scope: leaf.scope,
            state: "request",
            request_base64: btoa("public CSR"),
          }),
        );
        return {
          dataHandles: [
            await ctx.writeResource("requests", `requests-${args.run_id}`, {
              requests,
            }),
          ],
        };
      },
    },
    signBatch: {
      description: "Root signs public requests",
      arguments: Obj,
      execute: async (args: Record<string, unknown>, ctx: Ctx) => {
        await event("sign", 209);
        const certificates = (args.requests as Record<string, unknown>[][])
          .flat().map((req) => ({
            node_id: req.node_id,
            scope: req.scope,
            certificate_base64: btoa("public leaf"),
            root_certificate_base64: btoa("public root"),
          }));
        return {
          dataHandles: [
            await ctx.writeResource("signedBatch", `signed-${args.run_id}`, {
              certificates,
            }),
          ],
        };
      },
    },
    publishAll: {
      description: "Local publication",
      arguments: Obj,
      execute: async (args: Record<string, unknown>) => {
        await event("certificate", Number(args.target_node_id));
        return { ready: true };
      },
    },
    removeLeaf: {
      description: "Local staging cleanup",
      arguments: Obj,
      execute: async (args: Record<string, unknown>) => {
        await event("remove-staging", Number(args.target_node_id));
        return { ready: true };
      },
    },
  },
};
export const publicationFixture = {
  version: "2026.10.31.1",
  globalArguments: z.object({}),
  methods: {
    publishCaddy: {
      description: "Fresh Node Caddy under canonical model lock",
      arguments: Obj,
      execute: async (args: Record<string, unknown>) => {
        await event("caddy", Number(args.target_node_id));
        return { ready: true };
      },
    },
    publishPhp: {
      description: "Fresh Node PHP",
      arguments: Obj,
      execute: async (args: Record<string, unknown>) => {
        await event("php", Number(args.target_node_id));
        return { ready: true };
      },
    },
    publishDns: {
      description: "Fresh Node DNS under canonical model lock",
      arguments: Obj,
      execute: async (args: Record<string, unknown>) => {
        await event("dns", Number(args.target_node_id));
        return { ready: true };
      },
    },
  },
};
