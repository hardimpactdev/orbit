import { localCommand, localFilesystem } from "../_lib/local-worker.ts";
import { z } from "npm:zod@4.4.3";
export type DestroyReadCall = {
  name: string;
  input: Record<string, unknown>;
  sudo: boolean;
  mutates: boolean;
  deadlineMs: number;
  maxOutputBytes?: number;
};
import { DestroyPath, RemovalSource } from "./destroy_contract.ts";

// Source deletion belongs to the placed Swamp method, running as the admitted
// Node account. This module never starts orbit-agent or a shell.
const Account = z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/).refine((s) =>
  s !== "root"
);
const GitRead = z.object({
  github_token: z.string().min(1).max(4096).regex(/^[A-Za-z0-9_.-]+$/)
    .nullable().optional(),
}).strict();
const Receipt = RemovalSource.extend({
  receipt: z.string().regex(/^[a-f0-9]{64}$/),
  grouping_directory: DestroyPath,
});
const Finalize = Receipt.extend({
  branch: z.string().max(1024),
  force: z.boolean(),
  origin: z.string().min(1).max(4096),
  worktrees: z.string().max(262144),
  commit: z.string().regex(/^(?:[a-f0-9]{40}|[a-f0-9]{64}|)$/),
  unresolved: z.boolean(),
  independent_worktree: z.boolean(),
  own_units: z.array(
    z.string().regex(/^orbit-(?:process|schedule)-[a-z0-9-]+\.service$/),
  ).max(2048),
  git_read: GitRead,
});
type Source = z.infer<typeof RemovalSource>;
type ReceiptInput = z.infer<typeof Receipt>;
type FinalizeInput = z.infer<typeof Finalize>;
type Owner = {
  user: string;
  group: string;
  home: string;
  uid: number;
  gid: number;
};
const encoder = new TextEncoder();
const decoder = new TextDecoder("utf-8", { fatal: true });
const failure = () => {
  throw new Error("instance.removal_refused");
};
function require(value: unknown): asserts value {
  if (!value) failure();
}
const parent = (p: string) => p.slice(0, p.lastIndexOf("/")) || "/";
const inside = (p: string, root: string) => p.startsWith(root + "/");
const identity = (s: Deno.FileInfo) => `${s.dev}:${s.ino}`;
function encoded(value: string | Uint8Array): string {
  const bytes = typeof value === "string" ? encoder.encode(value) : value;
  return btoa(Array.from(bytes, (b) => String.fromCharCode(b)).join(""));
}
async function stat(path: string): Promise<Deno.FileInfo | null> {
  try {
    return await Deno.lstat(path);
  } catch (error) {
    if (error instanceof Deno.errors.NotFound) return null;
    throw error;
  }
}
async function noLinks(path: string) {
  DestroyPath.parse(path);
  let current = "";
  for (const piece of path.slice(1).split("/")) {
    current += "/" + piece;
    require(!(await stat(current))?.isSymlink);
  }
}
async function directory(path: string, owner: Owner) {
  await noLinks(path);
  const s = await stat(path);
  require(
    s?.isDirectory && !s.isSymlink && s.uid === owner.uid &&
      s.gid === owner.gid,
  );
  require(await Deno.realPath(path) === path);
  return s;
}
async function file(path: string, owner: Owner, contents?: string) {
  await noLinks(path);
  const s = await stat(path);
  require(
    s?.isFile && !s.isSymlink && s.uid === owner.uid && s.gid === owner.gid &&
      s.nlink === 1,
  );
  if (contents !== undefined) {
    require(await Deno.readTextFile(path) === contents + "\n");
  }
  return s;
}
async function runSystemProgram(
  executable: string,
  args: string[],
  signal?: AbortSignal,
  extraEnv: Record<string, string> = {},
  limit = 524288,
) {
  signal?.throwIfAborted();
  const child = localCommand(executable, {
    args,
    stdin: "null",
    stdout: "piped",
    stderr: "null",
    clearEnv: true,
    env: {
      PATH: "/usr/sbin:/usr/bin:/sbin:/bin",
      HOME: "/nonexistent",
      LANG: "C.UTF-8",
      ...extraEnv,
    },
    signal: signal
      ? AbortSignal.any([signal, AbortSignal.timeout(300000)])
      : AbortSignal.timeout(300000),
  }).spawn();
  try {
    const chunks: Uint8Array[] = [];
    let size = 0;
    for await (const bytes of child.stdout) {
      size += bytes.length;
      require(size <= limit);
      chunks.push(bytes);
    }
    const status = await child.status;
    const bytes = new Uint8Array(size);
    let offset = 0;
    for (const chunk of chunks) {
      bytes.set(chunk, offset);
      offset += chunk.length;
    }
    return {
      success: status.success,
      bytes,
      text: decoder.decode(bytes).replace(/\n+$/, ""),
    };
  } finally {
    try {
      child.kill("SIGKILL");
    } catch { /* exited */ }
    await child.status;
  }
}
async function account(
  user: string,
  group?: string,
  signal?: AbortSignal,
): Promise<Owner> {
  Account.parse(user);
  if (group !== undefined) Account.parse(group);
  const passwd = await runSystemProgram(
    "/usr/bin/getent",
    ["passwd", user],
    signal,
    {},
    8192,
  );
  const parts = passwd.text.split(":");
  require(
    passwd.success && parts.length === 7 && parts[0] === user &&
      /^\d+$/.test(parts[2]) && /^\d+$/.test(parts[3]),
  );
  const uid = Number(parts[2]), gid = Number(parts[3]);
  require(uid !== 0 && Deno.uid() === uid);
  const groups = await runSystemProgram(
    "/usr/bin/getent",
    ["group", String(gid)],
    signal,
    {},
    8192,
  );
  const fields = groups.text.split(":");
  require(groups.success && fields.length === 4 && fields[2] === String(gid));
  Account.parse(fields[0]);
  if (group !== undefined) require(fields[0] === group);
  DestroyPath.parse(parts[5]);
  return { user, group: fields[0], home: parts[5], uid, gid };
}
const gitSettings = [
  "core.hooksPath=/dev/null",
  "core.fsmonitor=false",
  "core.alternateRefsCommand=true",
  "core.sshCommand=ssh",
  "core.askPass=",
  "core.gitProxy=",
  "credential.helper=",
  "uploadpack.packObjectsHook=",
  "protocol.ext.allow=never",
];
async function git(
  args: string[],
  signal?: AbortSignal,
  privateRead?: z.infer<typeof GitRead>,
) {
  const material = GitRead.parse(privateRead ?? {});
  const env: Record<string, string> = {
    GIT_CONFIG_NOSYSTEM: "1",
    GIT_CONFIG_GLOBAL: "/dev/null",
    GIT_OPTIONAL_LOCKS: "0",
    GIT_TERMINAL_PROMPT: "0",
  };
  const settings = [...gitSettings];
  if (material.github_token) {
    // Only this process environment sees the credential. No token-bearing args,
    // temp file, stderr, native output, or caller-selected Git configuration.
    const entries = [
      [
        "http.https://github.com/.extraheader",
        "Authorization: Basic " +
        btoa("x-access-token:" + material.github_token),
      ],
      ["url.https://github.com/.insteadOf", "git@github.com:"],
      ["url.https://github.com/.insteadOf", "ssh://git@github.com/"],
    ];
    env.GIT_CONFIG_COUNT = String(entries.length);
    entries.forEach(([key, value], i) => {
      env[`GIT_CONFIG_KEY_${i}`] = key;
      env[`GIT_CONFIG_VALUE_${i}`] = value;
    });
    settings.push(
      "protocol.allow=never",
      "protocol.https.allow=always",
      "http.followRedirects=false",
    );
  }
  return await runSystemProgram(
    "/usr/bin/git",
    [...settings.flatMap((s) => ["-c", s]), ...args],
    signal,
    env,
  );
}
async function gitText(path: string, args: string[], signal?: AbortSignal) {
  const result = await git(["-C", path, ...args], signal);
  require(result.success);
  return result.text;
}
export function destroySourcePaths(
  input: Pick<Source, "root" | "operation" | "member">,
) {
  const state = input.root + "/.orbit-removals";
  const prefix = state + "/" + input.operation + "." + input.member;
  return {
    state,
    journal: prefix + ".journal",
    quarantine: prefix + ".quarantine",
    receipt: prefix + ".receipt",
    recovery: prefix + ".recovery",
  };
}
async function sourceOwner(input: Source, signal?: AbortSignal) {
  require(inside(input.checkout, input.root));
  await noLinks(input.checkout);
  const owner = await account(input.user, input.group, signal);
  await directory(input.root, owner);
  return owner;
}
async function state(input: Source, owner: Owner) {
  const p = destroySourcePaths(input);
  await directory(p.state, owner);
  await file(p.journal, owner, input.digest);
  return p;
}
async function writeOnce(path: string, contents: string, owner: Owner) {
  const temporary = parent(path) + "/.write-" + crypto.randomUUID();
  const handle = await localFilesystem().open(temporary, {
    createNew: true,
    write: true,
    mode: 0o600,
  });
  try {
    const bytes = encoder.encode(contents + "\n");
    let n = 0;
    while (n < bytes.length) n += await handle.write(bytes.subarray(n));
    await handle.sync();
  } finally {
    handle.close();
  }
  try {
    try {
      await localFilesystem().link(temporary, path);
    } catch (error) {
      if (!(error instanceof Deno.errors.AlreadyExists)) throw error;
    }
  } finally {
    await localFilesystem().remove(temporary);
  }
  await file(path, owner, contents);
  const dir = await localFilesystem().open(parent(path), { read: true });
  try {
    await dir.sync();
  } finally {
    dir.close();
  }
}
async function grouping(input: ReceiptInput, owner: Owner) {
  require(
    input.grouping_directory === parent(input.checkout) &&
      input.grouping_directory !== input.root &&
      inside(input.grouping_directory, input.root),
  );
  require(!await stat(input.checkout));
  const current = await stat(input.grouping_directory);
  if (
    !current || current.isSymlink || !current.isDirectory ||
    current.uid !== owner.uid || current.gid !== owner.gid
  ) return;
  await noLinks(input.grouping_directory);
  require(
    await Deno.realPath(input.grouping_directory) === input.grouping_directory,
  );
  for await (const _ of Deno.readDir(input.grouping_directory)) return;
  await localFilesystem().remove(input.grouping_directory);
}

