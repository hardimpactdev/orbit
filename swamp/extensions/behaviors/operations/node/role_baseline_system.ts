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
): SystemCommand {
  return {
    command: sudo ? "/usr/bin/sudo" : "/usr/bin/python3",
    args: sudo ? ["-n", "/usr/bin/python3", "-"] : ["-"],
    stdin: `${script}\nmain(json.loads(${
      JSON.stringify(JSON.stringify(input))
    }))\n`,
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
const StorageScript = `import json, os, pwd, grp, stat

def main(data):
    try:
        root = data['root']
        if not os.path.isabs(root) or any(part in ('.', '..') for part in root.split('/')):
            raise ValueError()
        uid, gid = pwd.getpwnam(data['user']).pw_uid, grp.getgrnam(data['group']).gr_gid
        current = '/'
        for part in filter(None, root.split('/')):
            current = os.path.join(current, part)
            if os.path.lexists(current):
                if not stat.S_ISDIR(os.lstat(current).st_mode) or os.path.realpath(current) != current:
                    raise ValueError()
            else:
                if not data['create_missing']:
                    raise ValueError()
                os.mkdir(current, 0o755)
                os.chown(current, uid, gid)
        metadata = os.lstat(root)
        mode = stat.S_IMODE(metadata.st_mode)
        if metadata.st_uid != uid or metadata.st_gid != gid or mode & 0o700 != 0o700 or mode & 0o022:
            raise ValueError()
        print('{}')
    except (ValueError, KeyError, OSError):
        print(json.dumps({'error_code': 'node.storage_root_unusable'}))
`;

/** Every component is real; existing roots are inspected and never repaired or adopted. */
export function prepareStorageRoot(
  raw: unknown,
  transport: SystemTransport = localSystemCommand,
  signal?: AbortSignal,
): Promise<BaselineOutcome> {
  return outcome(
    python(StorageScript, Storage.parse(raw), true),
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
const HibernationScript = `import json, os, stat
PROTECTED = {'/dev', '/dev/shm', '/etc', '/proc', '/run', '/sys', '/tmp', '/var', '/var/tmp'}

def main(data):
    try:
        paths = [data['marker_directory'], data['log_directory']]
        for path in paths:
            if not os.path.isabs(path) or path == '/' or path in PROTECTED or any(part in ('.', '..') for part in path.split('/')):
                raise ValueError()
            current = path
            while True:
                if os.path.lexists(current) and not stat.S_ISDIR(os.lstat(current).st_mode):
                    raise ValueError()
                if current == '/':
                    break
                current = os.path.dirname(current)
        if os.path.commonpath(paths) in paths:
            raise ValueError()
        owner = data['owner']
        for directory, mode in zip(paths, [0o755, 0o2775]):
            ancestors = []
            parent = os.path.dirname(directory)
            while parent != '/' and parent not in PROTECTED:
                ancestors.append(parent)
                parent = os.path.dirname(parent)
            for path in reversed(ancestors):
                if not os.path.isdir(path):
                    os.mkdir(path, 0o755)
                os.chmod(path, 0o755)
            if not os.path.isdir(directory):
                os.mkdir(directory, 0o755)
            os.chown(directory, *owner)
            os.chmod(directory, mode)
        print('{}')
    except (KeyError, ValueError, OSError):
        print(json.dumps({'error_code': 'hibernation.node_failed'}))
`;

/** Validate both trees before mutation and leave protected system-root modes untouched. */
export async function prepareHibernationDirectories(
  raw: unknown,
  transport: SystemTransport = localSystemCommand,
  signal?: AbortSignal,
): Promise<BaselineOutcome> {
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
    python(HibernationScript, { ...input, owner }, true),
    "hibernation.node_failed",
    transport,
    signal,
  );
}
