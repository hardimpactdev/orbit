import { z } from "npm:zod@4.4.3";
import type { Http } from "./gateway.ts";
import {
  isAdmissionFence,
  isAutomaticDeployment,
  type Operation,
  PolicyError,
  type PolicyEvent,
  PolicyEventSchema,
  RowSchema,
  sha,
} from "./policy.ts";

export const DevelopmentState = z.object({
  event: z.object({ key: z.string(), body: PolicyEventSchema }).nullable()
    .default(null),
  sequence: z.number().int().nonnegative().default(0),
  pending: z.object({
    commit: sha,
    key: z.string(),
    operation_id: z.number().int().positive().nullable(),
    manual_after: z.number().int().nonnegative().default(0),
  }).nullable().default(null),
  repair: z.object({
    route_id: z.number().int().positive(),
    key: z.string(),
    operation_id: z.number().int().positive().nullable(),
  }).nullable().default(null),
  result: z.string().nullable().default(null),
  commit: sha.nullable().default(null),
  since: z.string().nullable().default(null),
});
export type DevelopmentContext = {
  readResource(name: string): Promise<Record<string, unknown> | null>;
  writeResource(
    spec: string,
    name: string,
    value: Record<string, unknown>,
  ): Promise<{ name: string }>;
};
type Admissions = {
  event(instance: number, body: PolicyEvent, key: string): Promise<Operation>;
  deploy(instance: number, commit: string, key: string): Promise<Operation>;
  receipt(
    instance: number,
    commit: string,
    key: string,
  ): Promise<Operation | null>;
  resolve(instance: number, commit: string, key: string): Promise<Operation>;
  operation(id: number): Promise<Operation>;
};
const Instance = z.object({
  id: z.number().int().positive(),
  node_id: z.number().int().positive(),
  name: z.string(),
  status: z.string(),
  source_layout: z.string(),
  seed_commit: sha.nullable(),
  development_projection_route_id: z.number().int().positive().nullable(),
  auto_deploy: z.object({
    policy_owner: z.string(),
    resumed_after: z.number().int(),
    history_version: z.string(),
  }),
});
const terminal = (status: string) =>
  ["succeeded", "failed", "cancelled"].includes(status);

