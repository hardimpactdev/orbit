import { z } from "npm:zod@4.4.3";
import {
  DeploySourceLocal,
  LocalDeployError,
  localDeploySource,
} from "./deploy_local.ts";

const Name = z.string().regex(/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/);
const Commit = z.string().regex(/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/);
export const DeploySourceInput = z.object({
  instance_id: z.number().int().positive(),
  node_id: z.number().int().positive().optional(),
  platform: z.enum(["linux", "macos"]).optional(),
  architecture: z.enum(["x86_64", "arm64"]).optional(),
  project_id: z.number().int().positive(),
  agent_sha256: z.string().regex(/^[a-f0-9]{64}$/).optional(),
  commit: Commit.nullable(),
  release_name: Name.optional(),
  deployment: z.object({
    variant: z.enum(["development", "production", "rollback"]),
    home: z.string().startsWith("/").max(4096),
    repository: z.string().min(1).max(4096),
    branch: z.string().min(1).max(1024),
    node_user: z.string().regex(/^[a-z_][a-z0-9_-]{0,63}$/).refine((x) =>
      x !== "root"
    ),
    protocols: z.array(z.enum(["https", "ssh"])).min(1).max(2),
    user: z.string().nullable().optional(),
    relative_root: z.string().nullable().optional(),
    application_suffix: z.string().nullable().optional(),
    served: z.array(
      z.object({ web_root: z.string(), directory: z.string() }).strict(),
    ).max(32).optional(),
    shared_paths: z.array(z.string()).max(32).optional(),
  }),
});
export type DeploySourceInput = z.infer<typeof DeploySourceInput>;
export const Release = z.object({ name: Name, commit: Commit }).strict();
export type Release = z.infer<typeof Release>;
type SourceResult = {
  commit: string;
  release: Release | null;
  previous: Release | null;
} | {
  error_code: string;
};
type Port = {
  local?: DeploySourceLocal;
  git: (
    use: (git: { github_token: string | null }) => Promise<void>,
  ) => Promise<void>;
  current: () => Promise<{ cancel_requested: boolean }>;
  now?: () => number;
};
class Refused extends Error {}

/** Local source action in the parent; private Git material stays in its callback. */
export async function prepareDeploySource(
  raw: DeploySourceInput,
  deadline: number,
  port: Port,
  signal?: AbortSignal,
): Promise<SourceResult> {
  const input = DeploySourceInput.parse(raw), d = input.deployment;
  const checkout = {
    home: d.home,
    repository: d.repository,
    instance: input.instance_id,
    user: d.node_user,
  };
  const local = port.local ?? localDeploySource({ deadline, signal });
  const call = async <T>(
    action: () => Promise<T>,
    mutates = true,
  ): Promise<T> => {
    if ((await port.current()).cancel_requested || signal?.aborted) {
      throw new Refused("operation.cancelled");
    }
    if (deadline - (port.now ?? Date.now)() <= 0) {
      throw new Refused("deployment.deadline_exceeded");
    }
    try {
      return await action();
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
  try {
    if (d.variant === "rollback") {
      throw new Refused("deployment.identity_changed");
    }
    const home = d.variant === "production"
      ? productionDeployHome(input)
      : null;
    const releaseName = home === null ? null : Name.parse(input.release_name);
    let previous: Release | null = null;
    if (home !== null) {
      const prior = z.object({ release: Release.nullable() }).parse(
        await call(() => local.inspectRelease(home), false),
      );
      previous = prior.release;
    }
    let fetched: Record<string, unknown> | undefined,
      attempted = false,
      accepting = true;
    try {
      await port.git(async (git) => {
        if (!accepting || attempted) return;
        attempted = true;
        fetched = { error_code: "deployment.execution_unconfirmed" };
        try {
          const read = z.object({ github_token: z.string().nullable() })
            .strict().parse(git);
          fetched = home === null
            ? await call(() =>
              local.resolveDevelopment(checkout, {
                branch: d.branch,
                commit: input.commit,
                protocols: d.protocols,
                git_read: read,
              })
            )
            : await call(() =>
              local.prepareRelease(home, {
                name: releaseName!,
                branch: d.branch,
                commit: input.commit,
                protocols: d.protocols,
                shared_paths: d.shared_paths ?? [],
                git_read: read,
              })
            );
        } catch (error) {
          if (error instanceof Refused) fetched = { error_code: error.message };
        }
      });
    } catch {
      /* Private delivery cannot replace the local outcome. */
    } finally {
      accepting = false;
    }
    if (!fetched) throw new Refused("deployment.interrupted");
    if (typeof fetched.error_code === "string") {
      throw new Refused(fetched.error_code);
    }
    const commit = Commit.parse(fetched.commit);
    if (input.commit !== null && input.commit !== commit) {
      throw new Refused("deployment.commit_not_on_branch");
    }
    if (home !== null) {
      const release = Release.parse(fetched);
      if (release.name !== releaseName) {
        throw new Refused("deployment.identity_changed");
      }
      return { commit, release, previous };
    }
    const checked = await call(() =>
      local.checkoutDevelopment(checkout, { branch: d.branch, commit })
    );
    if (Commit.parse(checked.commit) !== commit) {
      throw new Refused("deployment.interrupted");
    }
    return { commit, release: null, previous: null };
  } catch (error) {
    return {
      error_code: error instanceof Refused
        ? error.message
        : "deployment.interrupted",
    };
  }
}

/** Canonical admitted production identity, shared by deploy and retained-release rollback. */
export function productionDeployHome(input: DeploySourceInput) {
  const d = input.deployment;
  if (
    !["production", "rollback"].includes(d.variant) ||
    d.user !== `orbit-app-${input.project_id}` ||
    d.home !== `/home/${d.user}` || typeof d.relative_root !== "string" ||
    typeof d.application_suffix !== "string" || !d.served || !d.shared_paths
  ) {
    throw new Refused("deployment.identity_changed");
  }
  return {
    home: d.home,
    repository: d.repository,
    instance: input.instance_id,
    user: d.user,
    relative_root: d.relative_root,
    application_suffix: d.application_suffix,
    served: d.served,
  };
}
