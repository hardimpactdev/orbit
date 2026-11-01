import { z } from "npm:zod@4.4.3";
import {
  localSystemCommand,
  type SystemCommand,
  type SystemTransport,
} from "./system_command.ts";

const AccountName = z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/);
const AbsolutePath = z.string().startsWith("/").max(4096).refine((path) =>
  !path.includes("\0") &&
  !path.split("/").some((part) => part === "." || part === "..")
);
export const RoleAccount = z.object({
  user: AccountName,
  group: AccountName,
  home: AbsolutePath,
}).strict();
export type RoleAccount = z.infer<typeof RoleAccount>;
export type BaselineOutcome = { ok: true } | { ok: false; error_code: string };
const Code = z.enum([
  "node.storage_root_unusable",
  "role.os_unsupported",
  "role.prerequisite_conflict",
  "role.prerequisite_failed",
  "hibernation.node_failed",
]);
function decodeJson(raw: string): unknown {
  try {
    return JSON.parse(raw);
  } catch {
    throw new Error("The system action returned an invalid result");
  }
}
const Reply = z.union([
  z.object({}).strict(),
  z.object({ error_code: Code }).strict(),
]);

/** Fixed programs receive their typed data through stdin, never executable intent. */
function python(
  script: string,
  input: unknown,
  sudo: boolean,
  timeoutMs = 30000,
  returns = false,
): SystemCommand {
  return {
    command: sudo ? "/usr/bin/sudo" : "/usr/bin/python3",
    args: sudo ? ["-n", "/usr/bin/python3", "-"] : ["-"],
    stdin: script + "\n" +
      (returns
        ? "import json\nprint(json.dumps(main(json.loads("
        : "main(json.loads(") +
      JSON.stringify(JSON.stringify(input)) +
      (returns ? "))))\n" : "))\n"),
    timeoutMs,
  };
}
async function outcome(
  command: SystemCommand,
  failure: string,
  transport: SystemTransport,
  signal?: AbortSignal,
): Promise<BaselineOutcome> {
  signal?.throwIfAborted();
  const result = await transport(command, signal);
  if (!result.success) return { ok: false, error_code: failure };
  const reply = Reply.parse(decodeJson(result.stdout));
  return "error_code" in reply
    ? { ok: false, error_code: reply.error_code }
    : { ok: true };
}

const AccountScript = `import json, pwd, grp

def main(data):
    try:
        account = pwd.getpwnam(data['user'])
        print(json.dumps({'user': account.pw_name, 'group': grp.getgrgid(account.pw_gid).gr_name, 'home': account.pw_dir}))
    except (KeyError, OSError):
        print('null')
`;
export async function readRoleAccount(
  user: string,
  transport: SystemTransport = localSystemCommand,
  signal?: AbortSignal,
): Promise<RoleAccount | null> {
  signal?.throwIfAborted();
  const result = await transport(
    python(AccountScript, { user: AccountName.parse(user) }, false, 10000),
    signal,
  );
  if (!result.success) return null;
  const account = RoleAccount.nullable().parse(decodeJson(result.stdout));
  if (account !== null && account.user !== user) {
    throw new Error("The role account differs from admission");
  }
  return account;
}

const Storage = z.object({
  root: AbsolutePath,
  user: AccountName,
  group: AccountName,
  create_missing: z.boolean(),
}).strict();

/** Every component is real; existing roots are inspected and never repaired or adopted. */
export function prepareStorageRoot(
  raw: unknown,
  transport: SystemTransport = localSystemCommand,
  signal?: AbortSignal,
  source?: string,
): Promise<BaselineOutcome> {
  if (!source) {
    throw new Error(
      "The role root must supply its canonical storage asset",
    );
  }
  return outcome(
    python(source, Storage.parse(raw), true, 30000, true),
    "node.storage_root_unusable",
    transport,
    signal,
  );
}

const Hibernation = z.object({
  marker_directory: AbsolutePath,
  log_directory: AbsolutePath,
}).strict();
const CaddyOwnerScript = `import json, grp

def main(data):
    try:
        print(json.dumps([0, grp.getgrnam('caddy').gr_gid]))
    except KeyError:
        print('null')
`;

/** Validate both trees before mutation and leave protected system-root modes untouched. */
export async function prepareHibernationDirectories(
  raw: unknown,
  transport: SystemTransport = localSystemCommand,
  signal?: AbortSignal,
  source?: string,
): Promise<BaselineOutcome> {
  if (!source) {
    throw new Error(
      "The role root must supply its canonical hibernation asset",
    );
  }
  const input = Hibernation.parse(raw);
  signal?.throwIfAborted();
  const group = await transport(
    python(CaddyOwnerScript, {}, false, 10000),
    signal,
  );
  if (!group.success) {
    return { ok: false, error_code: "hibernation.node_failed" };
  }
  const owner = z.tuple([
    z.number().int().nonnegative(),
    z.number().int().nonnegative(),
  ]).nullable().parse(decodeJson(group.stdout));
  if (owner === null) {
    return { ok: false, error_code: "hibernation.node_failed" };
  }
  return outcome(
    python(source, { ...input, owner }, true, 30000, true),
    "hibernation.node_failed",
    transport,
    signal,
  );
}
