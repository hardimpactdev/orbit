import { z } from "npm:zod@4.4.3";
import { runNodeAgent } from "../_lib/node-agent.ts";

const Commit = z.string().regex(/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/);
const Name = z.string().regex(/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/);
export const DeploySourceInput = z.object({
  instance_id: z.number().int().positive(),
  project_id: z.number().int().positive(),
  agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
  commit: Commit.nullable(),
  release_name: Name,
  deployment: z.object({
    variant: z.enum(["development", "production"]),
    home: z.string().startsWith("/").max(4096),
    repository: z.string().min(1).max(4096),
    branch: z.string().min(1).max(1024),
    node_user: z.string().regex(/^[a-z_][a-z0-9_-]{0,63}$/),
    protocols: z.array(z.enum(["https", "ssh"])).min(1).max(2),
    user: z.string().nullable(),
    relative_root: z.string().nullable(),
    application_suffix: z.string().nullable(),
    served: z.array(
      z.object({
        web_root: z.string(),
        directory: z.string(),
      }).strict(),
    ).max(32),
    shared_paths: z.array(z.string()).max(32),
  }),
});
export type DeploySourceInput = z.infer<typeof DeploySourceInput>;
export const Release = z.object({ name: Name, commit: Commit });
export type Release = z.infer<typeof Release>;
type SourceResult = {
  commit: string;
  release: Release | null;
  previous: Release | null;
} | { error_code: string };
type GitRead = { github_token: string | null };
type PrivateGit = (
  action: (git: GitRead) => Promise<void>,
) => Promise<void>;
type Port = {
  invoke?: typeof runNodeAgent;
  git: PrivateGit;
  current: () => Promise<{ cancel_requested: boolean }>;
  now?: () => number;
};

/** The same canonical production identity is used by preparation, activation and cleanup. */
export function productionDeployHome(input: DeploySourceInput) {
  const d = input.deployment;
  if (
    d.variant !== "production" || d.user !== `orbit-app-${input.project_id}` ||
    d.home !== `/home/${d.user}`
  ) throw new Error("deployment.identity_changed");
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

const mapped = (code: string) =>
  ({
    "instance.commit_not_on_branch": "deployment.commit_not_on_branch",
    "instance.checkout_dirty": "deployment.checkout_dirty",
    "instance.branch_diverged": "deployment.branch_diverged",
  })[code] ?? code;

/** One source-preparation action in the parent native DAG; private Git material stays in its callback. */
export async function prepareDeploySource(
  raw: DeploySourceInput,
  deadline: number,
  port: Port,
  signal?: AbortSignal,
): Promise<SourceResult> {
  const input = DeploySourceInput.parse(raw), d = input.deployment;
  const now = port.now ?? Date.now, invoke = port.invoke ?? runNodeAgent;
  const checkout = {
    home: d.home,
    repository: d.repository,
    instance: input.instance_id,
  };
  async function call(
    name: string,
    data: Record<string, unknown>,
    mutates: boolean,
  ) {
    const current = await port.current();
    if (current.cancel_requested || signal?.aborted) {
      return { error_code: "operation.cancelled" };
    }
    const remaining = Math.floor(deadline - now());
    if (!Number.isFinite(remaining) || remaining <= 0) {
      return { error_code: "deployment.deadline_exceeded" };
    }
    const result = await invoke({
      name,
      input: data,
      pin: input.agent_sha256,
      sudo: true,
      mutates,
      deadlineMs: Math.min(remaining, 900000),
    }, signal);
    if (typeof result.error_code === "string") {
      return { error_code: mapped(result.error_code) };
    }
    return result;
  }
  try {
    let fetched: Record<string, unknown> | undefined;
    let previous: Release | null = null;
    if (d.variant === "production") {
      const home = productionDeployHome(input);
      const result = await call("instance.inspect-release", home, false);
      if (typeof result.error_code === "string") {
        return { error_code: result.error_code };
      }
      previous = result.release === null ? null : Release.parse(result.release);
    }
    let attempted = false, accepting = true;
    try {
      await port.git(async (git) => {
        if (!accepting || attempted) return;
        attempted = true;
        fetched = { error_code: "deployment.interrupted" };
        const read = z.object({ github_token: z.string().nullable() }).strict()
          .parse(git);
        fetched = d.variant === "development"
          ? await call("instance.resolve-development-target", {
            ...checkout,
            branch: d.branch,
            commit: input.commit,
            protocols: d.protocols,
            git_read: read,
          }, true)
          : await call("instance.prepare-release", {
            ...productionDeployHome(input),
            name: input.release_name,
            branch: d.branch,
            commit: input.commit,
            shared_paths: d.shared_paths,
            git_read: read,
          }, true);
      });
    } catch {
      // A delivery failure cannot erase or replace the local action's outcome.
    } finally {
      accepting = false;
    }
    if (!fetched) return { error_code: "deployment.interrupted" };
    if (typeof fetched.error_code === "string") {
      return { error_code: fetched.error_code };
    }
    const commit = Commit.parse(fetched.commit);
    if (input.commit !== null && commit !== input.commit) {
      return { error_code: "deployment.commit_not_on_branch" };
    }
    if (d.variant === "development") {
      const checked = await call("instance.checkout-development-default", {
        ...checkout,
        branch: d.branch,
        commit,
      }, true);
      if (typeof checked.error_code === "string") {
        return { error_code: checked.error_code };
      }
      if (Commit.parse(checked.commit) !== commit) {
        return { error_code: "deployment.interrupted" };
      }
      return { commit, release: null, previous: null };
    }
    const release = Release.parse(fetched);
    if (release.name !== input.release_name) {
      return { error_code: "deployment.interrupted" };
    }
    return { commit, release, previous };
  } catch (error) {
    return {
      error_code: error instanceof Error &&
          error.message === "deployment.identity_changed"
        ? error.message
        : "deployment.interrupted",
    };
  }
}
