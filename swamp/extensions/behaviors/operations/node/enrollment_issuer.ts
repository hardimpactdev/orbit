import { z } from "npm:zod@4.4.3";
import {
  due,
  type Native,
  native,
  Token,
  WorkerCredentialDuration,
} from "../../node-worker/rotation.ts";

export const WorkerCredentials = z.object({
  enrollment_token: z.string().max(8192),
  server_token: z.string().max(8192),
}).strict();
export const WorkerEnrollmentInput = z.object({
  schema_version: z.literal(1),
  node_id: z.number().int().positive(),
  issuer_node_id: z.number().int().positive(),
  worker_user: z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/),
  selected: z.literal(true),
}).strict();

/** Existing native token types; issuer209 stores only the declared protected output. */
export function nodeEnrollmentIssuer(call: Native = native) {
  return async (
    raw: unknown,
    existing: unknown,
    store: (
      value: { worker_credentials: z.infer<typeof WorkerCredentials> },
    ) => Promise<void>,
    signal?: AbortSignal,
  ) => {
    const input = WorkerEnrollmentInput.parse(raw);
    const name = `orbit-node-${input.node_id}`;
    const validate = (raw: unknown) => {
      const value = WorkerCredentials.parse(raw);
      if (
        !value.enrollment_token.startsWith(name + ".") ||
        !value.server_token.startsWith(name + "-access.")
      ) {
        throw new Error("swamp.node_worker_not_ready");
      }
      return value;
    };
    signal?.throwIfAborted();
    if (existing !== null) {
      validate(existing);
      return { issued: true as const, node_id: input.node_id };
    }
    try {
      const tokens = z.array(Token).parse(
        await call(["worker", "token", "list"]),
      );
      const current = tokens.find((token) => token.name === name);
      if (current && !["revoked", "expired"].includes(current.state)) {
        await call(["worker", "token", "revoke", name]);
      }
      const enrollment = z.object({ token: z.string() }).parse(
        await call([
          "worker",
          "token",
          "create",
          name,
          "--duration",
          WorkerCredentialDuration,
        ]),
      ).token;
      const accessName = name + "-access";
      const accessTokens = z.array(Token).parse(
        await call(["access", "token", "list"]),
      );
      const access = accessTokens.find((token) => token.name === accessName);
      if (!access || ["revoked", "expired"].includes(access.state)) {
        await call([
          "access",
          "token",
          "mint",
          accessName,
          "--principal",
          `worker:${name}`,
          "--duration",
          WorkerCredentialDuration,
        ]);
      } else if (due(access)) {
        await call([
          "access",
          "token",
          "rotate",
          accessName,
          "--duration",
          WorkerCredentialDuration,
        ]);
      }
      const server = z.object({ token: z.string() }).parse(
        await call(["access", "token", "reveal", accessName, "--yes"]),
      ).token;
      await store({
        worker_credentials: validate({
          enrollment_token: enrollment,
          server_token: server,
        }),
      });
    } catch {
      // Native token command output can contain both existing credential types.
      throw new Error("swamp.node_worker_enrollment_uncertain");
    }
    return { issued: true as const, node_id: input.node_id };
  };
}
