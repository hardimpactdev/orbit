import { z } from "npm:zod@4.4.3";
import {
  type Decision,
  DecisionSchema,
  type Gateway,
  type Observation,
  type Operation,
  PolicyError,
  type PolicyEvent,
  RowSchema,
  sha,
} from "./policy.ts";

export interface Response {
  status: number;
  body: unknown;
}
export type Http = (
  method: "GET" | "POST" | "PUT",
  path: string,
  body?: unknown,
  key?: string,
) => Promise<Response>;
const productionNode = (roles: string[]) =>
  roles.includes("app-prod") && !roles.includes("app-dev");
const Envelope = z.object({ data: z.unknown() });
const OperationSchema = z.object({
  id: z.number().int().positive(),
  target_type: z.string(),
  target_id: z.number().int().positive(),
  kind: z.string(),
  status: z.string(),
  attempts: z.number().int().nonnegative(),
  input: z.preprocess(
    (value) => Array.isArray(value) && value.length === 0 ? {} : value,
    z.object({
      commit: z.string().nullable().optional(),
      caller_node_id: z.number().optional(),
    }).passthrough(),
  ),
  triggered_by: z.string().nullable(),
  result: z.object({ deployment_id: z.number().optional() }).passthrough()
    .nullable(),
  error_code: z.string().nullable(),
});
const ReceiptSchema = z.object({
  request_key: z.string(),
  found: z.boolean(),
  operation: OperationSchema.nullable(),
  deployment: RowSchema.nullable(),
});
const InstanceSchema = z.object({
  node_id: z.number().int().positive(),
  project_id: z.number().int().positive(),
  status: z.string(),
  auto_deploy: z.object({
    enabled: z.boolean(),
    gate: z.string().nullable(),
    resumed_after: z.number().int().nonnegative(),
    blocked: z.array(sha),
    history_version: z.string().regex(/^[0-9a-f]{64}$/).nullable(),
    result: z.string().nullable(),
    commit: sha.nullable(),
    head: sha.nullable(),
    warning: z.string().nullable(),
    since: z.string().nullable(),
    error_code: z.string().nullable(),
    message: z.string().nullable(),
    pause: DecisionSchema.shape.projection.unwrap().shape.pause,
    policy_owner: z.enum(["gateway", "swamp"]).optional(),
  }),
});

