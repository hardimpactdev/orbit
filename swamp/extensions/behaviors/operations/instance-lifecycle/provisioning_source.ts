import { z } from "npm:zod@4.4.3";
import { classifyProvisioningProfile, ProvisioningRefusal } from "./source_profile.ts";
export { classifyProvisioningProfile, ProvisioningRefusal } from "./source_profile.ts";
import type { NodeCall } from "../_lib/node-agent.ts";
import { runLocalSource } from "./source_system.ts";
import {
  isProductionHomeAction,
  runLocalProductionHome,
} from "./production_home_system.ts";
import type { GatewayOperation } from "../_lib/direct.ts";
import { ProjectStepList } from "../instance-deploy/run_list.ts";

const Id = z.number().int().positive();
const Account = z.string().regex(/^[a-z_][a-z0-9_-]{0,63}$/).refine((v) =>
  v !== "root"
);
const Path = z.string().regex(/^\/(?:[A-Za-z0-9_.-]+\/)*[A-Za-z0-9_.-]+$/)
  .refine((v) => !v.split("/").some((part) => part === "." || part === ".."));
const GitRead = z.object({ github_token: z.string().max(4096).nullable() })
  .strict();
export const CloneCandidate = z.object({
  node_id: Id,
  agent_sha256: z.string().regex(/^[a-f0-9]{64}$/).optional(),
  environment: z.enum(["development", "production"]),
  base: Path,
  user: Account,
  repository: z.string(),
  configured_branch: z.string(),
  target_branch: z.string(),
  expected_source: Path,
}).strict();
const Source = z.object({
  branch: z.string().min(1).max(255),
  commit: z.string().regex(/^[a-f0-9]{40}(?:[a-f0-9]{24})?$/),
  php_version: z.string().nullable(),
  laravel: z.boolean(),
}).strict();
export const Provisioning = z.object({
  instance_id: Id,
  project_id: Id,
  node_id: Id,
  node_user: Account,
  platform: z.enum(["linux", "macos"]),
  architecture: z.enum(["x86_64", "arm64"]),
  vp_materialize: z.record(z.string(), z.unknown()),
  environment: z.enum(["development", "production"]),
  user: Account,
  checkout: Path,
  application: Path,
  agent_sha256: z.string().regex(/^[a-f0-9]{64}$/).optional(),
  project_type: z.enum([
    "monorepo",
    "laravel-app",
    "symfony-app",
    "laravel-package",
    "node-package",
  ]),
  entry_point: z.enum(["artisan", "bin/console"]),
  skip_profile: z.boolean(),
  php_versions: z.array(z.string().regex(/^\d+\.\d+$/)).min(1),
  source: z.record(z.string(), z.unknown()),
  resolve: z.record(z.string(), z.unknown()),
  home: z.object({ user: Account, home: Path }).strict().optional(),
  setup: ProjectStepList.optional(),
  candidate: CloneCandidate.optional(),
  already_active: z.boolean(),
  retained_source: Source.nullable(),
}).strict().superRefine((plan, ctx) => {
  const dev = plan.environment === "development";
  if (
    dev
      ? plan.user !== plan.node_user || plan.setup === undefined ||
        plan.home !== undefined
      : plan.user !== `orbit-app-${plan.project_id}` ||
        plan.home?.user !== plan.user || plan.home.home !== `/home/${plan.user}`
  ) {
    ctx.addIssue({
      code: "custom",
      message: "Provisioning account does not match environment",
    });
  }
  if (
    dev &&
    (plan.source.user !== plan.user || plan.source.checkout !== plan.checkout ||
      plan.setup?.execution.variant !== "checkout" ||
      plan.setup.execution.user !== plan.user ||
      plan.setup.execution.directory !== plan.checkout ||
      plan.setup.project_id !== plan.project_id ||
      plan.setup.instance_id !== plan.instance_id)
  ) {
    ctx.addIssue({
      code: "custom",
      message: "Development source or setup does not match admission",
    });
  }
  if (
    !dev &&
    (plan.source.user !== plan.user || plan.source.home !== plan.home?.home ||
      plan.source.instance !== plan.instance_id)
  ) {
    ctx.addIssue({
      code: "custom",
      message: "Production source does not match admission",
    });
  }
});
export type Provisioning = z.infer<typeof Provisioning>;
export type ProvisioningInvoke = typeof runLocalSource;
export const invokeLocalProvisioningSource: ProvisioningInvoke = (
  call,
  signal,
) => {
  if (
    isProductionHomeAction(call.name) &&
    (call.name !== "instance.inspect-clone-candidate" ||
      call.input.environment === "production")
  ) {
    return runLocalProductionHome(call, signal);
  }
  return runLocalSource(call, signal);
};
const Code = z.string().regex(/^[a-z0-9_.-]{1,100}$/);

