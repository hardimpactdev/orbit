import { z } from "npm:zod@4.4.3";
import { NodeExecution } from "./execution.ts";
import { ErrorCode } from "./contract.ts";

const Connection = z.object({
  bootstrap_host: z.string().min(1),
  bootstrap_port: z.number().int().min(1).max(65535),
  bootstrap_fingerprint: z.string().regex(/^SHA256:[A-Za-z0-9+/]{43}$/),
  bootstrap_user: z.string().min(1),
  bootstrap_identity: z.object({
    $secret: z.literal("node.bootstrap_ssh_identity"),
    id: z.number().int().positive(),
  }).strict(),
});

/** First contact uses only the Gateway's immutable, reference-only bootstrap input. */
export async function bootstrap(execution: NodeExecution) {
  const operation = execution.operation;
  if (operation.cancel_requested && operation.steps.length === 0) {
    return { status: "cancelled" as const, result: {}, error_code: null };
  }
  const probe = operation.kind === "node.bootstrap_probe";
  const rollback = operation.kind === "node.network_rollback";
  const input = probe
    ? Connection.extend({
      command_input: z.object({
        expected_architecture: z.enum(["x86_64", "amd64", "arm64", "aarch64"])
          .nullable(),
      }).strict(),
    }).strict().parse(operation.input)
    : Connection.extend({
      agent_pin: z.string().regex(/^[0-9a-f]{64}$/),
      command_input: z.preprocess(
        (v) => Array.isArray(v) && v.length === 0 ? {} : v,
        z.object({}).strict(),
      ),
    }).strict().parse(operation.input);
  if (input.bootstrap_identity.id !== operation.node_id) {
    throw new Error("Bootstrap credential reference belongs to another Node");
  }
  const result = await execution.run(
    probe ? "bootstrap-probe" : rollback ? "network-rollback" : "agent-install",
    "bootstrap-ssh",
    probe
      ? "node.probe-bootstrap"
      : rollback
      ? "wireguard.rollback-peer"
      : "agent.install",
    input,
  );
  const normalize = (value: unknown) =>
    value === "amd64" ? "x86_64" : value === "aarch64" ? "arm64" : value;
  const expected = probe
    ? normalize(input.command_input.expected_architecture)
    : null;
  const mismatch = expected !== null &&
    expected !== normalize(result.architecture);
  const error = result.error_code === undefined
    ? (rollback || result[probe ? "probed" : "installed"] === true)
      ? mismatch ? "node.architecture_mismatch" : null
      : "node.bootstrap_verification_failed"
    : ErrorCode.parse(result.error_code);
  return {
    status: error === "operation.cancelled"
      ? "cancelled" as const
      : error
      ? "failed" as const
      : "succeeded" as const,
    result,
    error_code: error,
  };
}
