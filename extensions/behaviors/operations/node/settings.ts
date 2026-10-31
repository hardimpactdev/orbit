import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../../automatic-deploy/gateway.ts";
import {
  DirectArgs,
  directOperation,
  GatewayOperation,
} from "../_lib/direct.ts";
import { runNodeAgent } from "../_lib/node-agent.ts";
import { Globals } from "../../operation-runner/mod.ts";

const Path = z.string().regex(/^\/(?:[^/\0\r\n]+\/)*[^/\0\r\n]+$/).refine(
  (path) =>
    path.split("/").slice(1).every((part) => part !== "." && part !== ".."),
);
const User = z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/);
export const Storage = z.object({
  root: Path.nullable(),
  user: User,
  create_missing: z.boolean(),
  protected_roots: z.array(Path),
  managed_checkouts: z.array(Path),
}).strict();
export const Prepare = z.object({
  agent_pin: z.string().regex(/^[a-f0-9]{64}$/),
  storage: Storage,
}).strict();
type Context = { globalArgs: z.infer<typeof Globals>; signal?: AbortSignal };
const inside = (path: string, parent: string) => path.startsWith(parent + "/");
const overlaps = (path: string, other: string) =>
  path === other || inside(path, other) || inside(other, path);

/** Account discovery and filesystem work stay on the admitted Node. */
export async function prepareStorage(
  args: z.infer<typeof Prepare>,
  signal?: AbortSignal,
  call = runNodeAgent,
) {
  const storage = args.storage;
  const account = await call({
    name: "account.lookup",
    input: { user: storage.user },
    pin: args.agent_pin,
    sudo: false,
    mutates: false,
    deadlineMs: 10000,
    maxOutputBytes: 16384,
  }, signal);
  if (account.error_code === "account.missing") return false;
  const parsed = z.object({ user: User, group: User, home: Path }).safeParse(
    account,
  );
  if (!parsed.success || parsed.data.user !== storage.user) {
    throw new Error("The admitted managed account is unavailable");
  }
  const { user, group, home } = parsed.data;
  const root = storage.root ?? home + "/apps";
  const hidden = inside(root, home) &&
    root.slice(home.length + 1).split("/")[0].startsWith(".");
  if (
    root === home || inside(home, root) || hidden ||
    storage.protected_roots.some((path) => overlaps(root, path)) ||
    storage.managed_checkouts.some((path) =>
      root === path || inside(root, path)
    )
  ) return false;
  const result = await call({
    name: "node.prepare-storage-root",
    input: {
      root,
      user,
      group,
      create_missing: storage.create_missing,
    },
    pin: args.agent_pin,
    sudo: true,
    mutates: true,
    deadlineMs: 30000,
    maxOutputBytes: 16384,
  }, signal);
  if (result.error_code === "node.storage_root_unusable") return false;
  if (Object.keys(result).length === 0) return true;
  throw new Error("Storage action outcome is uncertain");
}

const Args = DirectArgs.extend({
  kind: z.literal("node.settings"),
  node_id: z.number().int().positive(),
});
const Result = z.union([
  z.object({ prepared: z.literal(true) }).strict(),
  z.object({ error_code: z.literal("node.settings_root_failed") }).strict(),
]);

export function settings(
  action = prepareStorage,
  makeHttp: (ctx: Context) => Http = (ctx) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
) {
  return {
    type: "@nckrtl/node-settings",
    version: "2026.10.30.5",
    globalArguments: Globals,
    methods: {
      execute: {
        description:
          "Prepare one admitted apps root locally and project the bounded result",
        arguments: Args,
        execute: (args: z.infer<typeof Args>, ctx: Context) =>
          directOperation(
            args,
            new GatewayOperation(makeHttp(ctx), args),
            async (input) =>
              await action(Prepare.parse(input), ctx.signal)
                ? { prepared: true }
                : { error_code: "node.settings_root_failed" },
            Result.parse,
            ctx.signal,
          ),
      },
    },
  };
}
export const behavior = { ...settings(), type: "@nckrtl/node-settings" };
