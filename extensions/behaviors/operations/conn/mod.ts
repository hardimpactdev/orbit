import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { type Context, Globals } from "../../operation-runner/mod.ts";
import {
  DirectArgs,
  directOperation,
  GatewayOperation,
} from "../_lib/direct.ts";

const Kind = z.enum([
  "conn.pairing_create",
  "conn.pairing_revoke",
  "conn.gateway_sessions_revoke",
]);
const Args = DirectArgs.extend({ kind: Kind });
const Id = z.string().regex(/^[a-zA-Z0-9_-]{1,1024}$/);
const Time = z.string().datetime({ offset: true });
const SafeResult = z.union([
  z.object({
    backend: z.literal("issued"),
    pairing_id: z.number().int().positive(),
    pairing_link_id: Id,
    expires_at: Time,
  }).strict(),
  z.object({
    backend: z.literal("revoked"),
    revoked_sessions: z.number().int().nonnegative(),
  }).strict(),
  z.object({ error_code: z.string().regex(/^conn\.[a-z_]{1,80}$/) }).strict(),
]);
const Input = z.object({
  action: z.string(),
  environment: z.object({
    url: z.string().url(),
    admin_session: z.string().min(1),
    id: z.number().int().positive(),
  }).strict(),
  labels: z.array(z.string().max(1024)).max(4096),
  links: z.array(Id).max(4096),
  pairing_id: z.number().int().positive().nullable(),
  issue_started: z.boolean(),
  issued: z.boolean(),
  pairing_link_id: Id.nullable(),
  expires_at: Time.nullable(),
}).strict();
const Session = z.object({
  sessionId: Id,
  current: z.boolean().optional(),
  client: z.object({ label: z.string().nullable().optional() }).optional(),
});
const Scopes = [
  "orchestration:read",
  "orchestration:operate",
  "settings:write",
  "providers:manage",
  "environment:maintain",
  "preview:operate",
  "diagnostics:read",
  "terminal:read",
  "terminal:operate",
  "source-control:write",
  "filesystem:read",
  "filesystem:write",
  "relay:read",
  "access:read",
  "access:write",
  "relay:write",
];
class Refusal extends Error {}
const refuse = (code: string): never => {
  throw new Refusal(code);
};
export type T3 = (
  method: "GET" | "POST",
  path: string,
  input: z.infer<typeof Input>,
  body?: unknown,
) => Promise<unknown>;

