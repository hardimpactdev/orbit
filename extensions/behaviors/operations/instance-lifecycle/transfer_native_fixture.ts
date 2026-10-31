import { z } from "npm:zod@4.4.3";
import type { Http } from "../../automatic-deploy/gateway.ts";
import type { DirectIntent } from "../_lib/direct.ts";
const path = "transfer-fixture.json";
function state() {
  return JSON.parse(Deno.readTextFileSync(path));
}
function save(value: unknown) {
  Deno.writeTextFileSync(path, JSON.stringify(value));
}
function effect(name: string, detail: unknown = {}) {
  const value = state();
  value.effects.push({ name, detail });
  if (value.cancel_at === name) value.operation.cancel_requested = true;
  save(value);
  if (value.fail_effect === name) {
    throw new Error("instance.transfer_fixture_failed");
  }
}
export const transferHttp =
  (args: DirectIntent & { target_node_id?: number }): Http =>
  (method, url, body) => {
    const value = state();
    value.requests.push({ method, url, body });
    const operation = value.operation;
    if (method === "POST" && url.endsWith("/result")) {
      const data = body as Record<string, unknown>;
      const result = data.result as Record<string, unknown>;
      value.callbacks.push(data);
      if (["succeeded", "failed", "cancelled"].includes(String(data.status))) {
        value.terminal_effects = value.effects.map((v: { name: string }) =>
          v.name
        );
      }
      if (result?.checkpoint === "cutover") {
        operation.input.direct_transfer.cutover = true;
        value.cutover = true;
      }
      if (result?.checkpoint === "rollback") value.rolled_back = true;
      operation.status = data.status;
      operation.result = result;
      operation.error_code = data.error_code ?? null;
    }
    if (
      url.endsWith("/failure") &&
      !["succeeded", "failed", "cancelled"].includes(operation.status)
    ) {
      operation.status = operation.cancel_requested ? "cancelled" : "failed";
      operation.result = {};
    }
    save(value);
    if (url.includes("/private-material")) {
      return Promise.resolve({
        status: 200,
        body: { data: { destination_route_environment: [] } },
      });
    }
    const node = args.target_node_id ?? args.node_id;
    return Promise.resolve({
      status: 200,
      body: {
        data: {
          ...operation,
          input: {
            ...operation.input,
            publication: { node_id: node },
            process_runtime: {
              node_id: node,
              field: node === 9
                ? "source_installations"
                : "destination_installations",
              members: [],
              ports: [],
            },
            workloads: operation.input.direct_transfer.cutover && node === 17
              ? [{
                route_id: 1,
                instance_id: 11,
                node_id: 17,
                production: false,
                certificate_scope: "app-instance-11",
                source_access: {},
              }]
              : [],
            route_rollback: null,
            route_retire: node === 9
              ? {
                node_id: 9,
                user: "orbit",
                agent_sha256: "a".repeat(64),
                certificate_scopes: [],
                lan_route_ids: [],
                shared_php: true,
                production_instance_ids: [],
                url_instance_ids: [],
                ingress_firewall: false,
              }
              : null,
          },
        },
      },
    });
  };
