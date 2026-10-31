import { processSystem, type ProcessSystemCommand } from "./process-system.ts";
import { viteSystem } from "./vite-system.ts";

export type LocalCommand =
  | ProcessSystemCommand
  | "vite.select-port"
  | "vite.set-traffic"
  | "vite.check-listener";
const process = processSystem();
const vite = viteSystem();

/** Fixed Process effects run in Swamp; the admitted agent digest is metadata only. */
export function localProcessAction(
  name: LocalCommand,
  input: Record<string, unknown>,
  _admittedDigest: string | undefined,
  signal?: AbortSignal,
): Promise<Record<string, unknown>> {
  return name.startsWith("process.")
    ? process(name as ProcessSystemCommand, input, signal)
    : vite(name, input, signal);
}
