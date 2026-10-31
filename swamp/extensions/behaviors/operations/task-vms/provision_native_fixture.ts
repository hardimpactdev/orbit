import type { Http } from "../../automatic-deploy/gateway.ts";
import {
  type ProvisionAction,
  type ProvisionActions,
  ProvisionStages,
} from "./provision_direct.ts";

const file = "provision-fixture.json";
const read = async () => JSON.parse(await Deno.readTextFile(file));
const save = async (state: unknown) =>
  await Deno.writeTextFile(file, JSON.stringify(state));
const publicKey = "a".repeat(43) + "=";
export const fixtureHttp: Http = async (method, path, raw) => {
  const state = await read();
  const body = raw as Record<string, unknown> | undefined;
  const run = method === "GET"
    ? new URL(path, "https://gateway.test").searchParams.get("run_id")
    : body?.run_id;
  if (state.root && state.root !== run) return { status: 409, body: {} };
  state.root = run;
  state.calls.push({ method, path, result: body?.result });
  if (
    path.endsWith("/failure") &&
    !["succeeded", "failed", "cancelled"].includes(state.operation.status)
  ) {
    state.operation.status = "failed";
  }
  if (body?.status) {
    state.operation.status = body.status;
    state.operation.result = body.result ?? null;
    if (
      (body.result as { node?: { network_enrolled?: boolean } } | undefined)
        ?.node?.network_enrolled
    ) {
      state.networkCheckpoint = true;
    }
    await save(state);
    if (body.status === "succeeded" && state.loseFinal) {
      state.loseFinal = false;
      await save(state);
      throw new Error("Lost terminal reply");
    }
  }
  await save(state);
  return { status: 200, body: { data: state.operation } };
};
const action =
  (stage: typeof ProvisionStages[number]): ProvisionAction => async (input) => {
    const state = await read();
    const node =
      ["worker-ready", "prepare-runtime", "confirm-runtime"].includes(stage)
        ? input.guest_node_id
        : ["publish-hub", "issue-model"].includes(stage)
        ? input.gateway_node_id
        : stage === "issue-worker"
        ? input.issuer_node_id
        : input.node_id;
    if (stage === "publish-hub") {
      if (state.operation.result?.node?.wireguard_public_key !== publicKey) {
        throw new Error("task_vm.network_failed");
      }
      state.hubSawRootKey = true;
    }
    if (stage === "issue-worker" && state.networkCheckpoint !== true) {
      throw new Error("task_vm.network_failed");
    }
    state.stages.push({ stage, node_id: node });
    await save(state);
    if (state.failStage === stage) throw new Error("stage.failed");
    if (stage === "provider") {
      return {
        address: "10.251.77.2",
        provider_id: null,
        image_fingerprint: "a".repeat(64),
        ssh_host_key: null,
        running: true,
        settled: true,
      };
    }
    if (stage === "bootstrap") {
      return { bootstrapped: true, guest_node_id: input.guest_node_id };
    }
    if (stage === "prepare-network") return { wireguard_public_key: publicKey };
    if (stage === "worker-ready") return { worker_ready: true };
    if (stage === "confirm-runtime") {
      return { ready: true, process: { status: "active" } };
    }
    return { configured: true };
  };
export const fixtureActions: ProvisionActions = {
  provider: action("provider"),
  bootstrap: action("bootstrap"),
  "prepare-network": action("prepare-network"),
  "publish-network": action("publish-network"),
  "issue-worker": action("issue-worker"),
  "configure-worker": action("configure-worker"),
  "worker-ready": action("worker-ready"),
  "prepare-runtime": action("prepare-runtime"),
  "confirm-runtime": action("confirm-runtime"),
};

import { z } from "npm:zod@4.4.3";
import { DirectArgs, GatewayOperation, Globals } from "../_lib/direct.ts";

/** Only the placement/parent composition is faked here; model-key effects have their own native locking test. */
export const fixtureModelKeys = {
  type: "@nckrtl/task-vm-model-keys",
  version: "2026.10.31.1",
  globalArguments: Globals,
  resources: {
    result: {
      description: "Safe model issuance fixture result",
      schema: z.object({
        task_vm_id: z.number().int(),
        issued: z.literal(true),
      }),
      lifetime: "1d",
      garbageCollection: 1,
    },
  },
  methods: {
    issue: {
      description: "Record the shared router stage within the parent DAG",
      arguments: DirectArgs,
      execute: async (
        args: z.infer<typeof DirectArgs>,
        ctx: {
          definition: { name: string };
          writeResource(
            spec: string,
            name: string,
            data: unknown,
          ): Promise<unknown>;
        },
      ) => {
        const state = await read();
        if (
          ctx.definition.name !==
            `task-vm-model-router-${state.operation.input.model_router_node_id}`
        ) {
          throw new Error("task_vm.model_key_failed");
        }
        await action("issue-model")(
          state.operation.input,
          { provider: null, outcomes: [] },
          new GatewayOperation(fixtureHttp, args),
          async () => {},
        );
        return {
          dataHandles: [
            await ctx.writeResource("result", "issue-" + args.run_id, {
              task_vm_id: state.operation.input.task_vm_id,
              issued: true,
            }),
          ],
        };
      },
    },
  },
};

/** The shared hub transport and lock have their own native tests. */
export const fixtureHub = {
  type: "@nckrtl/node-wireguard-direct",
  version: "2026.10.31.3",
  globalArguments: Globals,
  resources: {
    outcome: {
      description: "Public hub outcome",
      schema: z.object({
        schema_version: z.literal(1),
        hub_id: z.number().int(),
        status: z.enum(["succeeded", "failed"]),
        projected: z.literal(true).optional(),
        error_code: z.string().optional(),
      }),
      lifetime: "1d",
      garbageCollection: 1,
    },
  },
  methods: {
    publish: {
      description: "Publish the admitted hub in the shared instance",
      arguments: DirectArgs.extend({
        snapshot: z.object({ hub_id: z.number().int() }).passthrough(),
      }),
      execute: async (
        args: z.infer<typeof DirectArgs> & { snapshot: { hub_id: number } },
        ctx: {
          definition: { name: string };
          writeResource(
            spec: string,
            name: string,
            value: unknown,
          ): Promise<unknown>;
        },
      ) => {
        const state = await read();
        if (
          ctx.definition.name !== `wireguard-hub-${args.snapshot.hub_id}`
        ) throw new Error("wrong hub instance");
        let result;
        try {
          await action("publish-hub")(
            state.operation.input,
            { provider: null, outcomes: [] },
            new GatewayOperation(fixtureHttp, args),
            async () => {},
          );
          result = {
            schema_version: 1 as const,
            hub_id: args.snapshot.hub_id,
            status: "succeeded" as const,
            projected: true as const,
          };
        } catch {
          result = {
            schema_version: 1 as const,
            hub_id: args.snapshot.hub_id,
            status: "failed" as const,
            error_code: "wireguard.hub_start_failed",
          };
        }
        await ctx.writeResource("outcome", "outcome", result);
        return result;
      },
    },
  },
};
