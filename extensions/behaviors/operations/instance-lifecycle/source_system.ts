/** Instance source effects run on their placed native worker, never through orbit-agent. */
import { z } from "npm:zod@4.4.3";
import { createHash } from "node:crypto";
import type { NodeCall } from "../_lib/node-agent.ts";

const Path = z.string().max(4096).regex(/^\/(?:[^/]+\/)*[^/]+$/).refine((p) =>
  !p.split("/").some((part) => part === "." || part === "..") &&
  [...p].every((c) => c.charCodeAt(0) > 32 && c.charCodeAt(0) !== 127)
);
const Account = z.string().regex(/^[a-z_][a-z0-9_-]{0,63}$/).refine((p) =>
  p !== "root"
);
const Commit = z.string().regex(/^[a-f0-9]{40}(?:[a-f0-9]{24})?$/);
const Branch = z.string().min(1).max(255).refine((p) =>
  !p.startsWith("-") &&
  [...p].every((c) => c.charCodeAt(0) > 32 && c.charCodeAt(0) !== 127)
);
const GitRead = z.object({
  github_token: z.string().min(1).max(4096).regex(/^[A-Za-z0-9_.-]+$/)
    .nullable(),
}).strict();
const Source = z.object({
  repository: z.string().min(1).max(4096).refine((p) =>
    !p.startsWith("-") &&
    [...p].every((c) => ![0, 10, 13].includes(c.charCodeAt(0)))
  ),
  checkout: Path,
  root: Path,
  user: Account,
  group: Account,
  prepare_id: z.string().regex(/^[A-Za-z0-9_-]{1,100}$/).nullable().optional(),
  seed_repository: Path.nullable().optional(),
});
type Source = z.infer<typeof Source>;
type Identity = { user: string; group: string; uid: number; gid: number };
class Refusal extends Error {
  constructor(public code = "instance.source_invalid") {
    super(code);
  }
}
function refuse(
  condition: unknown,
  code = "instance.source_invalid",
): asserts condition {
  if (!condition) throw new Refusal(code);
}
const decoder = new TextDecoder("utf-8", { fatal: true });
const parent = (path: string) => path.slice(0, path.lastIndexOf("/")) || "/";
const inside = (path: string, root: string) => path.startsWith(`${root}/`);
const text = (output: Uint8Array) => decoder.decode(output).replace(/\n+$/, "");
async function stat(path: string) {
  try {
    return await Deno.lstat(path);
  } catch (error) {
    if (error instanceof Deno.errors.NotFound) return null;
    throw error;
  }
}
async function real(path: string) {
  const info = await stat(path);
  refuse(
    info?.isDirectory && !info.isSymlink && await Deno.realPath(path) === path,
  );
  return info;
}
async function parentChain(path: string) {
  let current = "";
  for (const part of Path.parse(path).split("/").slice(1)) {
    current += `/${part}`;
    if (await stat(current)) await real(current);
  }
}
const fixedEnvironment = {
  PATH: "/usr/local/bin:/usr/bin:/bin",
  LANG: "C.UTF-8",
  LC_ALL: "C.UTF-8",
  GIT_CONFIG_NOSYSTEM: "1",
  GIT_CONFIG_GLOBAL: "/dev/null",
  GIT_CONFIG_SYSTEM: "/dev/null",
  GIT_OPTIONAL_LOCKS: "0",
  GIT_TERMINAL_PROMPT: "0",
};
async function execute(
  program: string,
  args: string[],
  signal?: AbortSignal,
  env: Record<string, string> = {},
) {
  const child = new Deno.Command(program, {
    args,
    clearEnv: true,
    env: { ...fixedEnvironment, ...env },
    stdin: "null",
    stdout: "piped",
    stderr: "null",
    signal,
  }).spawn();
  const parts: Uint8Array[] = [];
  let size = 0;
  try {
    for await (const chunk of child.stdout) {
      size += chunk.length;
      if (size > 2097152) {
        child.kill("SIGKILL");
        throw new Refusal("instance.source_output_too_large");
      }
      parts.push(chunk);
    }
    const status = await child.status;
    const output = new Uint8Array(size);
    let at = 0;
    for (const chunk of parts) {
      output.set(chunk, at);
      at += chunk.length;
    }
    return { ok: status.success, output };
  } finally {
    await child.status.catch(() => {});
  }
}
async function account(user: string, signal?: AbortSignal): Promise<Identity> {
  Account.parse(user);
  const values = await Promise.all(
    ["-un", "-gn", "-u", "-g"].map(async (flag) => {
      const value = await execute("/usr/bin/id", [flag], signal);
      refuse(value.ok, "account.missing");
      return text(value.output);
    }),
  );
  refuse(
    values[0] === user && Number(values[2]) !== 0,
    "instance.account_mismatch",
  );
  return {
    user,
    group: values[1],
    uid: Number(values[2]),
    gid: Number(values[3]),
  };
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
  "http.followRedirects=false",
];
async function git(
  args: string[],
  signal?: AbortSignal,
  read?: z.infer<typeof GitRead>,
  protocols?: string[],
) {
  const settings = [...gitSettings];
  const env: Record<string, string> = {};
  if (protocols || read?.github_token) {
    settings.push("protocol.allow=never");
    for (
      const protocol of [
        "https",
        "http",
        "ssh",
        "git",
        "file",
        "ext",
        "ftp",
        "ftps",
      ]
    ) {
      const allowed = read?.github_token
        ? protocol === "https"
        : protocol !== "ext" && protocols?.includes(protocol);
      settings.push(
        `protocol.${protocol}.allow=${allowed ? "always" : "never"}`,
      );
    }
  }
  if (read?.github_token) {
    const entries = [
      [
        "http.https://github.com/.extraheader",
        `Authorization: Basic ${btoa(`x-access-token:${read.github_token}`)}`,
      ],
      ["url.https://github.com/.insteadOf", "git@github.com:"],
      ["url.https://github.com/.insteadOf", "ssh://git@github.com/"],
    ];
    env.GIT_CONFIG_COUNT = String(entries.length);
    entries.forEach(([key, value], i) => {
      env[`GIT_CONFIG_KEY_${i}`] = key;
      env[`GIT_CONFIG_VALUE_${i}`] = value;
    });
  }
  return await execute(
    "/usr/bin/git",
    [...settings.flatMap((v) => ["-c", v]), ...args],
    signal,
    env,
  );
}
async function gitValue(
  checkout: string,
  args: string[],
  signal?: AbortSignal,
  optional = false,
) {
  const result = await git(["-C", checkout, ...args], signal);
  if (!result.ok) {
    if (optional) return null;
    throw new Refusal("instance.source_git_failed");
  }
  return text(result.output);
}
async function runGit(
  args: string[],
  signal?: AbortSignal,
  read?: z.infer<typeof GitRead>,
  protocols?: string[],
) {
  refuse(
    (await git(args, signal, read, protocols)).ok,
    "instance.source_git_failed",
  );
}
async function sourceInspect(
  source: Source,
  verifyOrigin: boolean,
  signal?: AbortSignal,
) {
  const owner = await account(source.user, signal);
  refuse(source.group === owner.group);
  refuse(inside(source.checkout, source.root));
  await parentChain(parent(source.checkout));
  const info = await real(source.checkout);
  refuse(info.uid === owner.uid && info.gid === owner.gid);
  const dotGit = `${source.checkout}/.git`;
  refuse(!(await stat(dotGit))?.isSymlink);
  refuse(
    await gitValue(
      source.checkout,
      ["rev-parse", "--show-toplevel"],
      signal,
    ) === source.checkout,
  );
  const directory = (await gitValue(
    source.checkout,
    ["rev-parse", "--absolute-git-dir"],
    signal,
  ))!;
  const common = (await gitValue(source.checkout, [
    "rev-parse",
    "--path-format=absolute",
    "--git-common-dir",
  ], signal))!;
  for (const path of [directory, common]) {
    const entry = await real(path);
    refuse(entry.uid === owner.uid && entry.gid === owner.gid);
  }
  if (source.seed_repository) {
    refuse(common === `${source.seed_repository}/.git`);
  }
  if ((await stat(dotGit))?.isFile) {
    refuse(source.seed_repository && inside(directory, `${common}/worktrees`));
    refuse(
      (await Deno.readTextFile(`${directory}/gitdir`)).trimEnd() === dotGit,
    );
  } else refuse(directory === dotGit && common === dotGit);
  const origin = await gitValue(source.checkout, [
    "config",
    "--get",
    "remote.origin.url",
  ], signal);
  if (verifyOrigin) refuse(origin === source.repository);
  if (source.prepare_id) {
    const marker = `${directory}/orbit-source-prepare`,
      metadata = await stat(marker);
    refuse(
      metadata?.isFile && !metadata.isSymlink && metadata.uid === owner.uid &&
        metadata.gid === owner.gid,
    );
    refuse(
      (await Deno.readTextFile(marker)).trimEnd() ===
        `${source.prepare_id}:${info.dev}:${info.ino}`,
    );
  }
  return {
    owner,
    directory,
    common,
    origin,
    branch: await gitValue(
      source.checkout,
      ["symbolic-ref", "--quiet", "--short", "HEAD"],
      signal,
      true,
    ),
    commit: await gitValue(
      source.checkout,
      ["rev-parse", "--verify", "--quiet", "HEAD^{commit}"],
      signal,
      true,
    ),
  };
}
async function prepare(
  source: Source,
  raw: Record<string, unknown>,
  signal?: AbortSignal,
) {
  const input = z.object({
    allow_existing: z.boolean(),
    network: z.boolean(),
    seed_commit: Commit.nullable().optional(),
    git_read: GitRead,
  }).parse(raw);
  const owner = await account(source.user, signal);
  refuse(source.group === owner.group && inside(source.checkout, source.root));
  await parentChain(parent(source.checkout));
  if (await stat(source.checkout)) {
    refuse(input.allow_existing);
    await sourceInspect(source, true, signal);
    return { created: false };
  }
  await Deno.mkdir(parent(source.checkout), { recursive: true, mode: 0o755 });
  await Deno.mkdir(source.checkout, { mode: 0o755 });
  if (source.seed_repository && input.seed_commit) {
    await parentChain(source.seed_repository);
    refuse(
      await gitValue(source.seed_repository, [
        "config",
        "--get",
        "remote.origin.url",
      ], signal) === source.repository,
    );
    await runGit([
      "-C",
      source.seed_repository,
      "worktree",
      "add",
      "--no-checkout",
      "--detach",
      "--",
      source.checkout,
      input.seed_commit,
    ], signal);
  } else if (source.seed_repository || input.seed_commit) throw new Refusal();
  else if (input.network) {
    await runGit(
      [
        "clone",
        "--no-checkout",
        "--origin",
        "origin",
        "--",
        source.repository,
        source.checkout,
      ],
      signal,
      input.git_read,
      ["https", "ssh", "file"],
    );
  } else {
    await runGit(["init", "--quiet", "--", source.checkout], signal);
    await runGit([
      "-C",
      source.checkout,
      "remote",
      "add",
      "origin",
      source.repository,
    ], signal);
  }
  if (source.prepare_id) {
    const directory = (await gitValue(
      source.checkout,
      ["rev-parse", "--absolute-git-dir"],
      signal,
    ))!;
    const info = await Deno.lstat(source.checkout);
    await Deno.writeTextFile(
      `${directory}/orbit-source-prepare`,
      `${source.prepare_id}:${info.dev}:${info.ino}\n`,
      { createNew: true, mode: 0o600 },
    );
  }
  await sourceInspect(source, true, signal);
  return { created: true };
}
async function resolve(
  source: Source,
  raw: Record<string, unknown>,
  signal?: AbortSignal,
) {
  const input = z.object({
    instance_name: Branch,
    default_branch: Branch,
    branch_override: Branch.nullable().optional(),
    seed_commit: Commit.nullable().optional(),
    network: z.boolean(),
    protocols: z.array(z.enum(["https", "http", "ssh", "git", "file"])),
    git_read: GitRead,
  }).parse(raw);
  await sourceInspect(source, true, signal);
  // Checkout must never execute a filter program captured in a local seed's config.
  const filters = await gitValue(
    source.checkout,
    ["config", "--get-regexp", "^filter\\..*\\.(clean|smudge|process)$"],
    signal,
    true,
  );
  refuse(filters === null || filters === "", "instance.source_metadata_unsafe");
  if (input.network) {
    await runGit(
      [
        "-C",
        source.checkout,
        "fetch",
        "--quiet",
        "--no-tags",
        "--prune",
        "--",
        source.repository,
        "+refs/heads/*:refs/remotes/origin/*",
      ],
      signal,
      input.git_read,
      input.protocols,
    );
  }
  const has = async (ref: string) =>
    (await git(
      ["-C", source.checkout, "show-ref", "--verify", "--quiet", ref],
      signal,
    )).ok;
  let branch = input.branch_override ?? input.instance_name;
  let reference: string;
  if (input.seed_commit) reference = input.seed_commit;
  else if (input.instance_name === "default" && !input.branch_override) {
    branch = input.default_branch;
    reference = `refs/remotes/origin/${branch}`;
  } else if (await has(`refs/remotes/origin/${branch}`)) {
    reference = `refs/remotes/origin/${branch}`;
  } else if (await has(`refs/heads/${branch}`)) {
    reference = `refs/heads/${branch}`;
  } else reference = `refs/remotes/origin/${input.default_branch}`;
  if (!input.seed_commit) {
    refuse(await has(reference), "instance.source_git_failed");
  }
  const current = await gitValue(
    source.checkout,
    ["symbolic-ref", "--quiet", "--short", "HEAD"],
    signal,
    true,
  );
  const worktrees = await gitValue(source.checkout, [
    "worktree",
    "list",
    "--porcelain",
    "-z",
  ], signal);
  const occupied = (name: string) =>
    current !== name &&
    worktrees!.split("\0").includes(`branch refs/heads/${name}`);
  if (occupied(branch)) {
    refuse(source.prepare_id);
    branch = `orbit/instance/${source.prepare_id}`;
    refuse(!occupied(branch));
  }
  await runGit(
    ["-C", source.checkout, "check-ref-format", "--branch", branch],
    signal,
  );
  await runGit([
    "-C",
    source.checkout,
    "checkout",
    "--quiet",
    "--force",
    "--no-track",
    "-B",
    branch,
    reference,
  ], signal);
  refuse(
    await gitValue(
      source.checkout,
      ["symbolic-ref", "--short", "HEAD"],
      signal,
    ) === branch,
  );
  return {
    branch,
    commit: Commit.parse(
      await gitValue(source.checkout, [
        "rev-parse",
        "--verify",
        "HEAD^{commit}",
      ], signal),
    ),
  };
}
async function profile(raw: Record<string, unknown>, signal?: AbortSignal) {
  const input = z.object({
    application: Path,
    owner: Account,
    entry_point: z.enum(["artisan", "bin/console"]),
  }).parse(raw);
  const owner = await account(input.owner, signal);
  await parentChain(input.application);
  const composer = await stat(`${input.application}/composer.json`),
    entry = await stat(`${input.application}/${input.entry_point}`);
  const unsafe = { kind: "unsafe", composer: null, entry_point: null };
  if (
    composer &&
    (!composer.isFile || composer.isSymlink || composer.uid !== owner.uid ||
      composer.size > 1048576)
  ) return unsafe;
  if (!composer) {
    return {
      kind: entry ? "partial" : "none",
      composer: null,
      entry_point: null,
    };
  }
  await parentChain(parent(`${input.application}/${input.entry_point}`));
  const bytes = await Deno.readFile(`${input.application}/composer.json`);
  return {
    kind: "composer",
    entry_point: !entry
      ? "absent"
      : entry.isFile && !entry.isSymlink
      ? "regular"
      : "unsafe",
    composer: base64(bytes),
  };
}
function base64(bytes: Uint8Array) {
  let value = "";
  for (let i = 0; i < bytes.length; i += 16384) {
    value += String.fromCharCode(...bytes.subarray(i, i + 16384));
  }
  return btoa(value);
}
async function template(
  source: Source,
  raw: Record<string, unknown>,
  signal?: AbortSignal,
) {
  const input = z.object({ application: Path, commit: Commit }).parse(raw);
  const checked = await sourceInspect(source, true, signal);
  refuse(
    checked.commit === input.commit &&
      (input.application === source.checkout ||
        inside(input.application, source.checkout)),
  );
  const relative = input.application.slice(source.checkout.length).replace(
    /^\//,
    "",
  );
  const path = relative ? `${relative}/.env.example` : ".env.example";
  const tree = await git([
    "-C",
    source.checkout,
    "ls-tree",
    "-z",
    input.commit,
    "--",
    path,
  ], signal);
  refuse(tree.ok);
  if (!tree.output.length) return { environment_template: "" };
  const match = /^(100644|100755) blob ([a-f0-9]+)\t([^\0]+)\0$/.exec(
    decoder.decode(tree.output),
  );
  refuse(match && match[3] === path);
  const size = Number(
    await gitValue(source.checkout, ["cat-file", "-s", match[2]], signal),
  );
  refuse(Number.isSafeInteger(size) && size <= 131072);
  const blob = await git(
    ["-C", source.checkout, "cat-file", "blob", match[2]],
    signal,
  );
  refuse(blob.ok && blob.output.length === size);
  return { environment_template: decoder.decode(blob.output) };
}
async function registrationDigest(root: string, signal?: AbortSignal) {
  const hash = createHash("sha256");
  for (
    const args of [
      ["rev-parse", "HEAD"],
      ["symbolic-ref", "-q", "HEAD"],
      ["status", "--porcelain=v2", "--untracked-files=all"],
      ["config", "--local", "--null", "--list"],
      ["ls-files", "--stage", "-v"],
    ]
  ) {
    const value = await git(["-C", root, ...args], signal);
    if (args[0] === "status") refuse(value.ok);
    hash.update(`git\0${args.join("\0")}\0`);
    hash.update(value.output);
  }
  const walk = async (directory: string) => {
    const entries = [];
    for await (const entry of Deno.readDir(directory)) entries.push(entry);
    entries.sort((a, b) => a.name < b.name ? -1 : a.name > b.name ? 1 : 0);
    const subdirectories: string[] = [];
    for (const entry of entries) {
      if (directory === root && entry.name === ".git") continue;
      const path = `${directory}/${entry.name}`, info = await Deno.lstat(path);
      hash.update(
        `${path.slice(root.length + 1)}\0${(info.mode ?? 0) & 0o7777}\0`,
      );
      if (info.isSymlink) {
        hash.update("L");
        hash.update(await Deno.readLink(path));
      } else if (info.isFile) {
        hash.update("F");
        const file = await Deno.open(path, { read: true });
        try {
          for await (const part of file.readable) hash.update(part);
        } finally {
          try {
            file.close();
          } catch { /* readable closed it */ }
        }
      } else {
        hash.update("D");
        if (info.isDirectory) subdirectories.push(path);
      }
    }
    for (const directory of subdirectories) await walk(directory);
  };
  await walk(root);
  return hash.digest("hex");
}
async function registration(
  raw: Record<string, unknown>,
  signal?: AbortSignal,
) {
  const input = z.object({
    path: Path,
    include_worktrees: z.literal(false),
    user: Account,
    group: Account,
  }).parse(raw);
  const owner = await account(input.user, signal);
  refuse(input.group === owner.group);
  await parentChain(input.path);
  const top = await gitValue(
    input.path,
    ["rev-parse", "--show-toplevel"],
    signal,
  );
  refuse(top === input.path);
  const dotGit = `${input.path}/.git`, directory = await stat(dotGit);
  refuse(
    directory?.isDirectory && !directory.isSymlink,
    "instance.registration_conflict",
  );
  const common = await gitValue(input.path, [
    "rev-parse",
    "--path-format=absolute",
    "--git-common-dir",
  ], signal);
  refuse(common === dotGit);
  for (const path of [input.path, dotGit]) {
    const entry = await real(path);
    refuse(
      entry.uid === owner.uid && entry.gid === owner.gid &&
        ((entry.mode ?? 0) & 0o7002) === 0,
    );
  }
  const worktrees =
    (await gitValue(input.path, ["worktree", "list", "--porcelain"], signal))!
      .split("\n").filter((line) => line.startsWith("worktree ")).map((line) =>
        line.slice(9)
      );
  refuse(
    worktrees.length === 1 && worktrees[0] === input.path,
    "instance.registration_conflict",
  );
  const repository = (await gitValue(
    input.path,
    ["config", "--get", "remote.origin.url"],
    signal,
  ))!;
  const branch = await gitValue(
    input.path,
    ["symbolic-ref", "--short", "HEAD"],
    signal,
    true,
  );
  const defaultRef = await gitValue(
    input.path,
    ["symbolic-ref", "--short", "refs/remotes/origin/HEAD"],
    signal,
    true,
  );
  const slug = repository.replace(/\/+$/, "").split(/[/:]/).at(-1)!.replace(
    /\.git$/,
    "",
  ).toLowerCase().replace(/[^a-z0-9-]+/g, "-").replace(/^-|-$/g, "");
  return {
    rows: [{
      path: input.path,
      layout: "checkout",
      repository_url: repository,
      branch,
      detached: branch === null,
      commit: Commit.parse(
        await gitValue(
          input.path,
          ["rev-parse", "--verify", "HEAD^{commit}"],
          signal,
        ),
      ),
      source_digest: await registrationDigest(input.path, signal),
      default_branch: defaultRef?.replace(/^origin\//, "") ?? null,
      inferred_slug: slug,
      common_repository_path: common,
      worktree_paths: worktrees,
    }],
  };
}
async function adopt(raw: Record<string, unknown>, signal?: AbortSignal) {
  const input = z.object({
    operation: z.enum(["adopt", "adopt-finalize"]),
    members: z.array(
      z.object({
        source: Path,
        destination: Path,
        application: Path,
        commit: Commit,
        branch: z.string().nullable(),
        detached: z.boolean(),
        digest: z.string().regex(/^[a-f0-9]{64}$/),
      }),
    ).length(1),
  }).parse(raw);
  const member = input.members[0];
  refuse(
    member.source === member.destination &&
      (member.application === member.source ||
        inside(member.application, member.source)),
  );
  await parentChain(member.source);
  refuse(
    await gitValue(member.source, ["rev-parse", "--show-toplevel"], signal) ===
      member.source,
  );
  if (input.operation === "adopt") {
    refuse(
      await gitValue(
        member.source,
        ["rev-parse", "--verify", "HEAD^{commit}"],
        signal,
      ) === member.commit,
    );
    const branch = await gitValue(
      member.source,
      ["symbolic-ref", "--short", "HEAD"],
      signal,
      true,
    );
    refuse(
      branch === member.branch && (branch === null) === member.detached &&
        await registrationDigest(member.source, signal) === member.digest,
      "instance.registration_conflict",
    );
  } else {
    await parentChain(member.application);
    const path = `${member.application}/.env`, entry = await stat(path);
    if (entry?.isFile && !entry.isSymlink && (entry.mode ?? 0) & 0o007) {
      await Deno.chmod(path, (entry.mode ?? 0) & 0o770);
    }
  }
  return { identities: [] };
}
async function candidate(raw: Record<string, unknown>, signal?: AbortSignal) {
  const input = z.object({
    environment: z.literal("development"),
    base: Path,
    user: Account,
    repository: Source.shape.repository,
    configured_branch: Branch,
    target_branch: Branch,
    expected_source: Path,
    git_read: GitRead,
  }).parse(raw);
  await account(input.user, signal);
  refuse(
    input.base === input.expected_source,
    "instance.clone_candidate_changed",
  );
  await parentChain(input.base);
  refuse(
    await gitValue(input.base, ["rev-parse", "--show-toplevel"], signal) ===
      input.base,
  );
  refuse(
    await gitValue(
      input.base,
      ["symbolic-ref", "--quiet", "--short", "HEAD"],
      signal,
      true,
    ) === input.configured_branch,
    "instance.clone_candidate_branch_invalid",
  );
  const clean = await git([
    "-C",
    input.base,
    "status",
    "--porcelain=v1",
    "-z",
    "--untracked-files=all",
    "--ignore-submodules=none",
  ], signal);
  refuse(
    clean.ok && clean.output.length === 0,
    "instance.clone_candidate_dirty",
  );
  // Submodules require a separate recursive ownership port; refuse before any target source is created.
  const index = await gitValue(
    input.base,
    ["ls-files", "--stage", "-z"],
    signal,
  );
  refuse(
    !index?.split("\0").some((entry) => entry.startsWith("160000 ")),
    "instance.clone_candidate_source_invalid",
  );
  const commit = Commit.parse(
    await gitValue(
      input.base,
      ["rev-parse", "--verify", "HEAD^{commit}"],
      signal,
    ),
  );
  const scratch = await Deno.makeTempDir({ prefix: "orbit-clone-candidate-" });
  try {
    const bare = `${scratch}/repository.git`;
    await runGit(["init", "--quiet", "--bare", "--", bare], signal);
    await runGit(
      [
        `--git-dir=${bare}`,
        "fetch",
        "--quiet",
        "--no-tags",
        "--prune",
        "--",
        input.repository,
        "+refs/heads/*:refs/remotes/origin/*",
      ],
      signal,
      input.git_read,
      ["https", "ssh", "file"],
    );
    await runGit(
      [`--git-dir=${bare}`, "cat-file", "-e", `${commit}^{commit}`],
      signal,
    );
    await runGit([
      `--git-dir=${bare}`,
      "show-ref",
      "--verify",
      "--quiet",
      `refs/remotes/origin/${input.target_branch}`,
    ], signal);
  } finally {
    await Deno.remove(scratch, { recursive: true });
  }
  return { source: input.base, commit };
}

/** Closed operation adapter. `pin` is no longer used: no agent binary is invoked. */
export async function runLocalSource(
  call: NodeCall,
  signal?: AbortSignal,
): Promise<Record<string, unknown>> {
  const timeout = AbortSignal.timeout(
    Math.min(call.deadlineMs ?? 120000, 900000),
  );
  const boundedSignal = signal ? AbortSignal.any([signal, timeout]) : timeout;
  try {
    refuse(
      !call.sudo && !call.name.includes("production"),
      "instance.source_kind_unavailable",
    );
    refuse(
      [
        "account.lookup",
        "instance.inspect-development-profile",
        "instance.inspect-registration-source",
        "instance.relocate-registration-source",
        "instance.inspect-clone-candidate",
        "instance.prepare-development-source",
        "instance.resolve-development-source",
        "instance.read-development-environment-template",
        "instance.inspect-development-source",
      ].includes(call.name),
      "instance.source_kind_unavailable",
    );
    if (call.name === "account.lookup") {
      return await account(Account.parse(call.input.user), boundedSignal);
    }
    if (call.name === "instance.inspect-development-profile") {
      return await profile(call.input, boundedSignal);
    }
    if (call.name === "instance.inspect-registration-source") {
      return await registration(call.input, boundedSignal);
    }
    if (call.name === "instance.relocate-registration-source") {
      return await adopt(call.input, boundedSignal);
    }
    if (call.name === "instance.inspect-clone-candidate") {
      return await candidate(call.input, boundedSignal);
    }
    const source = Source.parse(call.input);
    switch (call.name) {
      case "instance.prepare-development-source":
        return await prepare(source, call.input, boundedSignal);
      case "instance.resolve-development-source":
        return await resolve(source, call.input, boundedSignal);
      case "instance.read-development-environment-template":
        return await template(source, call.input, boundedSignal);
      case "instance.inspect-development-source": {
        const input = z.object({
          verify_origin: z.boolean(),
          require_idle: z.boolean().optional(),
        }).parse(call.input);
        const value = await sourceInspect(
          source,
          input.verify_origin,
          boundedSignal,
        );
        if (input.require_idle) {
          refuse(
            (await execute("/usr/bin/flock", [
              "-n",
              source.checkout,
              "/usr/bin/true",
            ], boundedSignal)).ok,
            "instance.source_busy",
          );
        }
        return {
          origin: value.origin,
          branch: value.branch,
          commit: value.commit,
        };
      }
      default:
        throw new Refusal("instance.source_kind_unavailable");
    }
  } catch (error) {
    return {
      error_code: error instanceof Refusal
        ? error.code
        : boundedSignal.aborted
        ? "instance.source_execution_unconfirmed"
        : "instance.source_invalid",
    };
  }
}
