import { z } from "npm:zod@4.4.3";
import { type LocalCommand, localProcessAction } from "./native-action.ts";
import { Input } from "./process-input.ts";

const Kind = z.enum([
  "process.start",
  "process.stop",
  "process.restart",
  "process.destroy",
]);
const State = z.string().regex(/^[a-z][a-z_-]{0,63}$/);
type Invoke = (
  name: LocalCommand,
  input: Record<string, unknown>,
  digest: string | undefined,
  signal?: AbortSignal,
) => Promise<Record<string, unknown>>;

/** The native behavior lock covers this complete, local Process mutation. */
export function processAction(invoke: Invoke = localProcessAction) {
  return async (
    kind: z.infer<typeof Kind>,
    input: z.infer<typeof Input>,
    signal?: AbortSignal,
  ) => {
    const artifact = {
      process_id: input.process_id,
      runtime: input.runtime,
      name: input.name,
    };
    const call = (name: LocalCommand, data: Record<string, unknown>) =>
      invoke(name, data, input.agent_sha256, signal);
    if (
      kind !== "process.stop" && kind !== "process.destroy" &&
      input.directory !== undefined
    ) {
      const release = await call("process.check-release", {
        directory: input.directory,
      });
      if (release.error_code !== undefined) {
        return { error_code: release.error_code };
      }
      if (release.available !== true) {
        return { error_code: "process.release_unavailable" };
      }
    }
    const result = kind === "process.destroy"
      ? await call("process.remove", { ...artifact, vite: input.vite })
      : await call("process.control", {
        ...artifact,
        on_demand: input.on_demand,
        operation: kind.slice("process.".length),
      });
    if (result.error_code !== undefined) {
      return { error_code: result.error_code };
    }
    if (kind === "process.destroy") return { ready: true };
    const status = await call("process.status", artifact);
    return status.error_code !== undefined
      ? { error_code: status.error_code }
      : { ready: true, state: State.parse(status.state) };
  };
}
