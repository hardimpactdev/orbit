import { z } from "npm:zod@4.4.3";
import { prepareStorageRoot, readRoleAccount } from "./role_baseline_system.ts";
import { localSystemCommand, type SystemTransport } from "./system_command.ts";

const Path = z.string().max(4096).regex(
  /^\/(?:[^/\0\r\n]+(?:\/[^/\0\r\n]+)*)?$/,
)
  .refine((path) =>
    path.split("/").slice(1).every((part) => part !== "." && part !== "..")
  );
export const EnrollmentStorage = z.object({
  root: Path.nullable(),
  user: z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/),
  create_missing: z.boolean(),
  protected_roots: z.array(Path).max(256),
  managed_checkouts: z.array(Path).max(10000),
}).strict();
const inside = (path: string, parent: string) =>
  parent === "/"
    ? path !== "/" && path.startsWith("/")
    : path.startsWith(parent + "/");

/** Discover the admitted candidate's real home; reuse the canonical filesystem action. */
export async function prepareEnrollmentSettings(
  raw: unknown,
  invoke: SystemTransport = localSystemCommand,
  signal?: AbortSignal,
  readAccount = readRoleAccount,
  prepare = prepareStorageRoot,
  source?: string,
): Promise<
  { settings_applied: true } | { error_code: "node.settings_root_failed" }
> {
  signal?.throwIfAborted();
  const input = EnrollmentStorage.parse(raw);
  const account = await readAccount(input.user, invoke, signal);
  const failure = { error_code: "node.settings_root_failed" as const };
  if (account === null || account.user !== input.user) return failure;
  const home = Path.parse(account.home);
  const root = input.root ?? home + "/apps";
  const hidden = inside(root, home) &&
    root.slice(home.length + 1).split("/")[0].startsWith(".");
  if (
    root === home || inside(home, root) || hidden ||
    input.protected_roots.some((other) =>
      root === other || inside(root, other) || inside(other, root)
    ) ||
    input.managed_checkouts.some((other) =>
      root === other || inside(root, other)
    )
  ) return failure;
  const outcome = await prepare(
    {
      root,
      user: account.user,
      group: account.group,
      create_missing: input.create_missing,
    },
    invoke,
    signal,
    source,
  );
  return outcome.ok ? { settings_applied: true } : failure;
}