/** Read-only preparation uses exactly the same source checks as finalization. */
async function inspect(input: {
  checkout: string;
  root: string;
  user: string;
  group: string;
  layout: string;
  branch: string;
  inspect_content: boolean;
  unresolved: boolean;
  allow_absent: boolean;
  origin: string;
  prepare_id: string;
}, signal?: AbortSignal): Promise<Record<string, unknown>> {
  DestroyPath.parse(input.checkout);
  DestroyPath.parse(input.root);
  require(
    inside(input.checkout, input.root) &&
      ["checkout", "worktree"].includes(input.layout),
  );
  const owner = await account(input.user, input.group, signal);
  await directory(input.root, owner);
  await noLinks(input.checkout);
  if (!await stat(input.checkout)) {
    require(input.allow_absent);
    return Object.fromEntries(
      Object.entries({
        top: input.checkout,
        common: input.checkout + "/.git",
        origin: input.origin,
        branch: input.branch,
        commit: "0".repeat(40),
        dirty: "",
        source_identity: "absent",
        worktrees: `worktree ${input.checkout}\0\0`,
      }).map(([key, value]) => [key, encoded(value)]),
    );
  }
  const info = await directory(input.checkout, owner);
  await directory(parent(input.checkout), owner);
  const layout = await gitLayout(input.checkout, input.layout, owner, signal);
  if (input.prepare_id) {
    require(/^[A-Za-z0-9_-]+$/.test(input.prepare_id));
    await file(
      layout.admin + "/orbit-source-prepare",
      owner,
      `${input.prepare_id}:${identity(info)}`,
    );
  }
  const head = await git([
    "-C",
    input.checkout,
    "rev-parse",
    "--verify",
    "HEAD^{commit}",
  ], signal);
  require(input.unresolved || head.success);
  const branch = await git([
    "-C",
    input.checkout,
    "symbolic-ref",
    "--quiet",
    "--short",
    "HEAD",
  ], signal);
  require(input.unresolved || branch.text === input.branch);
  const origin = await gitText(input.checkout, [
    "config",
    "--get",
    "remote.origin.url",
  ], signal);
  require(origin && !origin.includes("\n"));
  const dirty = input.inspect_content
    ? (await gitText(input.checkout, [
        "status",
        "--porcelain",
        "--untracked-files=all",
      ], signal)
      ? "1"
      : "0")
    : "";
  const inventory = await git([
    "-C",
    input.checkout,
    "worktree",
    "list",
    "--porcelain",
    "-z",
  ], signal);
  require(inventory.success);
  return Object.fromEntries(
    Object.entries({
      top: input.checkout,
      common: layout.common,
      origin,
      branch: branch.text,
      commit: head.success ? head.text : "0".repeat(40),
      dirty,
      source_identity: identity(info),
      worktrees: inventory.bytes,
    }).map(([key, value]) => [key, encoded(value)]),
  );
}
async function gitLayout(
  path: string,
  layout: string,
  owner: Owner,
  signal?: AbortSignal,
) {
  require(
    await gitText(path, ["rev-parse", "--show-toplevel"], signal) === path,
  );
  const admin = await gitText(
    path,
    ["rev-parse", "--absolute-git-dir"],
    signal,
  );
  const common = await gitText(path, [
    "rev-parse",
    "--path-format=absolute",
    "--git-common-dir",
  ], signal);
  if (layout === "checkout") {
    await directory(path + "/.git", owner);
    require(admin === path + "/.git" && common === admin);
  } else {
    require(layout === "worktree" && admin !== common);
    await file(path + "/.git", owner);
    await directory(common, owner);
    await directory(admin, owner);
    require(parent(admin) === common + "/worktrees");
  }
  return { admin, common };
}