const proof = (path: string) => ({
  path,
  user: "orbit",
  device: 1,
  inode: path === "/srv/source" ? 10 : 20,
  layout: "checkout" as const,
  common: path + "/.git",
});
export const transferEffects = {
  ready: () => {
    effect("destination-ready");
    return Promise.resolve({ ready: true as const });
  },
  inspect: (path: string) => Promise.resolve(proof(path)),
  capture: () => {
    effect("capture");
    return Promise.resolve({
      model_id: "1995a0ac-99da-4860-abf0-8b09260710fa",
      name: "archive-43a17cb2-f652-48ad-91a4-3ba2d921caae",
      version: 1,
      bytes: 10240,
      sha256: "a".repeat(64),
      head: "b".repeat(40),
      branch: "main",
      layout: "checkout" as const,
      repository: "https://github.com/example/transfer.git",
    });
  },
  materialize: () => {
    effect("materialize");
    return Promise.resolve({
      bytes: 10240,
      sha256: "a".repeat(64),
      head: "b".repeat(40),
    });
  },
  remove: (raw: unknown) => {
    effect(
      (raw as { path: string }).path === "/srv/source"
        ? "remove-source"
        : "discard-destination",
    );
    return Promise.resolve({ removed: true as const });
  },
};
export const fixtureTargetActions = () => ({
  prepare: () => {
    effect("prepare");
    return Promise.resolve({ ready: true as const });
  },
  synchronizeEnvironment: () => {
    effect("environment");
    return Promise.resolve({ ready: true as const });
  },
  lan: () => {
    effect("lan");
    return Promise.resolve({ ready: true as const });
  },
  verify: () => Promise.resolve({ ready: true as const }),
  firewall: () => Promise.resolve({ ready: true as const }),
  cleanup: () => Promise.resolve({ ready: true as const }),
});
export const fixtureRemovalActions = () => ({
  certificates: () => {
    effect("retire-certificates");
    return Promise.resolve({ ready: true as const });
  },
  lan: () => Promise.resolve({ ready: true as const }),
});
/** The integration test keeps native DAG/resources real and substitutes only shared effect boundaries. */
export function fixtureSharedBehavior(type: string) {
  const Args = z.object({
    operation_id: z.number(),
    node_id: z.number(),
    kind: z.string(),
    run_id: z.string(),
    target_node_id: z.number().optional(),
    recovery: z.boolean().optional(),
    leaves: z.array(z.unknown()).optional(),
    requests: z.array(z.unknown()).optional(),
    signed: z.array(z.unknown()).optional(),
  }).passthrough();
  type Context = {
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
  };
  const methods: Record<string, unknown> = {};
  for (
    const name of [
      "publishPhp",
      "publishCaddy",
      "publishDns",
      "requestAll",
      "signBatch",
      "publishAll",
    ]
  ) {
    methods[name] = {
      description: "Fixture local effect",
      arguments: Args,
      execute: async (args: z.infer<typeof Args>, ctx: Context) => {
        effect(name, { node: args.target_node_id });
        const data = name === "requestAll"
          ? { requests: [] }
          : name === "signBatch"
          ? { certificates: [] }
          : { ready: true };
        return {
          dataHandles: [
            await ctx.writeResource(
              "result",
              name + "-" + String(args.target_node_id ?? 0),
              data,
            ),
          ],
        };
      },
    };
  }
  return {
    type,
    version: "2026.10.31.1",
    resources: {
      result: {
        description: "Fixture effect output",
        schema: z.record(z.string(), z.unknown()),
        lifetime: "workflow",
        garbageCollection: 1,
      },
    },
    methods,
  };
}

export const fixtureRuntime = {
  ready: () => Promise.resolve(),
  pause: () => {
    effect("pause-runtime");
    return Promise.resolve();
  },
  prepare: () => {
    effect("prepare-runtime");
    return Promise.resolve();
  },
  install: () => {
    effect("install-runtime");
    return Promise.resolve();
  },
  verify: () => {
    effect("verify-runtime");
    return Promise.resolve();
  },
  restore: () => {
    effect("restore-runtime");
    return Promise.resolve();
  },
  retire: () => {
    effect("retire-runtime");
    return Promise.resolve();
  },
};

export const fixtureSqlite = {
  export: (_plan: unknown, ctx: { modelId: string }, runId: string) => {
    effect("sqlite-export");
    return Promise.resolve({
      model_id: ctx.modelId,
      name: `snapshot-${runId}`,
      version: 1,
      bytes: 4096,
      sha256: "a".repeat(64),
    });
  },
  install: async (
    _plan: unknown,
    _snapshot: unknown,
    _ctx: unknown,
    refresh: () => Promise<unknown>,
  ) => {
    effect("sqlite-prepare");
    await refresh();
    effect("sqlite-receive");
    await refresh();
    effect("sqlite-install");
  },
  environment: () => {
    effect("sqlite-environment");
    return Promise.resolve();
  },
  cleanupSource: () => {
    effect("sqlite-cleanup-source");
    return Promise.resolve();
  },
  cleanupTarget: () => {
    effect("sqlite-cleanup-target");
    return Promise.resolve();
  },
};