/** Development policy uses public records and normal keyed admissions; it executes no Node action. */
export async function tickDevelopment(
  http: Http,
  ctx: DevelopmentContext,
  admissions: Admissions,
  now: string,
  filter: number[] = [],
) {
  const request = async (
    method: "GET" | "POST" | "PUT" | "PATCH",
    path: string,
    body?: unknown,
    key?: string,
  ): Promise<unknown> => {
    const response = await http(method, path, body, key);
    if (response.status < 200 || response.status >= 300) {
      const code = z.object({
        error: z.object({ code: z.string().regex(/^[a-z0-9_.-]{1,100}$/) }),
      }).safeParse(response.body);
      throw new PolicyError(
        code.success ? code.data.error.code : "gateway.read_unavailable",
        "Development policy request failed",
      );
    }
    return z.object({ data: z.unknown() }).parse(response.body).data;
  };
  const nodes = z.array(
    z.object({ id: z.number().int(), roles: z.array(z.string()) }),
  ).parse(await request("GET", "/api/v1/nodes"));
  const development = new Set(
    nodes.filter((n) =>
      n.roles.includes("app-dev") && !n.roles.includes("app-prod")
    ).map((n) => n.id),
  );
  const discovery = Instance.pick({
    id: true,
    node_id: true,
    name: true,
    status: true,
    source_layout: true,
  }).extend({
    auto_deploy: z.object({ policy_owner: z.string() }),
  });
  const records = z.array(discovery).parse(
    await request("GET", "/api/v1/instances"),
  );
  const handles: { name: string }[] = [];
  const failures: string[] = [];
  for (const listed of records) {
    if (
      !development.has(listed.node_id) || listed.name !== "default" ||
      listed.source_layout !== "checkout" || listed.status !== "active" ||
      listed.auto_deploy.policy_owner !== "swamp" ||
      filter.length && !filter.includes(listed.id)
    ) continue;
    try {
      const instance = Instance.parse(
        await request("GET", `/api/v1/instances/${listed.id}`),
      );
      if (
        instance.id !== listed.id || instance.name !== "default" ||
        instance.source_layout !== "checkout" ||
        instance.node_id !== listed.node_id || instance.status !== "active" ||
        instance.auto_deploy.policy_owner !== "swamp"
      ) continue;
      const id = instance.id, name = `development-${id}`;
      const state = DevelopmentState.parse(await ctx.readResource(name) ?? {});
      let saved = JSON.stringify(state);
      const save = async () => {
        if (JSON.stringify(state) === saved) return;
        handles.push(await ctx.writeResource("development", name, state));
        saved = JSON.stringify(state);
      };
      const history = z.array(RowSchema).parse(
        await request("GET", `/api/v1/instances/${id}/deployments`),
      );
      const head = z.object({ head: sha }).parse(
        await request("GET", `/api/v1/instances/${id}/deployment-source/head`),
      ).head;
      const publish = async (
        result: string,
        operationId: number | null = null,
        routeOperationId: number | null = null,
      ) => {
        if (state.event) {
          await admissions.event(id, state.event.body, state.event.key);
          state.event = null;
          await save();
        }
        const changed = state.result !== result || state.commit !== head;
        if (changed) state.since = now;
        if (
          changed &&
          !["up_to_date", "deploying", "gate_pending"].includes(result)
        ) {
          state.event = {
            key: `dev.policy.${id}.${++state.sequence}`,
            body: {
              event: "activity",
              result,
              commit: head,
              operation_id: operationId,
              error_code: result === "failed"
                ? "deployment.automatic_failed"
                : null,
              message: null,
            },
          };
        }
        state.result = result;
        state.commit = head;
        await save();
        await request("PUT", `/api/v1/instances/${id}/auto-deploy/policy`, {
          resumed_after: instance.auto_deploy.resumed_after,
          last_deployment_id: Math.max(0, ...history.map((r) => r.id)),
          history_version: instance.auto_deploy.history_version,
          result,
          commit: head,
          head,
          since: state.since ?? now,
          error_code: result === "failed"
            ? "deployment.automatic_failed"
            : null,
          message: null,
          pause: null,
          ...(routeOperationId === null
            ? {}
            : { route_operation_id: routeOperationId }),
        });
        if (state.event) {
          await admissions.event(id, state.event.body, state.event.key);
          state.event = null;
          await save();
        }
        return operationId;
      };
      if (state.pending) {
        const intent = state.pending;
        const operation = intent.operation_id === null
          ? await admissions.receipt(id, intent.commit, intent.key) ??
            await admissions.resolve(id, intent.commit, intent.key)
          : await admissions.operation(intent.operation_id);
        if (!terminal(operation.status)) {
          await publish("deploying", operation.id);
          continue;
        }
        const manual = Math.max(
          0,
          ...history.filter((r) =>
            r.kind === "deploy" && r.status === "succeeded" &&
            !isAutomaticDeployment(r)
          ).map((r) => r.id),
        );
        const historyVisible =
          typeof operation.result?.deployment_id === "number" &&
          history.some((r) => r.id === operation.result?.deployment_id);
        if (
          !historyVisible &&
          !isAdmissionFence(operation) &&
          manual <= intent.manual_after
        ) {
          await publish("failed", operation.id);
          continue;
        }
        state.pending = null;
        await save();
        // The next observation must include the terminal history before another decision.
        await publish(
          operation.status === "succeeded" ? "up_to_date" : "failed",
          operation.id,
        );
        continue;
      }
      let holder: Operation | undefined;
      for (const status of ["queued", "running", "waiting"]) {
        const rows = z.array(
          z.object({
            id: z.number().int(),
            kind: z.string(),
            status: z.string(),
            target_type: z.string(),
            target_id: z.number().int(),
          }),
        ).parse(
          await request(
            "GET",
            `/api/v1/operations?target_type=instance&target_id=${id}&status=${status}`,
          ),
        );
        holder = rows.find((r) =>
          r.target_type === "instance" && r.target_id === id &&
          r.status === status
        ) as Operation | undefined;
        if (holder) break;
      }
      if (holder) {
        await publish("busy", holder.id);
        continue;
      }
      if (instance.development_projection_route_id !== null) {
        if (
          state.repair?.route_id !== instance.development_projection_route_id
        ) {
          state.repair = {
            route_id: instance.development_projection_route_id,
            key: `dev.route.${id}.${++state.sequence}`,
            operation_id: null,
          };
          await save();
        }
        const repair = state.repair;
        const operation = repair.operation_id === null
          ? z.object({ id: z.number().int().positive(), status: z.string() })
            .parse(
              await request(
                "PATCH",
                `/api/v1/routes/${repair.route_id}`,
                {},
                repair.key,
              ),
            )
          : await admissions.operation(repair.operation_id);
        repair.operation_id = operation.id;
        await save();
        if (operation.status === "succeeded") {
          await publish("busy", operation.id, operation.id);
          state.repair = null;
          await save();
        } else {
          await publish(
            terminal(operation.status) ? "failed" : "busy",
            operation.id,
          );
          if (terminal(operation.status)) {
            state.repair = null;
            await save();
          }
        }
        continue;
      }
      if (head === instance.seed_commit) {
        await publish("up_to_date");
        continue;
      }
      const manual = Math.max(
        0,
        ...history.filter((r) =>
          r.kind === "deploy" && !isAutomaticDeployment(r)
        )
          .map((r) => r.id),
      );
      const failures = history.filter((r) =>
        r.id > manual && r.kind === "deploy" && isAutomaticDeployment(r) &&
        r.commit === head && r.status === "failed" &&
        r.error_code !== "deployment.paused"
      );
      if (failures.length >= 3) {
        await publish("commit_failed");
        continue;
      }
      const last = failures.sort((a, b) => b.id - a.id)[0];
      if (
        last &&
        Date.parse(now) - Date.parse(last.finished_at ?? last.started_at) <
          600000 * 2 ** (failures.length - 1)
      ) {
        await publish("backing_off");
        continue;
      }
      state.pending = {
        commit: head,
        key: `dev.deploy.${id}.${++state.sequence}.${head}`,
        operation_id: null,
        manual_after: manual,
      };
      await save();
      // A lost response retains this exact intent. Later ticks use the shared receipt/resolution contract.
      let operation: Operation;
      try {
        operation = await admissions.deploy(id, head, state.pending.key);
      } catch (error) {
        if (
          error instanceof PolicyError && error.code === "operation.target_busy"
        ) {
          state.pending = null;
          await save();
          await publish("busy", error.operationId);
          continue;
        }
        throw error;
      }
      state.pending.operation_id = operation.id;
      await save();
      await publish("started", operation.id);
    } catch (error) {
      if (
        error instanceof PolicyError &&
        error.code === "instance.auto_deploy_observation_changed"
      ) continue;
      failures.push(
        `Instance ${listed.id}: ${
          error instanceof PolicyError
            ? error.code
            : "deployment.policy_unavailable"
        }`,
      );
    }
  }
  if (failures.length) throw new Error(failures.join("; "));
  return handles;
}