/** Uses an isolated bare repository, so source configuration cannot execute a
 * helper or alter the publication query. Existing private Git read is the only
 * secret type. This must run before any destructive phase, and again at delete. */
export async function checkDestroyPublication(
  origin: string,
  commit: string,
  rawRead: unknown,
  signal?: AbortSignal,
): Promise<boolean> {
  require(
    typeof origin === "string" && origin.length <= 4096 &&
      !/[\p{Cc}]/u.test(origin) &&
      /^(?:https:\/\/|ssh:\/\/|git@|\/)/.test(origin),
  );
  require(/^[a-f0-9]{40}(?:[a-f0-9]{24})?$/.test(commit));
  const material = GitRead.parse(rawRead);
  const scratch = await localFilesystem().makeTempDir({
    prefix: "orbit-publication-",
  });
  await localFilesystem().chmod(scratch, 0o700);
  try {
    const repository = scratch + "/repository.git";
    require(
      (await git(["init", "--bare", "--quiet", repository], signal)).success,
    );
    const prefix = ["--git-dir=" + repository];
    require(
      (await git([...prefix, "remote", "add", "origin", origin], signal))
        .success,
    );
    require(
      (await git(
        [
          ...prefix,
          "fetch",
          "--quiet",
          "--no-tags",
          "--filter=blob:none",
          "origin",
          "+refs/heads/*:refs/remotes/origin/*",
          "+refs/tags/*:refs/tags/*",
        ],
        signal,
        material,
      )).success,
    );
    if (
      !(await git([...prefix, "cat-file", "-e", commit + "^{commit}"], signal))
        .success
    ) return false;
    const refs = await git([
      ...prefix,
      "for-each-ref",
      "--format=%(refname)",
      "refs/remotes/origin",
      "refs/tags",
    ], signal);
    require(refs.success);
    for (const ref of refs.text.split("\n").filter(Boolean)) {
      const tip = await git([
        ...prefix,
        "rev-parse",
        "--verify",
        ref + "^{commit}",
      ], signal);
      if (
        tip.success &&
        (await git(
          [...prefix, "merge-base", "--is-ancestor", commit, tip.text],
          signal,
        )).success
      ) return true;
    }
    return false;
  } finally {
    await localFilesystem().remove(scratch, { recursive: true });
  }
}

