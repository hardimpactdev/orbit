import { z } from "npm:zod@4.4.3";

export const Input = z.object({
  node_id: z.number().int().positive(),
  // Each field is Gateway-admitted intent. The Gateway independently enforces
  // the exact fields and fixed values of every named invocation.
}).catchall(z.unknown());
export const Completion = z.object({
  status: z.enum(["succeeded", "failed", "cancelled"]),
  result: z.record(z.string(), z.unknown()),
  error_code: z.string().regex(/^[a-z0-9_.-]+$/).nullable(),
});
export type Call = {
  step: string;
  transport?: "agent" | "gateway";
  name: string;
  input: Record<string, unknown>;
};
export const scheduleFields = {
  create: [
    "id",
    "user",
    "group",
    "home",
    "working_directory",
    "shell",
    "login_shell",
    "command",
    "calendar",
    "timeout_seconds",
    "desired",
  ],
  destroy: ["id", "group", "cascade"],
  enable: ["id", "user", "working_directory"],
  run: ["id", "user", "working_directory"],
} as const;
export function pick(
  input: Record<string, unknown>,
  fields: readonly string[],
) {
  return Object.fromEntries(
    fields.filter((field) => field in input).map((
      field,
    ) => [field, input[field]]),
  );
}
export function scheduleCall(
  kind: string,
  input: Record<string, unknown>,
): Call {
  const verb = kind.slice("schedule.".length) as keyof typeof scheduleFields;
  if (!(verb in scheduleFields)) throw new Error("Wrong Schedule kind");
  const command = {
    create: "install",
    destroy: "remove",
    enable: "activate",
    run: "run",
  }[verb];
  return {
    step: `schedule-${command}`,
    name: `schedule.${command}`,
    input: pick(input, scheduleFields[verb]),
  };
}
