import { z } from "npm:zod@4.4.3";
import {
  localSystemCommand,
  type SystemTransport,
} from "../node/system_command.ts";
import { cleanupProgram } from "./cleanup_program.ts";

const Result = z.object({ generation: z.string().regex(/^[a-f0-9]{64}$/) })
  .strict();
/** Compose before Gateway FPM or scheduler activation in the parent's native DAG. */
export function prepareDocumentsCleanup(
  transport: SystemTransport = localSystemCommand,
) {
  return async (signal?: AbortSignal): Promise<z.infer<typeof Result>> => {
    let reply;
    try {
      reply = await transport({
        command: "/usr/bin/sudo",
        args: ["-n", "--", "/usr/bin/python3", "-I", "-c", cleanupProgram],
        timeoutMs: 60000,
        maxOutputBytes: 4096,
      }, signal);
    } catch {
      throw new Error("documents.cleanup_outcome_unknown");
    }
    if (!reply.success) throw new Error("documents.cleanup_outcome_unknown");
    let value;
    try {
      value = JSON.parse(reply.stdout);
    } catch {
      throw new Error("documents.cleanup_outcome_unknown");
    }
    if (value?.error_code === "project_documents.cleanup_state_unavailable") {
      throw new Error(value.error_code);
    }
    const parsed = Result.safeParse(value);
    if (!parsed.success) throw new Error("documents.cleanup_outcome_unknown");
    return parsed.data;
  };
}
export function documentsCleanup(prepare = prepareDocumentsCleanup()) {
  return {
    type: "@nckrtl/documents-cleanup",
    version: "2026.10.31.1",
    methods: {
      prepare: {
        description:
          "Invalidate the local Documents cleanup permit before Gateway services start",
        arguments: z.object({}).strict(),
        execute: (
          _args: Record<string, never>,
          ctx: { signal?: AbortSignal },
        ) => prepare(ctx.signal),
      },
    },
  };
}
export const behavior = documentsCleanup();