async function prepare(input: Source, signal?: AbortSignal) {
  const owner = await sourceOwner(input, signal);
  const p = destroySourcePaths(input);
  if (!await stat(p.state)) {
    try {
      await localFilesystem().mkdir(p.state, { mode: 0o700 });
    } catch (e) {
      if (!(e instanceof Deno.errors.AlreadyExists)) throw e;
    }
  }
  await directory(p.state, owner);
  require(
    !await stat(p.receipt) && !await stat(p.quarantine) &&
      !await stat(p.recovery),
  );
  if (input.source_identity === "absent") require(!await stat(input.checkout));
  else {
    require(
      identity(await directory(input.checkout, owner)) ===
        input.source_identity,
    );
    await directory(parent(input.checkout), owner);
    const layout = await gitLayout(input.checkout, input.layout, owner, signal);
    if (input.layout === "worktree") {
      require(layout.common === input.common_repository + "/.git");
    }
  }
  await writeOnce(p.journal, input.digest, owner);
  return {};
}
async function readState(input: ReceiptInput, signal?: AbortSignal) {
  const owner = await sourceOwner(input, signal);
  const p = await state(input, owner);
  const checkout = await stat(input.checkout),
    quarantine = await stat(p.quarantine),
    receipt = await stat(p.receipt);
  if (input.source_identity === "absent") {
    require(!checkout && !quarantine);
    if (receipt) {
      await file(p.receipt, owner, input.receipt);
      await grouping(input, owner);
    }
    return { state: receipt ? "completed" : "present" };
  }
  if (checkout) {
    require(!quarantine && !receipt && !await stat(p.recovery));
    require(
      identity(await directory(input.checkout, owner)) ===
        input.source_identity,
    );
    return { state: "present" };
  }
  if (quarantine) {
    require(
      identity(await directory(p.quarantine, owner)) === input.source_identity,
    );
    if (receipt) await file(p.receipt, owner, input.receipt);
    return { state: receipt ? "receipt_pending_cleanup" : "quarantined" };
  }
  await file(p.receipt, owner, input.receipt);
  await grouping(input, owner);
  return { state: "completed" };
}
async function stopTreeProcesses(
  path: string,
  ownUnits: string[],
  signal?: AbortSignal,
) {
  const find = async () => {
    const found = [];
    for await (const entry of Deno.readDir("/proc")) {
      if (!/^\d+$/.test(entry.name) || Number(entry.name) === Deno.pid) {
        continue;
      }
      const base = "/proc/" + entry.name;
      for (const link of ["cwd", "exe"]) {
        let target: string;
        try {
          if ((await Deno.lstat(base + "/" + link)).uid !== Deno.uid()) {
            continue;
          }
          target = await Deno.readLink(base + "/" + link);
        } catch (e) {
          if (
            e instanceof Deno.errors.NotFound ||
            e instanceof Deno.errors.PermissionDenied
          ) continue;
          throw e;
        }
        if (
          target !== path && !inside(target, path) &&
          target !== path + " (deleted)"
        ) continue;
        let unit = "", name = "";
        try {
          unit =
            (await Deno.readTextFile(base + "/cgroup")).trim().split("/").at(
              -1,
            ) ?? "";
          name = (await Deno.readTextFile(base + "/comm")).trim();
        } catch (e) {
          if (e instanceof Deno.errors.NotFound) continue;
          throw e;
        }
        if (
          ownUnits.includes(unit) ||
          ["tmux: server", "screen", "SCREEN"].includes(name)
        ) continue;
        found.push(Number(entry.name));
        break;
      }
    }
    return found;
  };
  for (const killSignal of ["SIGTERM", "SIGKILL"] as const) {
    const deadline = Date.now() + 5000;
    do {
      signal?.throwIfAborted();
      const pids = await find();
      if (!pids.length) return;
      for (const pid of pids) {
        try {
          Deno.kill(pid, killSignal);
        } catch (e) {
          if (!(e instanceof Deno.errors.NotFound)) throw e;
        }
      }
      await new Promise((resolve) => setTimeout(resolve, 100));
    } while (Date.now() < deadline);
  }
  require(!(await find()).length);
}
async function recovery(input: ReceiptInput, owner: Owner) {
  const p = destroySourcePaths(input),
    common = input.common_repository + "/.git",
    worktrees = common + "/worktrees";
  await file(p.recovery, owner);
  const fields = (await Deno.readTextFile(p.recovery)).replace(/\n$/, "").split(
    "\n",
  );
  require(
    fields.length === 4 && fields.slice(1).every((s) => /^\d+:\d+$/.test(s)),
  );
  const admin = decoder.decode(
    Uint8Array.from(atob(fields[0]), (c) => c.charCodeAt(0)),
  );
  require(encoded(admin) === fields[0] && parent(admin) === worktrees);
  require(
    identity(await directory(common, owner)) === fields[2] &&
      identity(await directory(worktrees, owner)) === fields[3],
  );
  const adminStat = await stat(admin);
  if (adminStat) require(identity(await directory(admin, owner)) === fields[1]);
  for await (const entry of Deno.readDir(worktrees)) {
    const candidate = worktrees + "/" + entry.name;
    await directory(candidate, owner);
    const pointer = candidate + "/gitdir";
    if (!await stat(pointer)) continue;
    await file(pointer, owner);
    const points = (await Deno.readTextFile(pointer)).replace(/\n$/, "");
    if (
      points === input.checkout + "/.git" || points === p.quarantine + "/.git"
    ) require(candidate === admin);
    if (candidate === admin) require(points === p.quarantine + "/.git");
  }
  if (await stat(p.quarantine + "/.git")) {
    await file(p.quarantine + "/.git", owner, "gitdir: " + admin);
  }
  return admin;
}
async function cleanup(input: ReceiptInput, signal?: AbortSignal) {
  const owner = await sourceOwner(input, signal), p = await state(input, owner);
  await file(p.receipt, owner, input.receipt);
  require(!await stat(input.checkout));
  const quarantine = await stat(p.quarantine);
  if (quarantine) {
    require(
      identity(await directory(p.quarantine, owner)) === input.source_identity,
    );
  }
  if (input.layout === "worktree") {
    const admin = await recovery(input, owner);
    if (await stat(admin)) {
      await localFilesystem().remove(admin, { recursive: true });
    }
  } else require(!await stat(p.recovery));
  if (quarantine) {
    await localFilesystem().remove(p.quarantine, { recursive: true });
  }
  require(!await stat(p.quarantine));
  await grouping(input, owner);
  return { receipt: input.receipt };
}

