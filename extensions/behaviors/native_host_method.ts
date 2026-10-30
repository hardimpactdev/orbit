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
  // The pinned compiled Swamp CLI forbids direct /proc reads. This fixed
  // read-only child inherits our cgroup; no caller selects its path or args.
  const cgroup = await new Deno.Command("/usr/bin/cat", {
    args: ["/proc/self/cgroup"],
    stdout: "piped",
    stderr: "null",
  }).output();
  if (!cgroup.success) throw new Error("native_host_lifetime_unavailable");
  const membership = new TextDecoder().decode(cgroup.stdout);
  const parent = /^0::\/system\.slice\/([a-zA-Z0-9@_.-]+\.service)$/m.exec(
    membership,
  )?.[1];
  if (!parent) throw new Error("native_host_lifetime_unavailable");
  const scratch = await Deno.makeTempDir({ prefix: "orbit-host-method-" });
  let child: Deno.ChildProcess | undefined;
  const unit = `orbit-swamp-host-${
    crypto.randomUUID().replaceAll("-", "")
  }.service`;
  const lease = `${scratch}/active`;
  await Deno.writeTextFile(lease, "active\n", { mode: 0o600 });
  let abort: (() => void) | undefined;
  let errorText = "";
  let readErrors: Promise<void> | undefined;
  const stop = AbortSignal.any([
    signal ?? new AbortController().signal,
    AbortSignal.timeout(seconds * 1000),
  ]);
  const stopNativeHost = async () => {
    const stopped = await new Deno.Command("/usr/bin/sudo", {
      args: ["-n", "/usr/bin/systemctl", "stop", unit],
      stdin: "null",
      stdout: "null",
      stderr: "null",
    }).output();
    await child!.status;
    await readErrors;
    if (!stopped.success) {
      const state = await new Deno.Command("/usr/bin/sudo", {
        args: [
          "-n",
          "/usr/bin/systemctl",
          "show",
          "--property=ActiveState",
          "--value",
          unit,
        ],
        stdout: "piped",
        stderr: "null",
      }).output();
      if (
        !["inactive", "failed"].includes(
          new TextDecoder().decode(state.stdout).trim(),
        )
      ) throw new Error("native_host_cleanup_failed");
    }
  };
  try {
    child = new Deno.Command("/usr/bin/sudo", {
      args: [
        "-n",
        "/usr/bin/systemd-run",
        "--quiet",
        "--wait",
        "--pipe",
        "--collect",
        "--service-type=exec",
        `--unit=${unit}`,
        `--uid=${Deno.uid()}`,
        `--gid=${Deno.gid()}`,
        `--working-directory=${runtime.repo}`,
        `--property=BindsTo=${parent}`,
        `--property=After=${parent}`,
        "--property=KillMode=control-group",
        "--property=SendSIGKILL=yes",
        `--property=TimeoutStopSec=${
          Math.max(1, Math.ceil(runtime.graceMs / 1000))
        }`,
        `--property=RuntimeMaxSec=${seconds + 10}`,
        ...Object.entries({
          HOME: runtime.home,
          PATH: `${runtime.home}/.local/bin:/usr/local/bin:/usr/bin:/bin`,
          TMPDIR: scratch,
          LANG: "C.UTF-8",
          SWAMP_HOME: `${runtime.home}/.swamp`,
          SWAMP_CONFIG_DIR: `${runtime.home}/.config/swamp`,
          SWAMP_API_KEY_FILE: `${runtime.home}/.config/swamp/api-key`,
          SWAMP_NO_UPDATE_CHECK: "1",
          SWAMP_NO_TELEMETRY: "1",
        }).map(([key, value]) => `--setenv=${key}=${value}`),
        "--",
        "/usr/bin/python3",
        "-I",
        `${runtime.repo}/bin/native-host-start.py`,
        lease,
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
    await Deno.remove(lease).catch((error) => {
      if (!(error instanceof Deno.errors.NotFound)) throw error;
    });
    if (child) {
      // Revoke first: a unit registered after stop still refuses before exec.
      await stopNativeHost();
    }
    if (abort) stop.removeEventListener("abort", abort);
    await Deno.remove(scratch, { recursive: true });
  }
}

export const behavior = {
  type: "@nckrtl/native-host-method",
  version: "2026.10.30.2",
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
