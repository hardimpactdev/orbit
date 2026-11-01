import { classifyProvisioningProfile } from "./source_profile.ts";
import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../_lib/direct.ts";
import {
  DirectArgs,
  directOperation,
  GatewayOperation,
} from "../_lib/direct.ts";
import type { NodeCall } from "../_lib/node-agent.ts";
import { runLocalSource } from "./source_system.ts";

import { repositoryIdentity } from "./source_identity.ts";

const Args = DirectArgs.extend({
  kind: z.literal("instance.register"),
  node_id: z.number().int().positive(),
});
const Path = z.string().max(4096).regex(
  /^\/(?:[^/]+\/)*[^/]+$/,
).refine((v) =>
  [...v].every((c) => c.charCodeAt(0) > 32) &&
  !v.split("/").some((p) => p === "." || p === "..")
);
const Input = z.object({
  instance_id: z.number().int().positive(),
  project_id: z.number().int().positive(),
  checkout: Path,
  application: Path,
  repository_identity: z.string().min(1),
  project_type: z.enum(["monorepo", "laravel-package", "node-package"]),
  php_versions: z.array(z.string().regex(/^\d+\.\d+$/)).min(1),
  user: z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/).refine((v) =>
    v !== "root"
  ),
});
const Refusal = z.object({
  error_code: z.string().regex(/^[a-z0-9_.-]{1,100}$/),
});
const Row = z.object({
  path: Path,
  layout: z.string(),
  repository_url: z.string(),
  branch: z.string().nullable(),
  detached: z.boolean(),
  commit: z.string().regex(/^[a-f0-9]{40}(?:[a-f0-9]{24})?$/),
  source_digest: z.string().regex(/^[a-f0-9]{64}$/),
  default_branch: z.string().nullable(),
  inferred_slug: z.string().min(1),
  common_repository_path: Path,
  worktree_paths: z.array(Path),
});
const Result = z.union([
  z.object({
    registered: z.literal(true),
    php_version: z.string().nullable(),
    laravel: z.boolean(),
    branch: z.string().nullable(),
    commit: z.string(),
    detached: z.boolean(),
    digest: z.string(),
    common: Path,
    default_branch: z.string().nullable(),
    inferred_slug: z.string(),
  }).strict(),
  Refusal.strict(),
]);

/** In-place adoption only. Source paths are never copied or removed. */
export async function registerCheckout(
  raw: unknown,
  invoke: (
    call: NodeCall,
    signal?: AbortSignal,
  ) => Promise<Record<string, unknown>> = runLocalSource,
  signal?: AbortSignal,
) {
  const input = Input.parse(raw);
  const call = async (
    name: string,
    fields: Record<string, unknown>,
    mutates = false,
  ) => {
    const reply = await invoke({
      name,
      input: fields,
      pin: "",
      sudo: false,
      mutates,
      deadlineMs: mutates
        ? 900_000
        : name === "account.lookup"
        ? 10_000
        : 120_000,
    }, signal);
    const refusal = Refusal.safeParse(reply);
    return refusal.success ? refusal.data : reply;
  };
  const account = await call("account.lookup", { user: input.user });
  if ("error_code" in account) return Refusal.parse(account);
  const owner = z.object({
    user: z.literal(input.user),
    group: z.string().min(1),
  }).parse(account);
  const inspected = await call("instance.inspect-registration-source", {
    path: input.checkout,
    include_worktrees: false,
    user: owner.user,
    group: owner.group,
  });
  if ("error_code" in inspected) return Refusal.parse(inspected);
  const rows = z.object({ rows: z.array(Row).length(1) }).parse(inspected).rows;
  const source = rows[0];
  if (
    source.path !== input.checkout || source.layout !== "checkout" ||
    source.detached !== (source.branch === null) ||
    source.worktree_paths.length !== 1 ||
    source.worktree_paths[0] !== input.checkout
  ) return { error_code: "instance.registration_conflict" };
  try {
    if (
      repositoryIdentity(source.repository_url) !== input.repository_identity
    ) return { error_code: "instance.source_identity_invalid" };
  } catch {
    return { error_code: "instance.source_identity_invalid" };
  }
  const inspectedProfile = input.project_type === "monorepo"
    ? { kind: "none" }
    : await call("instance.inspect-development-profile", {
      application: input.application,
      owner: input.user,
      entry_point: "artisan",
    });
  if ("error_code" in inspectedProfile) return Refusal.parse(inspectedProfile);
  const profile = classifyProvisioningProfile(inspectedProfile, input);
  const members = [{
    id: input.instance_id,
    source: input.checkout,
    destination: input.checkout,
    application: input.application,
    layout: source.layout,
    commit: source.commit,
    branch: source.branch,
    detached: source.detached,
    digest: source.source_digest,
    common: source.common_repository_path,
    worktrees: source.worktree_paths,
    source_device: null,
    source_inode: null,
  }];
  for (const operation of ["adopt", "adopt-finalize"]) {
    const reply = await call("instance.relocate-registration-source", {
      operation,
      members,
    }, true);
    if ("error_code" in reply) return Refusal.parse(reply);
    z.object({ identities: z.array(z.unknown()).length(0) }).parse(reply);
  }
  return {
    registered: true as const,
    ...profile,
    branch: source.branch,
    commit: source.commit,
    detached: source.detached,
    digest: source.source_digest,
    common: source.common_repository_path,
    default_branch: source.default_branch,
    inferred_slug: source.inferred_slug,
  };
}

type Context = { globalArgs: z.infer<typeof Globals>; signal?: AbortSignal };
export function registerRoot(
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  register = registerCheckout,
) {
  return {
    type: "@nckrtl/instance-register",
    version: "2026.10.31.2",
    globalArguments: Globals,
    methods: {
      execute: {
        description:
          "Inspect and adopt the admitted local checkout, then project its record",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) =>
          await directOperation(
            Args.parse(args),
            new GatewayOperation(makeHttp(ctx), args),
            (input) => register(input, runLocalSource, ctx.signal),
            (result) => Result.parse(result),
            ctx.signal,
          ),
      },
    },
  };
}
export const behavior = registerRoot();
