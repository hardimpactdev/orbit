import { z } from "npm:zod@4.4.3";
import {
  DeploySourceLocal,
  LocalDeployError,
  localDeploySource,
} from "./deploy_local.ts";
import {
  DeploySourceInput,
  productionDeployHome,
  Release,
} from "./deploy_source.ts";

/** `current` is the root's cancellation policy; after activation it may ignore cooperative cancellation. */
type Port = {
  local?: DeploySourceLocal;
  current: () => Promise<{ cancel_requested: boolean }>;
  now?: () => number;
};
class Refused extends Error {}
const code = (error: unknown, fallback: string) =>
  error instanceof Refused ? error.message : fallback;
function local(
  _input: DeploySourceInput,
  deadline: number,
  port: Port,
  signal?: AbortSignal,
) {
  const effects = port.local ?? localDeploySource({ deadline, signal });
  return async <T>(
    action: (effects: DeploySourceLocal) => Promise<T>,
    mutates: boolean,
  ): Promise<T> => {
    if ((await port.current()).cancel_requested || signal?.aborted) {
      throw new Refused("operation.cancelled");
    }
    if (deadline - (port.now ?? Date.now)() <= 0) {
      throw new Refused("deployment.deadline_exceeded");
    }
    try {
      return await action(effects);
    } catch (error) {
      throw new Refused(
        error instanceof LocalDeployError
          ? error.code
          : mutates
          ? "deployment.execution_unconfirmed"
          : "deployment.interrupted",
      );
    }
  };
}
function candidate(input: DeploySourceInput, raw: Release | null) {
  const parsed = Release.parse(raw);
  if (
    parsed.name !== input.release_name ||
    (input.commit !== null && input.commit !== parsed.commit)
  ) throw new Refused("deployment.identity_changed");
  return parsed;
}

/** Native parent DAG action. Only a matching local acknowledgement confirms activation. */
export async function activateDeployRelease(
  raw: DeploySourceInput,
  prepared: Release | null,
  deadline: number,
  port: Port,
  signal?: AbortSignal,
): Promise<
  { selected_release: Release | null; selection_confirmed: true } | {
    error_code: string;
    selection_confirmed: false;
  }
> {
  try {
    const input = DeploySourceInput.parse(raw);
    if (input.deployment.variant === "development") {
      if (prepared !== null) throw new Refused("deployment.identity_changed");
      return { selected_release: null, selection_confirmed: true };
    }
    let home: ReturnType<typeof productionDeployHome>;
    try {
      home = productionDeployHome(input);
    } catch {
      throw new Refused("deployment.identity_changed");
    }
    const release = candidate(input, prepared);
    const activated = Release.parse(
      await local(input, deadline, port, signal)(
        (effects) => effects.activateRelease(home, release),
        true,
      ),
    );
    if (
      activated.name !== release.name || activated.commit !== release.commit
    ) throw new Error();
    return { selected_release: activated, selection_confirmed: true };
  } catch (error) {
    return {
      error_code: code(error, "deployment.interrupted"),
      selection_confirmed: false,
    };
  }
}

/** Prune only after the local selected release still matches this run's admitted candidate. */
export async function pruneDeployReleases(
  raw: DeploySourceInput,
  selected: Release | null,
  previous: Release | null,
  retained: number,
  deadline: number,
  port: Port,
  signal?: AbortSignal,
): Promise<{ removed: string[]; in_use: string[] } | { error_code: string }> {
  try {
    const input = DeploySourceInput.parse(raw);
    if (input.deployment.variant === "development") {
      if (selected !== null || previous !== null) {
        throw new Refused("deployment.identity_changed");
      }
      return { removed: [], in_use: [] };
    }
    let home: ReturnType<typeof productionDeployHome>;
    try {
      home = productionDeployHome(input);
    } catch {
      throw new Refused("deployment.identity_changed");
    }
    if (input.deployment.variant === "rollback") {
      throw new Refused("deployment.identity_changed");
    }
    const release = candidate(input, selected),
      prior = previous === null ? null : Release.parse(previous);
    const limit = z.literal(3).parse(retained);
    const call = local(input, deadline, port, signal);
    const inspection = z.object({ release: Release.nullable() }).parse(
      await call((effects) => effects.inspectRelease(home), false),
    );
    if (
      inspection.release?.name !== release.name ||
      inspection.release?.commit !== release.commit
    ) throw new Refused("deployment.selection_changed");
    const result = z.object({
      removed: z.array(Release.shape.name).max(256),
      in_use: z.array(Release.shape.name).max(256),
    }).strict().parse(
      await call((effects) =>
        effects.pruneReleases(home, {
          limit,
          selected: release.name,
          previous: prior?.name ?? null,
        }), true),
    );
    const names = [...result.removed, ...result.in_use];
    if (
      new Set(names).size !== names.length ||
      names.some((name) => name === release.name || name === prior?.name)
    ) throw new Error();
    return result;
  } catch (error) {
    return { error_code: code(error, "deployment.cleanup_unconfirmed") };
  }
}

/** Read-only retained selection for a future rollback parent. No Git/private material is read. */
export async function inspectRetainedDeployRelease(
  raw: DeploySourceInput,
  name: string,
  deadline: number,
  port: Port,
  signal?: AbortSignal,
): Promise<
  { release: Release; previous: Release | null } | { error_code: string }
> {
  try {
    const input = DeploySourceInput.parse(raw);
    const home = productionDeployHome(input);
    const retainedName = Release.shape.name.parse(name);
    const call = local(input, deadline, port, signal);
    const previous = z.object({ release: Release.nullable() }).parse(
      await call((effects) => effects.inspectRelease(home), false),
    ).release;
    const release = Release.parse(
      z.object({ release: Release.nullable() }).parse(
        await call(
          (effects) => effects.inspectRelease(home, retainedName),
          false,
        ),
      ).release,
    );
    if (
      release.name !== retainedName ||
      (input.commit !== null && release.commit !== input.commit)
    ) throw new Refused("deployment.identity_changed");
    return { release, previous };
  } catch (error) {
    return { error_code: code(error, "rollback.release_invalid") };
  }
}