/** Only documented Gateway routes. There are no direct git, GitHub, SQL, or manual-start paths. */
export class GatewayClient implements Gateway {
  constructor(private http: Http) {}
  private async request(
    method: "GET" | "POST" | "PUT",
    path: string,
    body?: unknown,
    key?: string,
  ): Promise<unknown> {
    let response: Response;
    try {
      response = await this.http(method, path, body, key);
    } catch {
      throw new PolicyError(
        method !== "GET"
          ? "automatic.outcome_unknown"
          : "gateway.read_unavailable",
        method !== "GET"
          ? "The admission response was lost. Hold the target and reconcile its request key."
          : "The Gateway read failed.",
      );
    }
    if (response.status < 200 || response.status >= 300) {
      const error = z.object({
        error: z.object({
          code: z.string(),
          message: z.string(),
          details: z.object({ operation_id: z.number().optional() })
            .passthrough().optional(),
        }),
      }).safeParse(response.body);
      const knownRefusal = error.success && (
        response.status >= 400 && response.status < 500 ||
        response.status === 503 && (
            error.data.error.code === "operation.queue_unavailable" ||
            error.data.error.code === "operation.dispatch_failed" &&
              error.data.error.details?.operation_id !== undefined
          )
      );
      if (!error.success || method !== "GET" && !knownRefusal) {
        throw new PolicyError(
          method !== "GET"
            ? "automatic.outcome_unknown"
            : "gateway.read_unavailable",
          "The Gateway response cannot establish the effect outcome.",
        );
      }
      throw new PolicyError(
        error.data.error.code,
        error.data.error.message,
        error.data.error.details?.operation_id ?? null,
      );
    }
    const parsed = Envelope.safeParse(response.body);
    if (!parsed.success) {
      throw new PolicyError(
        method !== "GET"
          ? "automatic.outcome_unknown"
          : "gateway.read_unavailable",
        "The Gateway returned no readable receipt.",
      );
    }
    return parsed.data.data;
  }
  private async read<T>(path: string, schema: z.ZodType<T>): Promise<T> {
    const parsed = schema.safeParse(await this.request("GET", path));
    if (!parsed.success) {
      throw new PolicyError(
        "gateway.read_unavailable",
        "The Gateway response is unreadable: " + path,
      );
    }
    return parsed.data;
  }
  async discover(filter: number[] = []): Promise<number[]> {
    // Both list endpoints return complete arrays, without pagination.
    // The Gateway applies the admission eligibility rule, including active app-prod roles.
    const nodes = await this.read(
      "/api/v1/nodes",
      z.array(z.object({
        id: z.number().int().positive(),
        roles: z.array(z.string()),
      })),
    );
    const production = new Set(
      nodes.filter((node) => productionNode(node.roles)).map((node) => node.id),
    );
    const instances = await this.read(
      "/api/v1/instances?auto_deploy=true",
      z.array(z.object({
        id: z.number().int().positive(),
        node_id: z.number().int().positive(),
        auto_deploy: z.object({ enabled: z.boolean() }),
      })),
    );
    return [
      ...new Set(
        instances.filter((instance) =>
          instance.auto_deploy.enabled && production.has(instance.node_id) &&
          (!filter.length || filter.includes(instance.id))
        ).map((instance) => instance.id),
      ),
    ].sort((a, b) => a - b);
  }
  async guard(instance: number, observation: Observation): Promise<void> {
    if (observation.policyOwner !== "swamp") {
      throw new PolicyError(
        "instance.auto_deploy_policy_owner",
        "PHP owns production policy.",
      );
    }
    if (!observation.publication) {
      throw new PolicyError(
        "gateway.read_unavailable",
        "The Gateway projection is missing.",
      );
    }
    await this.publish(instance, {
      ...observation.publication,
      result: observation.publication.result ?? "not_deployed",
      since: observation.publication.since ?? new Date().toISOString(),
    }, observation);
  }
  async history(instance: number) {
    return await this.read(
      `/api/v1/instances/${instance}/deployments`,
      z.array(RowSchema),
    );
  }
  async observe(instance: number): Promise<Observation> {
    const record = await this.read(
      `/api/v1/instances/${instance}`,
      InstanceSchema,
    );
    const node = await this.read(
      `/api/v1/nodes/${record.node_id}`,
      z.object({ roles: z.array(z.string()) }),
    );
    const production = productionNode(node.roles);
    const controls = record.auto_deploy;
    const result: Observation = {
      enabled: controls.enabled,
      production,
      active: record.status === "active",
      gate: controls.gate,
      repository: null,
      gateReadable: false,
      resumedAfter: controls.resumed_after,
      blocked: controls.blocked,
      historyVersion: controls.history_version,
      policyOwner: controls.policy_owner,
      publication: DecisionSchema.shape.projection.unwrap().parse(controls),
      history: [],
      holder: null,
    };
    if (!result.enabled || !production || !result.active) return result;
    const project = await this.read(
      `/api/v1/projects/${record.project_id}`,
      z.object({ repository_url: z.string(), source_access: z.string() }),
    );
    const repository =
      /^(?:https:\/\/github\.com\/|ssh:\/\/git@github\.com\/|git@github\.com:)([^/]+\/[^/]+?)(?:\.git)?\/?$/
        .exec(
          project.repository_url,
        )?.[1] ?? null;
    result.repository = repository;
    result.gateReadable = repository !== null &&
      project.source_access === "github_app";
    result.history = await this.history(instance);
    // Listing is filtered by target and status; never mistake an unrelated operation for the holder.
    for (const status of ["queued", "running", "waiting"]) {
      const operations = await this.read(
        `/api/v1/operations?target_type=instance&target_id=${instance}&status=${status}`,
        z.array(OperationSchema),
      );
      const holder = operations.find((o) =>
        o.target_type === "instance" && o.target_id === instance &&
        o.status === status
      );
      if (holder) {
        result.holder = holder;
        break;
      }
    }
    const current = await this.read(
      `/api/v1/instances/${instance}`,
      InstanceSchema,
    );
    if (
      current.auto_deploy.policy_owner !== controls.policy_owner ||
      current.auto_deploy.history_version !== controls.history_version ||
      current.auto_deploy.resumed_after !== controls.resumed_after ||
      current.auto_deploy.gate !== controls.gate ||
      current.auto_deploy.enabled !== controls.enabled ||
      current.status !== record.status || current.node_id !== record.node_id ||
      current.project_id !== record.project_id
    ) {
      throw new PolicyError(
        "instance.auto_deploy_observation_changed",
        "Instance controls or deployment history changed during the policy reads.",
      );
    }
    return result;
  }
  async head(instance: number): Promise<string> {
    return (await this.read(
      `/api/v1/instances/${instance}/deployment-source/head`,
      z.object({ head: sha }),
    )).head;
  }
  async walk(instance: number, deployed: string): ReturnType<Gateway["walk"]> {
    const walk = await this.read(
      `/api/v1/instances/${instance}/deployment-source/commits?base=${
        encodeURIComponent(deployed)
      }`,
      z.object({
        head: sha,
        reached_base: z.boolean(),
        truncated: z.boolean(),
        commits: z.array(
          z.object({
            sha,
            parents: z.array(sha),
            descends_from_deployed: z.boolean(),
          }),
        ).max(20),
      }),
    );
    if (walk.truncated) {
      throw new PolicyError(
        "instance.auto_deploy_gate_unavailable",
        "The first-parent walk is truncated; no commit can be selected.",
      );
    }
    return {
      head: walk.head,
      commits: walk.commits.map((c) => ({
        sha: c.sha,
        parents: c.parents,
        descendsFromDeployed: c.descends_from_deployed,
      })),
    };
  }
  async checks(
    instance: number,
    commit: string,
    name: string,
  ): ReturnType<Gateway["checks"]> {
    const checks = await this.read(
      `/api/v1/instances/${instance}/deployment-source/checks?commit=${
        encodeURIComponent(commit)
      }`,
      z.object({
        gate: z.string().nullable(),
        runs: z.array(
          z.object({
            name: z.string(),
            head_sha: sha.nullable(),
            app: z.string().nullable(),
            status: z.string().nullable(),
            conclusion: z.string().nullable(),
          }),
        ),
      }),
    );
    if (checks.gate !== name) {
      throw new PolicyError(
        "instance.auto_deploy_observation_changed",
        "The Project gate changed during selection.",
      );
    }
    return checks.runs;
  }
  async receipt(
    instance: number,
    commit: string,
    key: string,
  ): Promise<Operation | null> {
    const receipt = await this.read(
      `/api/v1/instances/${instance}/deploy/automatic/receipt?request_key=${
        encodeURIComponent(key)
      }&commit=${encodeURIComponent(commit)}`,
      ReceiptSchema,
    );
    return this.receiptOperation(instance, commit, key, receipt);
  }
  async resolve(
    instance: number,
    commit: string,
    key: string,
  ): Promise<Operation> {
    const parsed = ReceiptSchema.safeParse(
      await this.request(
        "POST",
        `/api/v1/instances/${instance}/deploy/automatic/resolve`,
        { request_key: key, commit },
      ),
    );
    if (!parsed.success) {
      throw new PolicyError(
        "automatic.outcome_unknown",
        "The admission resolution returned no readable receipt.",
      );
    }
    const operation = this.receiptOperation(instance, commit, key, parsed.data);
    if (!operation) {
      throw new PolicyError(
        "automatic.outcome_unknown",
        "Admission resolution did not return its operation.",
      );
    }
    return operation;
  }
  private receiptOperation(
    instance: number,
    commit: string,
    key: string,
    receipt: z.infer<typeof ReceiptSchema>,
  ): Operation | null {
    const op = receipt.operation;
    if (
      receipt.request_key !== key || receipt.found !== (op !== null) ||
      op && (op.target_type !== "instance" || op.target_id !== instance ||
          op.kind !== "instance.deploy" || op.input.commit !== commit ||
          op.triggered_by !== "orbit:auto-deploy" || !op.input.caller_node_id)
    ) {
      throw new PolicyError(
        "gateway.read_unavailable",
        "The automatic receipt identity does not match.",
      );
    }
    return op;
  }
  async event(
    instance: number,
    body: PolicyEvent,
    key: string,
  ): Promise<Operation> {
    const parsed = OperationSchema.safeParse(
      await this.request(
        "POST",
        `/api/v1/instances/${instance}/auto-deploy/events`,
        body,
        key,
      ),
    );
    if (
      !parsed.success || parsed.data.kind !== "instance.policy_event" ||
      parsed.data.target_type !== "instance_policy" ||
      parsed.data.target_id !== instance
    ) {
      throw new PolicyError(
        "automatic.outcome_unknown",
        "The policy event returned an unreadable operation.",
      );
    }
    return parsed.data;
  }
  async publish(
    instance: number,
    projection: Decision["projection"],
    observed: Observation,
  ): Promise<void> {
    const p = projection;
    const fields = [
      "result",
      "commit",
      "head",
      "since",
      "error_code",
      "message",
      "pause",
    ] as const;
    if (
      observed.publication &&
      fields.every((key) =>
        JSON.stringify(p[key]) === JSON.stringify(observed.publication![key])
      )
    ) return;
    await this.request(
      "PUT",
      `/api/v1/instances/${instance}/auto-deploy/policy`,
      {
        resumed_after: observed.resumedAfter,
        last_deployment_id: Math.max(0, ...observed.history.map((r) => r.id)),
        history_version: observed.historyVersion,
        result: p.result,
        commit: p.commit,
        head: p.head,
        since: p.since,
        error_code: p.error_code,
        message: p.message,
        pause: p.pause,
      },
    );
  }
  async deploy(
    instance: number,
    commit: string,
    key: string,
  ): Promise<Operation> {
    const receipt = OperationSchema.safeParse(
      await this.request(
        "POST",
        `/api/v1/instances/${instance}/deploy/automatic`,
        { commit },
        key,
      ),
    );
    if (
      !receipt.success || receipt.data.target_type !== "instance" ||
      receipt.data.target_id !== instance ||
      receipt.data.kind !== "instance.deploy" ||
      receipt.data.input.commit !== commit ||
      receipt.data.triggered_by !== "orbit:auto-deploy"
    ) {
      throw new PolicyError(
        "automatic.outcome_unknown",
        "The automatic start returned an unreadable operation.",
      );
    }
    return receipt.data;
  }
  async operation(id: number): Promise<Operation> {
    const parsed = OperationSchema.safeParse(
      await this.request("GET", `/api/v1/operations/${id}`),
    );
    if (!parsed.success || parsed.data.id !== id) {
      throw new PolicyError(
        "gateway.read_unavailable",
        "The Gateway operation response is unreadable.",
      );
    }
    return parsed.data;
  }
}

/** Services' WireGuard peer provides authority. TLS uses Orbit's root CA; no bearer identity is invented. */
export function gatewayHttp(
  url: string,
  caFile: string,
  signal?: AbortSignal,
): Http {
  return async (method, path, body, key) => {
    const client = Deno.createHttpClient({
      caCerts: [await Deno.readTextFile(caFile)],
    });
    try {
      const response = await fetch(new URL(path, url), {
        method,
        client,
        redirect: "error",
        signal: signal
          ? AbortSignal.any([signal, AbortSignal.timeout(60_000)])
          : AbortSignal.timeout(60_000),
        headers: {
          Accept: "application/json",
          ...(body === undefined ? {} : { "Content-Type": "application/json" }),
          ...(key ? { "Idempotency-Key": key } : {}),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
      });
      return { status: response.status, body: await response.json() };
    } finally {
      client.close();
    }
  };
}
