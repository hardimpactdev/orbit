import { z } from "npm:zod@4.4.3";
import {
  type Gateway,
  type Observation,
  type Operation,
  PolicyError,
} from "./policy.ts";

export const gaps = {
  source:
    "G01: No Gateway read exposes a fresh credentialed branch head, first-parent commits, ancestry evidence, and exact GitHub Actions check runs. auto_deploy.head is the native tick's cached projection.",
  controls:
    "G02: instance:show omits auto_deploy_resumed_after and auto_deploy_state.blocked. Its pause projection depends on a native tick. Swamp cannot observe resume or recover rolled-away blocks after pruning without that writer.",
  history:
    "G03: instance:deployment:list/show omit kind. selected_release is not a substitute for deploy versus rollback. Pause and rolled-away history cannot be reconstructed faithfully.",
  alerts:
    "G04: No allowed Gateway primitive admits the release alert notifier (Activity, filed problem, signed webhook) or notable tick Activity. Swamp stores alert and Activity intents only.",
  projection:
    "G05: API/MCP/CLI/web auto_deploy reads still use Gateway-owned state. No supported read composition projects Swamp policy state into those surfaces.",
  recovery:
    "G06: operation:list/show omit request keys and provide no keyed receipt lookup. A lost admission response cannot be reconciled by exact request identity. Hold the Instance until that outcome is resolved.",
} as const;
export class GatewayGap extends Error {
  constructor(readonly gaps: string[]) {
    super(
      "Automatic deployment is unavailable until the Gateway gaps are closed.",
    );
  }
}
export interface Response {
  status: number;
  body: unknown;
}
export type Http = (
  method: "GET" | "POST",
  path: string,
  body?: unknown,
  key?: string,
) => Promise<Response>;
const Envelope = z.object({ data: z.unknown() });
const OperationSchema = z.object({
  id: z.number().int().positive(),
  target_type: z.string(),
  target_id: z.number().int().positive(),
  kind: z.string(),
  status: z.string(),
  attempts: z.number().int().nonnegative(),
  input: z.object({ commit: z.string().optional() }),
  triggered_by: z.string().nullable(),
  result: z.object({ deployment_id: z.number().optional() }).nullable(),
  error_code: z.string().nullable(),
});
const InstanceSchema = z.object({
  status: z.string(),
  auto_deploy: z.object({ enabled: z.boolean(), gate: z.string().nullable() }),
});

/** Only documented Gateway routes. There are no direct git, GitHub, SQL, or manual-start paths. */
export class GatewayClient implements Gateway {
  constructor(private http: Http) {}
  private async request(
    method: "GET" | "POST",
    path: string,
    body?: unknown,
    key?: string,
  ): Promise<unknown> {
    let response: Response;
    try {
      response = await this.http(method, path, body, key);
    } catch {
      throw new PolicyError(
        method === "POST"
          ? "automatic.outcome_unknown"
          : "gateway.read_unavailable",
        method === "POST"
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
      if (!error.success || method === "POST" && !knownRefusal) {
        throw new PolicyError(
          method === "POST"
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
        method === "POST"
          ? "automatic.outcome_unknown"
          : "gateway.read_unavailable",
        "The Gateway returned no readable receipt.",
      );
    }
    return parsed.data.data;
  }
  async observe(instance: number): Promise<Observation> {
    const parsed = InstanceSchema.safeParse(
      await this.request("GET", `/api/v1/instances/${instance}`),
    );
    if (!parsed.success) {
      throw new PolicyError(
        "gateway.read_unavailable",
        "The Gateway Instance response is unreadable.",
      );
    }
    const record = parsed.data;
    const inactive = !record.auto_deploy.enabled || record.status !== "active";
    if (inactive) {
      return {
        enabled: record.auto_deploy.enabled,
        production: false,
        active: record.status === "active",
        gate: record.auto_deploy.gate,
        repository: null,
        gateReadable: false,
        resumedAfter: 0,
        blocked: [],
        history: [],
        holder: null,
      };
    }
    // Probe the existing history contract, but never infer the missing kind from other fields.
    z.array(z.unknown()).parse(
      await this.request("GET", `/api/v1/instances/${instance}/deployments`),
    );
    throw new GatewayGap([
      gaps.source,
      gaps.controls,
      gaps.history,
      gaps.alerts,
      gaps.projection,
      gaps.recovery,
    ]);
  }
  head(_instance: number): Promise<string> {
    return Promise.reject(new GatewayGap([gaps.source]));
  }
  walk(_instance: number, _deployed: string): ReturnType<Gateway["walk"]> {
    return Promise.reject(new GatewayGap([gaps.source]));
  }
  checks(
    _instance: number,
    _commit: string,
    _name: string,
  ): ReturnType<Gateway["checks"]> {
    return Promise.reject(new GatewayGap([gaps.source]));
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
