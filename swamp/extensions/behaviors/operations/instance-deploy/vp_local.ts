import { localCommand } from "../_lib/local-worker.ts";
import { VP_LOCAL } from "./vp_local_python.ts";
export type VpLocalMode =
  | "project"
  | "scope"
  | "version"
  | "inspect"
  | "install"
  | "prune";
export type VpLocalOptions = {
  user: string;
  platform: "linux" | "macos";
  architecture: "x86_64" | "arm64";
  deadline: number;
  signal?: AbortSignal;
  /** Trusted fixture configuration; never populated from an admitted intent. */
  fixture?: {
    home?: string;
    opt?: string;
    root_uid?: number;
    root_gid?: number;
    fixtures?: Record<string, unknown>;
    fixture_only?: boolean;
    unprivileged?: boolean;
  };
};
export type VpLocal = (
  mode: VpLocalMode,
  input: Record<string, unknown>,
  options: VpLocalOptions,
) => Promise<Record<string, unknown>>;
/** Runs fixed Swamp-owned primitives under the admitted non-root OS identity, without Project hooks. */
export const vpLocal: VpLocal = async (mode, input, options) => {
  const remaining = options.deadline - Date.now();
  if (remaining <= 0) return { error_code: "deployment.deadline_exceeded" };
  if (options.signal?.aborted) return { error_code: "operation.cancelled" };
  if (
    !/^[a-z_][a-z0-9_-]{0,63}$/.test(options.user) || options.user === "root"
  ) return { error_code: "tool.store_invalid" };
  const controller = new AbortController(),
    timer = setTimeout(() => controller.abort(), remaining);
  const abort = () => controller.abort();
  options.signal?.addEventListener("abort", abort, { once: true });
  try {
    const child = localCommand(
      options.fixture?.unprivileged ? "python3" : "sudo",
      {
        args: [
          ...(options.fixture?.unprivileged
            ? []
            : ["-n", "-H", "-u", options.user, "--", "python3"]),
          "-I",
          "-c",
          VP_LOCAL,
        ],
        clearEnv: true,
        env: { PATH: "/usr/local/bin:/usr/bin:/bin", LANG: "C.UTF-8" },
        stdin: "piped",
        stdout: "piped",
        stderr: "null",
        signal: controller.signal,
      },
    ).spawn();
    const writer = child.stdin.getWriter();
    await writer.write(
      new TextEncoder().encode(
        JSON.stringify({
          mode,
          input,
          options: {
            user: options.user,
            platform: options.platform,
            architecture: options.architecture,
            deadline: options.deadline,
            ...options.fixture,
          },
        }),
      ),
    );
    await writer.close();
    const output = await child.output();
    if (!output.success || output.stdout.length > 32 * 1024 * 1024) {
      throw new Error("tool.store_failed");
    }
    return JSON.parse(new TextDecoder().decode(output.stdout));
  } finally {
    clearTimeout(timer);
    options.signal?.removeEventListener("abort", abort);
  }
};
