import { z } from "npm:zod@4.4.3";

export const ErrorCode = z.string().regex(/^[a-z0-9_.-]+$/);
export const Completion = z.object({
  status: z.enum(["succeeded", "failed", "cancelled"]),
  result: z.record(z.string(), z.unknown()),
  error_code: ErrorCode.nullable(),
});
const Address = z.ipv4();
export const ExporterInput = z.object({
  address: Address,
  metrics_address: Address,
});
export const ExporterResult = z.union([
  z.object({ error_code: ErrorCode }),
  z.object({ changed: z.literal(true) }),
]);
export type Invocation = {
  step: string;
  transport: "agent" | "remote_program" | "gateway";
  name: string;
  input: Record<string, unknown>;
  parseResult: (result: unknown) => Record<string, unknown>;
  error: (result: Record<string, unknown>) => string | null;
  pending?: (result: Record<string, unknown>) => boolean;
  prove: (result: Record<string, unknown>) => boolean;
};

export function invocation(
  kind: string,
  input: Record<string, unknown>,
): Invocation {
  if (
    [
      "conn.gateway_sessions_revoke",
    ].includes(kind)
  ) {
    const environment = z.object({
      $secret: z.literal("conn.environment"),
      id: z.number().int().positive(),
    }).strict().parse(input.environment);
    const ids = z.array(z.number().int().positive()).max(10000).parse(
      input.pairing_ids,
    );
    const result = z.union([
      z.object({ error_code: ErrorCode }),
      z.object({
        backend: z.enum(["issued", "revoked"]),
        pairing_id: z.number().int().positive().optional(),
        pairing_link_id: z.string().optional(),
        expires_at: z.string().optional(),
        revoked_sessions: z.number().int().nonnegative().optional(),
      }),
    ]);
    return {
      step: kind === "conn.gateway_sessions_revoke"
        ? "gateway-sessions-revoke"
        : "pairing-revoke",
      transport: "gateway",
      name: kind === "conn.gateway_sessions_revoke"
        ? "conn.gateway-sessions-revoke"
        : "conn.pairing-revoke",
      input: {
        environment,
        pairing_ids: ids,
        ...(kind === "conn.gateway_sessions_revoke"
          ? {
            session_ids: z.array(z.string()).max(10000).parse(
              input.session_ids,
            ),
          }
          : {}),
      },
      parseResult: (value) => {
        const parsed = result.parse(value);
        return parsed;
      },
      error: remoteError,
      prove: (value) => value.backend === "revoked",
    };
  }
  if (
    [
      "websocket.app_rotate",
    ].includes(kind)
  ) {
    const identity = z.string().uuid().parse(input.registry_operation_id);
    const result = z.union([
      z.object({ error_code: ErrorCode }),
      z.object({
        outcome: z.enum(["succeeded", "pending", "superseded", "failed"]),
        data: z.object({
          backend: z.enum(["applied", "pending"]).optional(),
          error_code: ErrorCode.optional(),
        }),
      }),
    ]);
    return {
      step: "reverb-registry-applied",
      transport: "remote_program",
      name: "websocket.registry-applied",
      input: { registry_operation_id: identity },
      parseResult: (value) => result.parse(value),
      error: remoteError,
      pending: (value) => value.outcome === "pending",
      prove: (value) =>
        value.outcome === "succeeded" &&
        (value.data as Record<string, unknown>).backend === "applied",
    };
  }
  if (kind.startsWith("vault.")) {
    const action = z.enum(["setup", "sync", "teardown"]).parse(kind.slice(6));
    const reference = z.object({
      $secret: z.literal("vault.credentials"),
      id: z.number().int().positive(),
    }).strict().parse(input.configuration);
    const lists = z.array(z.string()).max(10000);
    const metadata = z.object({
      backend: z.enum(["configured", "synced", "absent"]).optional(),
      error_code: ErrorCode.optional(),
      email: z.string().optional(),
      organization_id: z.string().optional(),
      organization_name: z.string().optional(),
      collection_id: z.string().optional(),
      collection_name: z.string().optional(),
      sources: lists.optional(),
      cipher_ids: lists.optional(),
      revisions: lists.optional(),
      fingerprints: lists.optional(),
    });
    const result = z.union([
      z.object({ error_code: ErrorCode }),
      z.object({ outcome: z.enum(["succeeded", "failed"]), data: metadata }),
    ]);
    return {
      step: `vault-${action}`,
      transport: "remote_program",
      name: "vault.collection",
      input: { action, configuration: reference },
      parseResult: (value) => result.parse(value),
      error: (value) =>
        typeof value.error_code === "string"
          ? ErrorCode.parse(value.error_code)
          : typeof (value.data as Record<string, unknown> | undefined)
              ?.error_code === "string"
          ? ErrorCode.parse((value.data as Record<string, unknown>).error_code)
          : null,
      prove: (value) =>
        value.outcome === "succeeded" &&
        (value.data as Record<string, unknown>).backend ===
          ({ setup: "configured", sync: "synced", teardown: "absent" }[action]),
    };
  }
  const match = /^metrics\.(node_exporter|cadvisor)_(converge|remove)$/.exec(
    kind,
  );
  if (!match) throw new Error("integration.kind_invalid");
  const fields = ExporterInput.parse(input);
  return {
    step: `${match[1].replaceAll("_", "-")}-${match[2]}`,
    transport: "agent",
    name: match[1] === "node_exporter"
      ? "metrics.node-exporter"
      : "metrics.cadvisor",
    input: { ...fields, action: { operation: match[2] } },
    parseResult: (value) => ExporterResult.parse(value),
    error: (result) =>
      result.error_code === undefined
        ? null
        : ErrorCode.parse(result.error_code),
    prove: (result) => result.changed === true,
  };
}

function remoteError(value: Record<string, unknown>): string | null {
  const error = value.error_code ??
    (value.data as Record<string, unknown> | undefined)?.error_code;
  return error === undefined ? null : ErrorCode.parse(error);
}
