import { z } from "npm:zod@4.4.3";
import { runNodeAgent } from "../_lib/node-agent.ts";
import {
  DeploySourceInput,
  productionDeployHome,
  Release,
} from "./deploy_source.ts";

type Port = {
  invoke?: typeof runNodeAgent;
  current: () => Promise<{ cancel_requested: boolean }>;
  now?: () => number;
};
class Refused extends Error {}
const code = (error: unknown, fallback: string) =>
  error instanceof Refused ? error.message : fallback;
function local(
  input: DeploySourceInput,
  deadline: number,
  port: Port,
  signal?: AbortSignal,
) {
  return async (
    name: string,
    data: Record<string, unknown>,
    mutates: boolean,
  ) => {
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
      mutates,
      deadlineMs: Math.min(remaining, 900000),
    }, signal);
    if (typeof result.error_code === "string") {
      throw new Refused(
        z.string().regex(/^[a-z0-9_.-]{1,100}$/).parse(result.error_code),
      );
    }
    return result;
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
      await local(input, deadline, port, signal)("instance.activate-release", {
        ...home,
        ...release,
      }, true),
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
    const release = candidate(input, selected),
      prior = previous === null ? null : Release.parse(previous);
    const limit = z.number().int().positive().parse(retained);
    const call = local(input, deadline, port, signal);
    const inspection = z.object({ release: Release.nullable() }).parse(
      await call("instance.inspect-release", home, false),
    );
    if (
      inspection.release?.name !== release.name ||
      inspection.release?.commit !== release.commit
    ) throw new Refused("deployment.selection_changed");
    const result = z.object({
      removed: z.array(Release.shape.name).max(256),
      in_use: z.array(Release.shape.name).max(256),
    }).strict().parse(
      await call("instance.prune-releases", {
        ...home,
        limit,
        selected: release.name,
        previous: prior?.name ?? null,
      }, true),
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
