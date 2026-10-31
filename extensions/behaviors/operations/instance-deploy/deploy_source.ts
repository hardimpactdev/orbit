import { z } from "npm:zod@4.4.3";
import { runNodeAgent } from "../_lib/node-agent.ts";

const Commit = z.string().regex(/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/);
export const DeploySourceInput = z.object({
  instance_id: z.number().int().positive(),
  project_id: z.number().int().positive(),
  agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
  commit: Commit.nullable(),
  deployment: z.object({
    variant: z.literal("development"),
    home: z.string().startsWith("/").max(4096),
    repository: z.string().min(1).max(4096),
    branch: z.string().min(1).max(1024),
    node_user: z.string().regex(/^[a-z_][a-z0-9_-]{0,63}$/).refine((x) =>
      x !== "root"
    ),
    protocols: z.array(z.enum(["https", "ssh"])).min(1).max(2),
  }),
});
export type DeploySourceInput = z.infer<typeof DeploySourceInput>;
type SourceResult = { commit: string; release: null; previous: null } | {
  error_code: string;
};
type Port = {
  invoke?: typeof runNodeAgent;
  git: (
    use: (git: { github_token: string | null }) => Promise<void>,
  ) => Promise<void>;
  current: () => Promise<{ cancel_requested: boolean }>;
  now?: () => number;
};
class Refused extends Error {}

/** Local source action in the dev parent; private Git material stays in its callback. */
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
  };
  const call = async (name: string, data: Record<string, unknown>) => {
    if ((await port.current()).cancel_requested || signal?.aborted) {
      throw new Refused("operation.cancelled");
    }
    const remaining = Math.floor(deadline - (port.now ?? Date.now)());
    if (!Number.isFinite(remaining) || remaining <= 0) {
      throw new Refused("deployment.deadline_exceeded");
    }
    let result: Record<string, unknown>;
    try {
      result = await (port.invoke ?? runNodeAgent)({
        name,
        input: data,
        pin: input.agent_sha256,
        sudo: true,
        mutates: true,
        deadlineMs: Math.min(remaining, 900000),
      }, signal);
    } catch {
      throw new Refused("deployment.execution_unconfirmed");
    }
    if (typeof result.error_code === "string") {
      const code = ({
        "instance.commit_not_on_branch": "deployment.commit_not_on_branch",
        "instance.checkout_dirty": "deployment.checkout_dirty",
        "instance.branch_diverged": "deployment.branch_diverged",
      })[result.error_code] ?? result.error_code;
      throw new Refused(z.string().regex(/^[a-z0-9_.-]{1,100}$/).parse(code));
    }
    return result;
  };
  try {
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
          fetched = await call("instance.resolve-development-target", {
            ...checkout,
            branch: d.branch,
            commit: input.commit,
            protocols: d.protocols,
            git_read: read,
          });
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
    const checked = await call("instance.checkout-development-default", {
      ...checkout,
      branch: d.branch,
      commit,
    });
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