function inventoryEntries(
  bytes: Uint8Array,
  original: string,
  physical: string,
) {
  const value = decoder.decode(bytes);
  require(value.endsWith("\0\0"));
  return value.slice(0, -2).split("\0\0").map((entry) => {
    const fields = entry.split("\0");
    require(fields[0].startsWith("worktree "));
    const path = fields[0].slice(9);
    fields[0] = "worktree " + (path === original ? physical : path);
    return fields.join("\0");
  }).sort();
}

/** Last local identity/content fence. The parent must also call this before its
 * first database/drop effect; finalization repeats it to fence intervening edits. */
export async function verifyDestroySource(raw: unknown, signal?: AbortSignal) {
  const input = Finalize.parse(raw),
    owner = await sourceOwner(input, signal),
    p = await state(input, owner);
  let physical = input.checkout;
  if (!await stat(physical)) {
    if (input.source_identity === "absent") {
      require(!await stat(p.quarantine));
      return { absent: true };
    }
    if (!await stat(p.quarantine)) {
      await file(p.receipt, owner, input.receipt);
      return { absent: true };
    }
    physical = p.quarantine;
  } else {
    require(
      !await stat(p.quarantine) && !await stat(p.receipt) &&
        !await stat(p.recovery),
    );
  }
  if (await stat(p.receipt)) await file(p.receipt, owner, input.receipt);
  require(identity(await directory(physical, owner)) === input.source_identity);
  await directory(parent(physical), owner);
  const layout = await gitLayout(physical, input.layout, owner, signal);
  if (input.layout === "worktree") {
    require(layout.common === input.common_repository + "/.git");
    if (await stat(p.recovery)) await recovery(input, owner);
  }
  const branch = await git([
    "-C",
    physical,
    "symbolic-ref",
    "--quiet",
    "--short",
    "HEAD",
  ], signal);
  require(input.unresolved || branch.text === input.branch);
  const head = await git([
    "-C",
    physical,
    "rev-parse",
    "--verify",
    "HEAD^{commit}",
  ], signal);
  require((head.success ? head.text : "0".repeat(40)) === input.commit);
  require(
    await gitText(
      physical,
      ["config", "--get", "remote.origin.url"],
      signal,
    ) === input.origin,
  );
  if (
    input.layout !== "worktree" || !input.force || !input.independent_worktree
  ) {
    const inventory = await git([
      "-C",
      physical,
      "worktree",
      "list",
      "--porcelain",
      "-z",
    ], signal);
    const accepted = Uint8Array.from(
      atob(input.worktrees),
      (c) => c.charCodeAt(0),
    );
    require(encoded(accepted) === input.worktrees);
    require(
      inventory.success &&
        JSON.stringify(
            inventoryEntries(inventory.bytes, input.checkout, physical),
          ) ===
          JSON.stringify(inventoryEntries(accepted, input.checkout, physical)),
    );
  }
  if (!input.force) {
    if (
      await gitText(physical, [
        "status",
        "--porcelain",
        "--untracked-files=all",
      ], signal)
    ) throw new Error("instance.unsafe_content");
    if (
      !await checkDestroyPublication(
        input.origin,
        input.commit,
        input.git_read,
        signal,
      )
    ) throw new Error("instance.unsafe_content");
  }
  return { absent: false, physical, admin: layout.admin };
}
async function finalize(input: FinalizeInput, signal?: AbortSignal) {
  const checked = await verifyDestroySource(input, signal),
    owner = await sourceOwner(input, signal),
    p = await state(input, owner);
  if (checked.absent) {
    await writeOnce(p.receipt, input.receipt, owner);
    await grouping(input, owner);
    return { state: "finalized", receipt: input.receipt };
  }
  let physical = checked.physical!;
  await stopTreeProcesses(physical, input.own_units, signal);
  if (physical === input.checkout) {
    if ((await Deno.lstat(physical)).dev !== (await Deno.lstat(p.state)).dev) {
      return { state: "cross_device", receipt: null };
    }
    // Recheck the inode immediately before the rename that authorizes deletion.
    require(
      identity(await directory(physical, owner)) === input.source_identity,
    );
    if (input.layout === "worktree") {
      require(
        (await git([
          "--git-dir=" + input.common_repository + "/.git",
          "worktree",
          "move",
          "--",
          physical,
          p.quarantine,
        ], signal)).success,
      );
    } else await localFilesystem().rename(physical, p.quarantine);
    physical = p.quarantine;
  }
  require(identity(await directory(physical, owner)) === input.source_identity);
  if (input.layout === "worktree") {
    const admin = await gitText(
      physical,
      ["rev-parse", "--absolute-git-dir"],
      signal,
    );
    const common = input.common_repository + "/.git",
      worktrees = common + "/worktrees";
    require(parent(admin) === worktrees);
    const record = [
      encoded(admin),
      identity(await directory(admin, owner)),
      identity(await directory(common, owner)),
      identity(await directory(worktrees, owner)),
    ].join("\n");
    await writeOnce(p.recovery, record, owner);
  } else require(!await stat(p.recovery));
  await writeOnce(p.receipt, input.receipt, owner);
  await cleanup(input, signal);
  return { state: "finalized", receipt: input.receipt };
}

