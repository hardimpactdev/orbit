import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import {
  DirectArgs,
  directOperation,
  GatewayOperation,
  Globals,
} from "../_lib/direct.ts";
import type { NodeCall } from "../_lib/node-agent.ts";
import { runLocalSource } from "./source_system.ts";
import { repositoryIdentity } from "./source_identity.ts";

const Args = DirectArgs.extend({
  kind: z.literal("instance.rename"),
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
  branch: z.string().min(1).nullable(),
  checkout: Path,
  source_root: Path,
  repository: z.string().min(1),
  repository_identity: z.string().min(1),
  user: z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/).refine((v) =>
    v !== "root"
  ),
  prepare_id: z.string().nullable(),
  seed_repository: z.string().nullable(),
});
const Result = z.union([
  z.object({ branch: z.string().nullable() }).strict(),
  z.object({ error_code: z.string().regex(/^[a-z0-9_.-]{1,100}$/) }).strict(),
]);
const Refusal = z.object({
  error_code: z.string().regex(/^[a-z0-9_.-]{1,100}$/),
});
/** Read the local checkout. Rename changes recorded intent, never Git HEAD. */
export async function inspectRename(
  raw: unknown,
  invoke: (
    call: NodeCall,
    signal?: AbortSignal,
  ) => Promise<Record<string, unknown>> = runLocalSource,
  signal?: AbortSignal,
) {
  const input = Input.parse(raw);
  const call = async (name: string, fields: Record<string, unknown>) => {
    const result = await invoke({
      name,
      input: fields,
      pin: "",
      sudo: false,
      mutates: false,
      deadlineMs: name === "account.lookup" ? 10_000 : 120_000,
    }, signal);
    const refusal = Refusal.safeParse(result);
    return refusal.success ? refusal.data : result;
  };
  const account = await call("account.lookup", { user: input.user });
  if ("error_code" in account) return Refusal.parse(account);
  const parsed = z.object({
    user: z.literal(input.user),
    group: z.string().min(1),
  }).parse(account);
  const source = await call("instance.inspect-development-source", {
    repository: input.repository,
    checkout: input.checkout,
    root: input.source_root,
    user: parsed.user,
    group: parsed.group,
    prepare_id: input.prepare_id,
    seed_repository: input.seed_repository,
    verify_origin: false,
    require_idle: true,
  });
  if ("error_code" in source) return Refusal.parse(source);
  const inspected = z.object({
    origin: z.string(),
    branch: z.string().nullable(),
  }).parse(source);
  try {
    if (repositoryIdentity(inspected.origin) !== input.repository_identity) {
      return { error_code: "instance.source_identity_invalid" };
    }
  } catch {
    return { error_code: "instance.source_identity_invalid" };
  }
  if (input.branch !== null && inspected.branch !== input.branch) {
    return { error_code: "instance.branch_not_checked_out" };
  }
  return { branch: inspected.branch };
}

type Context = { globalArgs: z.infer<typeof Globals>; signal?: AbortSignal };
export function renameRoot(
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  inspect = inspectRename,
) {
  return {
    type: "@nckrtl/instance-rename",
    version: "2026.10.31.1",
    globalArguments: Globals,
    methods: {
      execute: {
        description:
          "Verify the admitted checkout locally and project its selected branch",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) =>
          await directOperation(
            Args.parse(args),
            new GatewayOperation(makeHttp(ctx), args),
            (input) => inspect(input, runLocalSource, ctx.signal),
            (result) => Result.parse(result),
            ctx.signal,
          ),
      },
    },
  };
}
export const behavior = renameRoot();