/** Secret-bearing input and output stay inside this call, outside native arguments/resources. */
export async function executeConn(
  args: z.infer<typeof Args>,
  ctx: Pick<Context, "readResource" | "writeResource">,
  gateway: GatewayOperation,
  t3: T3,
  safeInput: Record<string, unknown>,
) {
  let outcome: Record<string, unknown> = {
    error_code: "conn.response_invalid",
  };
  try {
    await gateway.withPrivateMaterial(async (material) => {
      try {
        const admitted = Input.parse({ ...safeInput, ...material });
        const result = (id: string, expires_at: string) => ({
          backend: "issued",
          pairing_id: admitted.pairing_id,
          pairing_link_id: id,
          expires_at,
        });
        if (args.kind === "conn.pairing_create") {
          if (admitted.issued) {
            outcome = SafeResult.parse(
              result(admitted.pairing_link_id!, admitted.expires_at!),
            );
            return;
          }
          const key = `issue-${args.operation_id}`;
          if (admitted.issue_started || await ctx.readResource(key)) {
            refuse("conn.pairing_outcome_unknown");
          }
          await ctx.writeResource("issue", key, { started: true });
          await gateway.project({
            status: "running",
            started_at: new Date().toISOString(),
            result: { backend: "issuing" },
          });
          const issued = z.object({
            id: Id,
            credential: z.string().min(1).max(16384),
            expiresAt: Time,
          }).parse(
            await t3("POST", "/api/auth/pairing-token", admitted, {
              label: admitted.labels[0],
              scopes: Scopes,
            }),
          );
          if (
            [issued.id, issued.expiresAt].some((value) =>
              value.includes(issued.credential) ||
              value.includes(admitted.environment.admin_session)
            )
          ) refuse("conn.response_invalid");
          const metadata = SafeResult.parse(
            result(issued.id, issued.expiresAt),
          );
          await gateway.project({
            status: "running",
            started_at: new Date().toISOString(),
            result: metadata,
          });
          await gateway.storeSecretOutput({ credential: issued.credential });
          outcome = metadata;
          return;
        }
        const stillCurrent = async (link?: string) => {
          const current = z.object({ input: z.record(z.string(), z.unknown()) })
            .parse(await gateway.read());
          let matches = false;
          await gateway.withPrivateMaterial((fresh) => {
            const value = Input.parse({ ...current.input, ...fresh });
            matches = value.environment.admin_session ===
                admitted.environment.admin_session &&
              value.environment.url === admitted.environment.url &&
              (link === undefined || value.links.includes(link));
            return Promise.resolve();
          });
          if (!matches) refuse("conn.environment_changed");
        };
        const inspect = async () =>
          z.array(Session).max(4096).parse(
            await t3("GET", "/api/auth/clients", admitted),
          );
        const sessions = admitted.labels.length ? await inspect() : [];
        let revoked = 0;
        for (const session of sessions) {
          if (
            session.current ||
            !admitted.labels.includes(session.client?.label ?? "")
          ) continue;
          const fresh = (await inspect()).find((value) =>
            value.sessionId === session.sessionId
          );
          if (!fresh) continue;
          if (
            fresh.current ||
            !admitted.labels.includes(fresh.client?.label ?? "")
          ) refuse("conn.session_owner_changed");
          await stillCurrent();
          const reply = z.object({ revoked: z.boolean() }).parse(
            await t3("POST", "/api/auth/clients/revoke", admitted, {
              sessionId: fresh.sessionId,
            }),
          );
          if (reply.revoked) revoked++;
        }
        for (const link of admitted.links) {
          await stillCurrent(link);
          z.object({ revoked: z.boolean() }).parse(
            await t3("POST", "/api/auth/pairing-links/revoke", admitted, {
              id: link,
            }),
          );
        }
        outcome = { backend: "revoked", revoked_sessions: revoked };
      } catch (error) {
        outcome = {
          error_code: error instanceof Refusal
            ? error.message
            : "conn.response_invalid",
        };
      }
    });
  } catch { /* Private callback diagnostics are never public. */ }
  return outcome;
}

export async function requestT3(
  method: "GET" | "POST",
  path: string,
  input: z.infer<typeof Input>,
  body?: unknown,
) {
  let response: Response;
  try {
    response = await fetch(input.environment.url.replace(/\/+$/, "") + path, {
      method,
      headers: {
        Authorization: `Bearer ${input.environment.admin_session}`,
        "Content-Type": "application/json",
      },
      redirect: "error",
      signal: AbortSignal.timeout(10000),
      ...(body === undefined ? {} : { body: JSON.stringify(body) }),
    });
  } catch {
    return refuse("conn.server_unreachable");
  }
  if (!response.ok) {
    await response.body?.cancel();
    refuse(
      response.status === 401
        ? "conn.session_rejected"
        : "conn.request_refused",
    );
  }
  const reader = response.body?.getReader();
  if (!reader) return refuse("conn.response_invalid");
  const chunks: Uint8Array[] = [];
  let length = 0;
  for (;;) {
    const chunk = await reader.read();
    if (chunk.done) break;
    length += chunk.value.length;
    if (length > 4 * 1024 * 1024) {
      await reader.cancel();
      refuse("conn.response_invalid");
    }
    chunks.push(chunk.value);
  }
  const bytes = new Uint8Array(length);
  let offset = 0;
  for (const chunk of chunks) {
    bytes.set(chunk, offset);
    offset += chunk.length;
  }
  return JSON.parse(new TextDecoder().decode(bytes));
}

export function connDirect(
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  t3: T3 = requestT3,
) {
  return {
    type: "@nckrtl/conn-direct",
    version: "2026.10.30.1",
    globalArguments: Globals,
    resources: {
      issue: {
        description: "Native issue checkpoint; contains no credential",
        schema: z.object({ started: z.literal(true) }),
        lifetime: "30d",
        garbageCollection: 1,
      },
    },
    methods: {
      apply: {
        description: "Execute one admitted Conn intent directly against T3",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) => {
          const http = makeHttp(ctx);
          return await directOperation(
            args,
            new GatewayOperation(http, args),
            (input) =>
              executeConn(
                args,
                ctx,
                new GatewayOperation(http, args),
                t3,
                input,
              ),
            SafeResult.parse,
            ctx.signal,
          );
        },
      },
    },
  };
}
export const behavior = { ...connDirect(), type: "@nckrtl/conn-direct" };
