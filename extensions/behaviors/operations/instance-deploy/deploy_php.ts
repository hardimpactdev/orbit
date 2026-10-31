import { z } from "npm:zod@4.4.3";
import {
  installPhpBuild,
  PhpBuildInput,
  PhpLocalError,
} from "../php/build_local.ts";
import {
  configurePhpRuntime,
  PhpRuntimeProfileInput,
} from "../php/profile_local.ts";
import {
  convergeProductionRuntime,
  ProductionCacheInputSchema,
  ProductionRuntimeInputSchema,
  refreshProductionCache,
} from "../php/runtime_local.ts";

export const DeployPhpInput = z.object({
  project_id: z.number().int().positive(),
  php: z.object({
    packages: PhpBuildInput,
    profile: PhpRuntimeProfileInput,
    runtime: ProductionRuntimeInputSchema,
    refresh: ProductionCacheInputSchema,
  }).strict().nullable(),
}).strict();
type Port = {
  current: () => Promise<{ cancel_requested: boolean }>;
  now?: () => number;
  mutation?: <T>(action: () => Promise<T>) => Promise<T>;
  install?: typeof installPhpBuild;
  profile?: typeof configurePhpRuntime;
  runtime?: typeof convergeProductionRuntime;
  refresh?: typeof refreshProductionCache;
};
type Result = {
  confirmed: true;
  changed: boolean;
  deferred_count: number;
  stale_master_count: number;
} | { error_code: string };
class Refused extends Error {}

/** Canonical native PHP actions composed as steps of this admitted parent. */
export async function refreshDeployPhp(
  raw: z.input<typeof DeployPhpInput>,
  deadline: number,
  port: Port,
  signal?: AbortSignal,
): Promise<Result> {
  try {
    const input = DeployPhpInput.parse(raw), php = input.php;
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
      ) || php.runtime.marker !== php.refresh.marker ||
      php.runtime.mode !== "converge"
    ) throw new Refused("deployment.identity_changed");
    const call = async <T>(action: () => Promise<T>) => {
      if ((await port.current()).cancel_requested || signal?.aborted) {
        throw new Refused("operation.cancelled");
      }
      if (deadline <= (port.now ?? Date.now)()) {
        throw new Refused("deployment.deadline_exceeded");
      }
      try {
        return await (port.mutation ? port.mutation(action) : action());
      } catch (error) {
        if (error instanceof PhpLocalError && error.restored === true) {
          throw new Refused(error.code);
        }
        throw new Refused("deployment.execution_unconfirmed");
      }
    };
    const packages = await call(() =>
      (port.install ?? installPhpBuild)(php.packages, signal)
    );
    const profile = await call(() =>
      (port.profile ?? configurePhpRuntime)(php.profile, signal)
    );
    const runtime = await call(() =>
      (port.runtime ?? convergeProductionRuntime)(
        php.runtime,
        signal,
      )
    );
    await call(() =>
      (port.refresh ?? refreshProductionCache)(
        php.refresh,
        signal,
      )
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