function sourceInput(plan: Provisioning) {
  return { ...plan.source };
}
async function command(
  plan: Provisioning,
  name: string,
  input: Record<string, unknown>,
  mutates: boolean,
  invoke: ProvisioningInvoke,
  signal?: AbortSignal,
) {
  if (plan.environment === "production" && isProductionHomeAction(name)) {
    input = { ...input, project_id: plan.project_id };
  }
  if (
    plan.environment === "development" && Object.hasOwn(input, "checkout") &&
    Object.hasOwn(input, "prepare_id")
  ) {
    const account = z.object({ user: z.literal(plan.user), group: Account })
      .parse(
        await invoke({
          name: "account.lookup",
          input: { user: plan.user },
          pin: plan.agent_sha256 ?? "",
          sudo: false,
          mutates: false,
          deadlineMs: 10000,
        }, signal),
      );
    input = { ...input, group: account.group };
  }
  const call: NodeCall = {
    name,
    input,
    pin: plan.agent_sha256 ?? "",
    sudo: plan.environment === "production",
    mutates,
    deadlineMs: mutates ? 900000 : 120000,
    maxOutputBytes: 2097152,
  };
  const result = await invoke(call, signal);
  if (result.error_code !== undefined) {
    throw new ProvisioningRefusal(Code.parse(result.error_code), name);
  }
  return result;
}
async function withGit(
  plan: Provisioning,
  gateway: Pick<GatewayOperation, "withPrivateMaterial">,
  name: string,
  input: Record<string, unknown>,
  invoke: ProvisioningInvoke,
  signal?: AbortSignal,
) {
  let accepting = true, attempted = false, finished = false;
  let result: Record<string, unknown> | undefined,
    refusal: ProvisioningRefusal | undefined;
  try {
    await gateway.withPrivateMaterial(async (material) => {
      if (!accepting || attempted) throw new Error("Private callback consumed");
      attempted = true;
      try {
        result = await command(
          plan,
          name,
          { ...input, git_read: GitRead.parse(material.git_read) },
          true,
          invoke,
          signal,
        );
        finished = true;
      } catch (error) {
        if (error instanceof ProvisioningRefusal) refusal = error;
        throw new Error("Private source action failed");
      }
    });
  } catch {
    if (refusal) throw refusal;
    throw new ProvisioningRefusal(
      "instance.source_execution_unconfirmed",
      name,
    );
  } finally {
    accepting = false;
  }
  if (!attempted || !finished || result === undefined) {
    throw new ProvisioningRefusal(
      "instance.source_execution_unconfirmed",
      name,
    );
  }
  return result;
}
export async function prepareProvisioningSource(
  plan: Provisioning,
  gateway: Pick<GatewayOperation, "withPrivateMaterial">,
  invoke = invokeLocalProvisioningSource,
  signal?: AbortSignal,
) {
  if (plan.already_active) return;
  if (plan.retained_source !== null) {
    if (plan.environment === "development") {
      const observed = await command(
        plan,
        "instance.inspect-development-source",
        { ...sourceInput(plan), verify_origin: true, require_idle: false },
        false,
        invoke,
        signal,
      );
      if (
        plan.retained_source !== null &&
        (observed.branch !== plan.retained_source.branch ||
          observed.commit !== plan.retained_source.commit)
      ) {
        throw new ProvisioningRefusal(
          "instance.source_identity_changed",
          "prepare",
        );
      }
    }
    return;
  }
  if (plan.environment === "production") {
    await command(
      plan,
      "instance.prepare-production-user",
      plan.home!,
      true,
      invoke,
      signal,
    );
  }
  await withGit(
    plan,
    gateway,
    `instance.prepare-${plan.environment}-source`,
    {
      ...sourceInput(plan),
      allow_existing: true,
      ...(plan.environment === "development"
        ? {
          seed_commit: plan.resolve.seed_commit,
          network: plan.resolve.network,
        }
        : {}),
    },
    invoke,
    signal,
  );
}
export async function resolveProvisioningSource(
  plan: Provisioning,
  gateway: Pick<GatewayOperation, "withPrivateMaterial">,
  invoke = invokeLocalProvisioningSource,
  signal?: AbortSignal,
  candidateCommit?: string,
) {
  if (plan.retained_source !== null) {
    return {
      branch: plan.retained_source.branch,
      commit: plan.retained_source.commit,
    };
  }
  const resolved = z.object({
    branch: Source.shape.branch,
    commit: Source.shape.commit,
  })
    .strict().parse(
      await withGit(
        plan,
        gateway,
        `instance.resolve-${plan.environment}-source`,
        { ...sourceInput(plan), ...plan.resolve },
        invoke,
        signal,
      ),
    );
  if (
    plan.candidate !== undefined &&
    plan.candidate.target_branch === plan.candidate.configured_branch &&
    resolved.commit !== candidateCommit
  ) {
    throw new ProvisioningRefusal(
      "instance.clone_candidate_changed",
      "resolve",
    );
  }
  return resolved;
}
export async function profileProvisioningSource(
  plan: Provisioning,
  invoke = invokeLocalProvisioningSource,
  signal?: AbortSignal,
) {
  if (plan.retained_source !== null) {
    return {
      php_version: plan.retained_source.php_version,
      laravel: plan.retained_source.laravel,
    };
  }
  if (plan.skip_profile) return { php_version: null, laravel: false };
  const input = plan.environment === "development"
    ? {
      application: plan.application,
      owner: plan.user,
      entry_point: plan.entry_point,
    }
    : {
      user: plan.user,
      relative_root: plan.resolve.relative_root,
      checkout: plan.checkout,
      application: plan.application,
      entry_point: plan.entry_point,
    };
  return classifyProvisioningProfile(
    await command(
      plan,
      `instance.inspect-${plan.environment}-profile`,
      input,
      false,
      invoke,
      signal,
    ),
    plan,
  );
}
export async function seedProvisioningTemplate(
  plan: Provisioning,
  commit: string,
  gateway: Pick<GatewayOperation, "storeSecretOutput">,
  invoke = invokeLocalProvisioningSource,
  signal?: AbortSignal,
) {
  if (plan.environment !== "development" || plan.already_active) return;
  const result = await command(
    plan,
    "instance.read-development-environment-template",
    { ...sourceInput(plan), application: plan.application, commit },
    false,
    invoke,
    signal,
  );
  const template = z.object({ environment_template: z.string().max(131072) })
    .strict().parse(result);
  await gateway.storeSecretOutput(template);
}

/** Runs only on the admitted candidate Node; protected Git input remains callback-local. */
export async function inspectProvisioningCandidate(
  plan: Provisioning,
  gateway: Pick<GatewayOperation, "withPrivateMaterial">,
  invoke = invokeLocalProvisioningSource,
  signal?: AbortSignal,
) {
  const { node_id: _node, agent_sha256, ...input } = CloneCandidate.parse(
    plan.candidate,
  );
  const observed = z.object({ source: Path, commit: Source.shape.commit })
    .strict().parse(
      await withGit(
        { ...plan, agent_sha256, environment: input.environment },
        gateway,
        "instance.inspect-clone-candidate",
        input,
        invoke,
        signal,
      ),
    );
  if (observed.source !== input.expected_source) {
    throw new ProvisioningRefusal(
      "instance.clone_candidate_source_invalid",
      "candidate",
    );
  }
  return { commit: observed.commit };
}
