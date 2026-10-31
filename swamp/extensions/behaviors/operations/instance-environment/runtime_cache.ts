import { z } from "npm:zod@4.4.3";
import {
  localSystemCommand,
  type SystemTransport,
} from "../node/system_command.ts";
import { configCacheProgram } from "./runtime_cache_program.ts";

const Path = z.string().startsWith("/").max(4096).refine((value) =>
  !/[\0\r\n]/.test(value) &&
  value.slice(1).split("/").every((part) =>
    part && part !== "." && part !== ".."
  )
);
export const ConfigCacheInput = z.object({
  user: z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/).refine((user) =>
    user !== "root"
  ),
  home: Path,
  version: z.string().regex(/^[0-9]+\.[0-9]+$/),
  application: Path,
}).strict();
const Outcome = z.object({
  outcome: z.enum(["config_cached", "config_uncached", "no_current_release"]),
}).strict();

/** Build and boot a private candidate as the admitted application account. */
export async function refreshConfigCache(
  raw: z.infer<typeof ConfigCacheInput>,
  signal?: AbortSignal,
  transport: SystemTransport = localSystemCommand,
): Promise<z.infer<typeof Outcome>> {
  try {
    const result = await transport({
      command: "/usr/bin/sudo",
      args: ["-n", "--", "/usr/bin/python3", "-I", "-c", configCacheProgram],
      stdin: JSON.stringify(ConfigCacheInput.parse(raw)),
      timeoutMs: 130_000,
      maxOutputBytes: 1024,
    }, signal);
    if (!result.success) throw new Error();
    return Outcome.parse(JSON.parse(result.stdout));
  } catch {
    throw new Error("env.config_cache_refresh_failed");
  }
}
