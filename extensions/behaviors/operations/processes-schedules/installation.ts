import { z } from "npm:zod@4.4.3";
import type { GatewayOperation } from "../_lib/direct.ts";
import { scheduleSystem } from "./schedule-system.ts";

export const ScheduleInstallation = z.object({
  id: z.string().uuid(),
  command_hash: z.string().regex(/^[a-f0-9]{64}$/),
  user: z.string().regex(/^[a-z_][a-z0-9_-]*$/),
  group: z.string().regex(/^[a-z_][a-z0-9_-]*$/),
  home: z.string().startsWith("/").max(4096),
  working_directory: z.string().startsWith("/").max(4096),
  shell: z.string().startsWith("/").max(4096),
  login_shell: z.boolean(),
  calendar: z.string().min(1).max(255),
  timeout_seconds: z.number().int().min(1).max(86400),
  desired: z.enum(["enabled", "disabled"]),
}).strict();

type Material = Pick<GatewayOperation, "withPrivateMaterial">;
type Refusal = { error_code: string };

/** Reuse the same private command custody and digest rule inside any native parent. */
export async function withScheduleCommand(
  input: { command_hash: string },
  privateMaterial: Material,
  use: (command: string) => Promise<void>,
): Promise<Refusal | undefined> {
  let refusal: Refusal | undefined;
  await privateMaterial.withPrivateMaterial(async (material) => {
    const command = z.string().min(1).max(4096).regex(/^[^\0\r\n]+$/).parse(
      material.command,
    );
    const digest = Array.from(
      new Uint8Array(
        await crypto.subtle.digest(
          "SHA-256",
          new TextEncoder().encode(command),
        ),
      ),
      (b) => b.toString(16).padStart(2, "0"),
    ).join("");
    if (digest !== input.command_hash) {
      refusal = { error_code: "schedule.artifact_conflict" };
      return;
    }
    await use(command);
  });
  return refusal;
}

/** Same-root timer installation; no command file, child admission, or projection. */
export async function installSchedule(
  input: z.infer<typeof ScheduleInstallation>,
  privateMaterial: Material,
  signal?: AbortSignal,
  invoke = scheduleSystem,
): Promise<{ ready: true } | Refusal> {
  const admitted = ScheduleInstallation.parse(input);
  let outcome: { ready: true } | Refusal | undefined;
  const refusal = await withScheduleCommand(
    admitted,
    privateMaterial,
    async (command) => {
      const result = await invoke(
        "schedule.install",
        { ...admitted, command },
        signal,
      );
      outcome = result.error_code === undefined ? { ready: true } : {
        error_code: z.string().regex(/^[a-z0-9_.-]{1,100}$/).parse(
          result.error_code,
        ),
      };
    },
  );
  if (refusal) return refusal;
  if (!outcome) throw new Error("Schedule installation produced no outcome");
  return outcome;
}
