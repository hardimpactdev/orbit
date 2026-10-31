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
  prove: (result: Record<string, unknown>) => boolean;
};

export function invocation(
  kind: string,
  input: Record<string, unknown>,
): Invocation {
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
