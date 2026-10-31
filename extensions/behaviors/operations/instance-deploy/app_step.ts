import { z } from "npm:zod@4.4.3";
import type { Http } from "../../automatic-deploy/gateway.ts";
import { appStepRuntime } from "./app_step_runtime.ts";
import { VpRuntime } from "./vp_requirements.ts";

export const AppVpRuntime = VpRuntime.extend({
  owner: z.string().regex(/^[a-z_][a-z0-9_-]{0,63}$/).refine((x) =>
    x !== "root"
  ),
});
export type AppVpRuntime = z.infer<typeof AppVpRuntime>;

export const StepTarget = z.discriminatedUnion("variant", [
  z.object({
    variant: z.literal("release"),
    release: z.string().regex(/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/),
  }).strict(),
  z.object({
    variant: z.literal("checkout"),
    user: z.string().regex(/^[a-z_][a-z0-9_-]{0,63}$/).refine((x) =>
      x !== "root"
    ),
    directory: z.string().startsWith("/").max(4096),
    seed_path: z.string().max(4096),
    seed_commit: z.string().max(64),
  }).strict(),
]);
export const AppStep = z.object({
  project_id: z.number().int().positive(),
  instance_id: z.number().int().positive(),
  target: StepTarget,
  name: z.string().regex(/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/),
  phase: z.enum(["setup", "teardown", "before_activation", "after_activation"]),
  command_digest: z.string().regex(/^[a-f0-9]{64}$/),
  timeout_seconds: z.number().int().min(1).max(3600),
  vp_environment: AppVpRuntime.nullable().optional(),
}).strict();
export type AppStep = z.infer<typeof AppStep>;
export const AppStepResult = z.union([
  z.object({
    exit_code: z.number().int(),
    timed_out: z.boolean(),
    output_tail: z.string().max(2048).refine((value) =>
      new TextEncoder().encode(value).length <= 2048
    ),
    truncated: z.boolean(),
  }).strict(),
  z.object({ error_code: z.string().regex(/^[a-z0-9_.-]{1,100}$/) }).strict(),
]);
export type AppStepResult = z.infer<typeof AppStepResult>;
const Command = z.object({
  name: z.string(),
  phase: z.string().optional(),
  command: z.string().min(1).max(16384),
  timeout_seconds: z.number().int(),
});
export async function sha256(script: string) {
  return [
    ...new Uint8Array(
      await crypto.subtle.digest(
        "SHA-256",
        new TextEncoder().encode(script),
      ),
    ),
  ].map((b) => b.toString(16).padStart(2, "0")).join("");
}

/** Existing read API only; the private program never becomes a behavior argument/result. */
export async function currentProgram(
  http: Http,
  step: AppStep,
): Promise<string> {
  const development = step.target.variant === "checkout" &&
    !["setup", "teardown"].includes(step.phase);
  const path = (step.phase === "setup" || step.phase === "teardown")
    ? `/api/v1/projects/${step.project_id}/${step.phase}-steps`
    : development
    ? `/api/v1/projects/${step.project_id}/development-deploy-steps`
    : `/api/v1/instances/${step.instance_id}/deploy-steps`;
  try {
    if (development && step.phase !== "before_activation") throw new Error();
    const response = await http("GET", path);
    if (response.status !== 200) throw new Error();
    const rows =
      z.object({ data: z.array(Command).max(32) }).parse(response.body)
        .data;
    const matches = rows.filter((row) =>
      row.name === step.name &&
      (["setup", "teardown"].includes(step.phase) ||
        (development ? row.phase === undefined : row.phase === step.phase))
    );
    if (matches.length !== 1) throw new Error();
    const row = matches[0];
    if (
      row.timeout_seconds !== step.timeout_seconds ||
      new TextEncoder().encode(row.command).length > 16384 ||
      row.command.includes("\0") ||
      await sha256(row.command) !== step.command_digest
    ) throw new Error();
    return row.command;
  } catch {
    throw new Error("instance.step_not_admitted");
  }
}

/** One workflow-owned sudo step. User, directory and program are never caller-selected argv. */
export async function sudoAppStep(
  admitted: AppStep,
  script: string,
  signal?: AbortSignal,
): Promise<AppStepResult> {
  const step = AppStep.parse(admitted);
  if (
    new TextEncoder().encode(script).length > 16384 || script.includes("\0") ||
    await sha256(script) !== step.command_digest
  ) return { error_code: "instance.step_not_admitted" };
  signal?.throwIfAborted();
  const child = new Deno.Command("/usr/bin/sudo", {
    args: [
      "-n",
      "-u",
      step.target.variant === "release"
        ? `orbit-app-${step.project_id}`
        : step.target.user,
      "--",
      "/usr/bin/python3",
      "-I",
      "-c",
      appStepRuntime,
    ],
    cwd: "/",
    clearEnv: true,
    env: { PATH: "/usr/bin:/bin", LANG: "C.UTF-8" },
    stdin: "piped",
    stdout: "piped",
    stderr: "null",
  }).spawn();
  const abort = () => {
    try {
      child.kill("SIGTERM");
    } catch { /* exited */ }
  };
  signal?.addEventListener("abort", abort, { once: true });
  if (signal?.aborted) abort();
  const timer = setTimeout(abort, (step.timeout_seconds + 10) * 1000);
  let size = 0;
  const parts: Uint8Array[] = [];
  try {
    const writer = child.stdin.getWriter();
    await writer.write(new TextEncoder().encode(JSON.stringify({
      project_id: step.project_id,
      target: step.target,
      command_digest: step.command_digest,
      timeout_seconds: step.timeout_seconds,
      script,
      ...(step.vp_environment === undefined
        ? {}
        : { vp_environment: step.vp_environment }),
    })));
    await writer.close();
    for await (const part of child.stdout) {
      size += part.length;
      if (size > 16384) throw new Error();
      parts.push(part);
    }
    if (!(await child.status).success) throw new Error();
    const bytes = new Uint8Array(size);
    let offset = 0;
    for (const part of parts) {
      bytes.set(part, offset);
      offset += part.length;
    }
    return AppStepResult.parse(JSON.parse(new TextDecoder().decode(bytes)));
  } catch {
    throw new Error("instance.step_execution_unconfirmed");
  } finally {
    clearTimeout(timer);
    signal?.removeEventListener("abort", abort);
    abort();
    await child.status;
  }
}
