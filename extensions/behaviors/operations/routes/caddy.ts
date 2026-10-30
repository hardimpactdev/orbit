import { z } from "npm:zod@4.4.3";
import { runNodeAgent } from "../_lib/node-agent.ts";

const Identity = z.object({
  node_id: z.number().int().positive(),
  user: z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/),
  agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
});
export const CaddyPublication = z.union([
  Identity.extend({
    caddy: z.object({
      content: z.string().max(4194304),
      version: z.string().regex(/^[a-f0-9]{32}$/),
      listen_addresses: z.array(z.ipv4()).max(1024),
      unserved: z.boolean(),
    }),
  }),
  Identity.extend({
    error_code: z.literal("caddy.render_failed"),
    stage: z.literal("render"),
  }),
]);

/** Fresh public input, applied locally while the canonical native model lock is held. */
export async function publishCaddy(
  raw: unknown,
  invoke = runNodeAgent,
  signal?: AbortSignal,
) {
  const input = CaddyPublication.parse(raw);
  if ("error_code" in input) {
    return { error_code: input.error_code, stage: input.stage };
  }
  const digest = Array.from(
    new Uint8Array(
      await crypto.subtle.digest(
        "SHA-256",
        new TextEncoder().encode(input.caddy.content),
      ),
    ),
    (b) => b.toString(16).padStart(2, "0"),
  ).join("");
  if (digest.slice(0, 32) !== input.caddy.version) {
    throw new Error("Caddy publication digest does not match");
  }
  const result = await invoke({
    name: "caddy.publish",
    input: input.caddy,
    pin: input.agent_sha256,
    sudo: true,
    mutates: true,
    deadlineMs: 120000,
    maxOutputBytes: 4096,
  }, signal);
  if (result.error_code !== undefined) {
    const error_code = z.string().regex(/^[a-z0-9_.-]{1,100}$/).parse(
      result.error_code,
    );
    return {
      error_code,
      ...(error_code === "caddy.publication_failed" &&
          result.stage === "validate"
        ? { stage: "validate" }
        : {}),
    };
  }
  const { result: outcome } = z.object({
    result: z.enum(["published", "unchanged"]),
  }).parse(result);
  return { ready: true, result: outcome, version: input.caddy.version };
}
