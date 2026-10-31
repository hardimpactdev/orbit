import { z } from "npm:zod@4.4.3";
import { runNodeAgent } from "../_lib/node-agent.ts";

const Version = z.string().regex(/^[0-9]+\.[0-9]+$/);
const Identity = z.object({
  user: z.string(),
  home: z.string(),
  version: Version,
  marker: z.string().min(1).max(256),
}).passthrough();
const Input = z.object({
  project_id: z.number().int().positive(),
  agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
  php: z.object({
    packages: z.record(z.string(), z.unknown()),
    profile: z.object({ version: Version }).passthrough(),
    runtime: Identity,
    refresh: Identity,
  }).strict().nullable(),
}).strict();
type Port = {
  invoke?: typeof runNodeAgent;
  current: () => Promise<{ cancel_requested: boolean }>;
  now?: () => number;
};
type Result = {
  confirmed: true;
  changed: boolean;
  deferred_count: number;
  stale_master_count: number;
} | { error_code: string };
class Refused extends Error {}
const Units = z.array(z.string().regex(/^[A-Za-z0-9@_.:-]{1,255}$/)).max(256)
  .default([]);

/** Local native DAG prerequisite; Gateway supplies immutable recipe/runtime data, never an executor. */
export async function refreshDeployPhp(
  raw: z.infer<typeof Input>,
  deadline: number,
  port: Port,
  signal?: AbortSignal,
): Promise<Result> {
  try {
    const input = Input.parse(raw), php = input.php;
    if (php === null) {
      return {
        confirmed: true,
        changed: false,
        deferred_count: 0,
        stale_master_count: 0,
      };
    }
    const user = `orbit-app-${input.project_id}`, home = `/home/${user}`;
    if (
      [php.runtime, php.refresh].some((part) =>
        part.user !== user || part.home !== home ||
        part.version !== php.profile.version
      ) || php.runtime.marker !== php.refresh.marker
    ) throw new Refused("deployment.identity_changed");
    const call = async (name: string, data: Record<string, unknown>) => {
      if ((await port.current()).cancel_requested || signal?.aborted) {
        throw new Refused("operation.cancelled");
      }
      const remaining = Math.floor(deadline - (port.now ?? Date.now)());
      if (!Number.isFinite(remaining) || remaining <= 0) {
        throw new Refused("deployment.deadline_exceeded");
      }
      const result = await (port.invoke ?? runNodeAgent)({
        name,
        input: data,
        pin: input.agent_sha256,
        sudo: true,
        mutates: true,
        deadlineMs: Math.min(remaining, 900000),
      }, signal);
      if (typeof result.error_code === "string") {
        throw new Refused(
          z.string().regex(/^[a-z0-9_.-]{1,100}$/).parse(result.error_code),
        );
      }
      return result;
    };
    const packages = z.object({
      outcome: z.enum(["current", "applied"]),
      data: z.object({ deferred: Units, stale_masters: Units }),
    }).parse(await call("php.install-build", php.packages));
    const profile = z.object({
      changed: z.boolean(),
      reloaded: z.boolean(),
      deferred: Units,
    }).parse(await call("php.configure-runtime", php.profile));
    const runtime = z.object({ published: z.boolean() }).parse(
      await call("php.converge-production-runtime", php.runtime),
    );
    z.object({ complete: z.literal(true) }).parse(
      await call("php.refresh-production-cache", php.refresh),
    );
    return {
      confirmed: true,
      changed: packages.outcome === "applied" || profile.changed ||
        runtime.published,
      deferred_count:
        new Set([...packages.data.deferred, ...profile.deferred]).size,
      stale_master_count: packages.data.stale_masters.length,
    };
  } catch (error) {
    return {
      error_code: error instanceof Refused
        ? error.message
        : "deployment.php_runtime_unconfirmed",
    };
  }
}