/** Closed local dispatcher retained for injectable unit tests, not a transport. */
export async function destroySystem(
  commandName: string,
  raw: Record<string, unknown>,
  signal?: AbortSignal,
): Promise<Record<string, unknown>> {
  try {
    signal?.throwIfAborted();
    switch (commandName) {
      case "account.lookup": {
        const owner = await account(Account.parse(raw.user), undefined, signal);
        return { user: owner.user, group: owner.group, home: owner.home };
      }
      case "instance.inspect-source":
        return await inspect(
          z.object({
            checkout: DestroyPath,
            root: DestroyPath,
            user: Account,
            group: Account,
            layout: z.enum(["checkout", "worktree"]),
            branch: z.string().max(1024),
            inspect_content: z.boolean(),
            unresolved: z.boolean(),
            allow_absent: z.boolean(),
            origin: z.string().max(4096),
            prepare_id: z.string().max(256),
          }).strict().parse(raw),
          signal,
        );
      case "instance.check-publication":
        return {
          published: await checkDestroyPublication(
            z.string().parse(raw.origin),
            z.string().parse(raw.commit),
            raw.git_read,
            signal,
          ),
        };
      case "instance.prepare-source-removal":
        return await prepare(RemovalSource.parse(raw), signal);
      case "instance.read-source-removal-state":
        return await readState(Receipt.parse(raw), signal);
      case "instance.finalize-source-removal":
        return await finalize(Finalize.parse(raw), signal);
      case "instance.clean-removal-receipt":
        return await cleanup(Receipt.parse(raw), signal);
      default:
        return { error_code: "instance.source_command_invalid" };
    }
  } catch (error) {
    signal?.throwIfAborted();
    return {
      error_code:
        error instanceof Error && error.message === "instance.unsafe_content"
          ? "instance.unsafe_content"
          : "instance.removal_refused",
    };
  }
}
export const destroySystemRead = (
  call: DestroyReadCall,
  signal?: AbortSignal,
) => destroySystem(call.name, call.input, signal);
