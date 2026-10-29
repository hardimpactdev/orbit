import { z } from "npm:zod@4";
import { PackageTargetSchema } from "./_lib/package_target.ts";

const REPO = "/home/orbit/apps/orbit-swamp/default";
const HOME = "/home/orbit";
const targets = {
  project_scan: ["@nckrtl/dependency-scanner", "scan", 300],
  project_routine: ["orbit-gateway", "schedule_project", 180],
  project_work: ["orbit-gateway", "project_work", 120],
  dependency_prs: ["dependency-pr-reader", "refresh", 120],
  package_admit: ["orbit-gateway", "admit_package", 180],
  upgrade_scan: ["orbit-deps", "scan", 180],
  upgrade_plan: ["orbit-deps", "plan", 180],
} as const;
export type HostAction = keyof typeof targets;
const schemas = {
  project_scan: z.object({
    projectId: z.number().int().positive(),
    runId: z.string().min(1).max(256),
  }).strict(),
  project_routine: z.object({
    projectId: z.number().int().positive(),
    mode: z.literal("routine"),
  }).strict(),
  project_work: z.object({}).strict(),
  dependency_prs: z.object({}).strict(),
  package_admit: PackageTargetSchema,
  upgrade_scan: z.object({}).strict(),
  upgrade_plan: z.object({}).strict(),
};

/** Same-host native execution gives nested methods the real local context. */
export async function executeHostMethod(
  action: HostAction,
  input: Record<string, unknown>,
  signal?: AbortSignal,
  runtime = {
    repo: REPO,
    home: HOME,
    executable: `${HOME}/.local/bin/swamp`,
    graceMs: 10_000,
  },
) {
  signal?.throwIfAborted();
  const value: Record<string, unknown> = schemas[action].parse(input);
  const [definition, method, seconds] = targets[action];
  const scratch = await Deno.makeTempDir({ prefix: "orbit-host-method-" });
  let child: Deno.ChildProcess | undefined;
  let timer: ReturnType<typeof setTimeout> | undefined;
  let abort: (() => void) | undefined;
  let errorText = "";
  let readErrors: Promise<void> | undefined;
  const stop = AbortSignal.any([
    signal ?? new AbortController().signal,
    AbortSignal.timeout(seconds * 1000),
  ]);
  try {
    child = new Deno.Command("/usr/bin/setsid", {
      args: [
        runtime.executable,
        "behavior",
        "run",
        definition,
        method,
        ...(action === "project_scan"
          ? [`dependency-scanner-${value.projectId}`]
          : []),
        "--repo-dir",
        runtime.repo,
        "--stdin",
        "--literal-inputs",
        "--skip-reports",
        "--timeout",
        `${seconds}s`,
        "--json",
      ],
      cwd: runtime.repo,
      clearEnv: true,
      env: {
        HOME: runtime.home,
        PATH: `${runtime.home}/.local/bin:/usr/local/bin:/usr/bin:/bin`,
        TMPDIR: scratch,
        LANG: "C.UTF-8",
        SWAMP_HOME: `${runtime.home}/.swamp`,
        SWAMP_CONFIG_DIR: `${runtime.home}/.config/swamp`,
        SWAMP_API_KEY_FILE: `${runtime.home}/.config/swamp/api-key`,
        SWAMP_NO_UPDATE_CHECK: "1",
        SWAMP_NO_TELEMETRY: "1",
      },
      stdin: "piped",
      stdout: "null",
      stderr: "piped",
    }).spawn();
    readErrors = (async () => {
      for await (const part of child!.stderr) {
        if (errorText.length < 16384) {
          errorText += new TextDecoder().decode(part).slice(
            0,
            16384 - errorText.length,
          );
        }
      }
    })();
    const cancelled = new Promise<null>((resolve) => {
      abort = () => resolve(null);
      stop.addEventListener("abort", abort, { once: true });
      if (stop.aborted) abort();
    });
    const execution = (async () => {
      // Literal stdin is data, never CEL or an @file expansion.
      const writer = child!.stdin.getWriter();
      await writer.write(new TextEncoder().encode(JSON.stringify(value)));
      await writer.close();
      const status = await child!.status;
      // A descendant can outlive its parent and retain this pipe. Keep draining
      // inside the cancellation race so the deadline still kills the group.
      await readErrors;
      return status;
    })();
    const status = await Promise.race([execution, cancelled]);
    if (status === null) throw new Error("native_host_method_cancelled");
    if (!status.success) {
      const code = [
        "lock_timeout",
        "datastore_v3_writer_busy",
        "model_not_found",
        "unknown_model_type",
        "unknown_method",
        "missing_deps",
        "not_authenticated",
        "auth_gate_blocked",
        "method_execution_failed",
        "cancelled",
      ]
        .find((code) => new RegExp(`"code"\\s*:\\s*"${code}"`).test(errorText));
      throw new Error(`native_host_method_failed${code ? `:${code}` : ""}`);
    }
    return { dataHandles: [], action, exitCode: 0 };
  } finally {
    if (child) {
      const kill = async (name: string) => {
        await new Deno.Command("/bin/kill", {
          args: ["--signal", name, "--", `-${child!.pid}`],
          stdin: "null",
          stdout: "null",
          stderr: "null",
        }).output();
      };
      await kill("TERM");
      await Promise.race([
        child.status,
        new Promise<void>((resolve) => {
          timer = setTimeout(resolve, runtime.graceMs);
        }),
      ]);
      clearTimeout(timer);
      await kill("KILL");
      await child.status;
      await readErrors;
    }
    clearTimeout(timer);
    if (abort) stop.removeEventListener("abort", abort);
    await Deno.remove(scratch, { recursive: true });
  }
}

export const behavior = {
  type: "@nckrtl/native-host-method",
  version: "2026.10.29.1",
  globalArguments: z.object({}),
  methods: Object.fromEntries(
    (Object.keys(targets) as HostAction[]).map((action) => [action, {
      description: `Run ${targets[action][0]}.${
        targets[action][1]
      } in a separate native process on the authoritative Swamp host`,
      arguments: schemas[action],
      execute: (
        input: Record<string, unknown>,
        ctx: { signal?: AbortSignal },
      ) => executeHostMethod(action, input, ctx.signal),
    }]),
  ),
};
