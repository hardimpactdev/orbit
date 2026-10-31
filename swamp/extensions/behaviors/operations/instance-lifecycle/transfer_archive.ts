import {
  assertLocalExecution,
  localCommand,
  localFilesystem,
} from "../_lib/local-worker.ts";
import { z } from "npm:zod@4.4.3";
import { createHash } from "node:crypto";
import { userInfo } from "node:os";
import type { FileContext } from "../database/sqlite_actions.ts";
import { repositoryIdentity } from "./source_identity.ts";

const Path = z.string().min(2).max(4096).startsWith("/").refine((value) =>
  [...value].every((c) => c.charCodeAt(0) > 32 && c.charCodeAt(0) !== 127) &&
  !value.endsWith("/") &&
  !value.split("/").slice(1).some((part) => ["", ".", ".."].includes(part))
);
const Commit = z.string().regex(/^[a-f0-9]{40}(?:[a-f0-9]{24})?$/);
const Digest = z.string().regex(/^[a-f0-9]{64}$/);
const User = z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/).refine((value) =>
  value !== "root"
);
const Branch = z.string().min(1).max(255).refine((value) =>
  !value.startsWith("-") &&
  [...value].every((c) => c.charCodeAt(0) > 32 && c.charCodeAt(0) !== 127)
);
const maxBytes = 2 ** 40;
export const TransferArchive = z.object({
  model_id: z.string().uuid(),
  name: z.string().regex(/^archive-[a-f0-9-]{36}$/),
  version: z.number().int().positive(),
  bytes: z.number().int().positive().max(maxBytes),
  sha256: Digest,
  head: Commit,
  branch: Branch.nullable(),
  layout: z.enum(["checkout", "worktree"]),
  repository: z.string().min(1).max(4096),
}).strict();
export const archiveFile = {
  description: "Private Instance checkout archive for this native workflow",
  contentType: "application/x-tar",
  lifetime: "workflow",
  garbageCollection: 1,
};

function owner(user: string) {
  assertLocalExecution();
  if (Deno.uid() === 0 || userInfo().username !== user) {
    throw new Error("instance.transfer_source_owner_invalid");
  }
}
async function realDirectory(path: string) {
  const info = await Deno.lstat(path);
  if (
    !info.isDirectory || info.isSymlink || await Deno.realPath(path) !== path
  ) {
    throw new Error("instance.transfer_source_invalid");
  }
  return info;
}
async function parents(path: string) {
  let current = "";
  for (const part of path.split("/").slice(1)) {
    current += `/${part}`;
    try {
      await realDirectory(current);
    } catch (error) {
      if (!(error instanceof Deno.errors.NotFound)) throw error;
    }
  }
}
async function run(
  command: string,
  args: string[],
  cwd: string,
  signal?: AbortSignal,
  extraEnv: Record<string, string> = {},
) {
  const result = await localCommand(command, {
    args,
    cwd,
    clearEnv: true,
    env: {
      PATH: "/usr/bin:/bin",
      LANG: "C.UTF-8",
      GIT_CONFIG_NOSYSTEM: "1",
      GIT_CONFIG_GLOBAL: "/dev/null",
      GIT_TERMINAL_PROMPT: "0",
      ...extraEnv,
    },
    stdin: "null",
    stdout: "piped",
    stderr: "null",
    signal,
  }).output();
  if (!result.success || result.stdout.length > 16777216) {
    throw new Error("instance.transfer_source_failed");
  }
  return new TextDecoder("utf-8", { fatal: true }).decode(result.stdout)
    .trimEnd();
}
const git = (
  cwd: string,
  args: string[],
  signal?: AbortSignal,
  index?: string,
) =>
  run(
    "/usr/bin/git",
    [
      "-c",
      "core.hooksPath=/dev/null",
      "-c",
      "core.fsmonitor=false",
      "-C",
      cwd,
      ...args,
    ],
    "/",
    signal,
    index === undefined ? {} : { GIT_INDEX_FILE: index },
  );

async function copy(
  stream: AsyncIterable<Uint8Array>,
  target: Deno.FsFile,
  expectedBytes: number,
  expectedDigest?: string,
) {
  const hash = createHash("sha256");
  let bytes = 0;
  for await (const chunk of stream) {
    bytes += chunk.length;
    if (bytes > expectedBytes) {
      throw new Error("instance.transfer_archive_invalid");
    }
    hash.update(chunk);
    let offset = 0;
    while (offset < chunk.length) {
      const written = await target.write(chunk.subarray(offset));
      if (written === 0) throw new Error("instance.transfer_archive_invalid");
      offset += written;
    }
  }
  const sha256 = hash.digest("hex");
  if (
    bytes !== expectedBytes ||
    (expectedDigest !== undefined && sha256 !== expectedDigest)
  ) {
    throw new Error("instance.transfer_archive_invalid");
  }
  await target.sync();
  return { bytes, sha256 };
}
/** Stream Git packs without buffering checkout bytes or logging object contents. */
async function gitStream(
  cwd: string,
  args: string[],
  input: ReadableStream<Uint8Array>,
  output: WritableStream<Uint8Array>,
  signal?: AbortSignal,
) {
  const child = localCommand("/usr/bin/git", {
    args: [
      "-c",
      "core.hooksPath=/dev/null",
      "-c",
      "core.fsmonitor=false",
      "-C",
      cwd,
      ...args,
    ],
    cwd: "/",
    clearEnv: true,
    env: {
      PATH: "/usr/bin:/bin",
      LANG: "C.UTF-8",
      GIT_CONFIG_NOSYSTEM: "1",
      GIT_CONFIG_GLOBAL: "/dev/null",
      GIT_TERMINAL_PROMPT: "0",
    },
    stdin: "piped",
    stdout: "piped",
    stderr: "null",
    signal,
  }).spawn();
  let bytes = 0;
  try {
    const [, , status] = await Promise.all([
      input.pipeTo(child.stdin),
      child.stdout.pipeThrough(
        new TransformStream<Uint8Array, Uint8Array>({
          transform(chunk, controller) {
            bytes += chunk.length;
            if (bytes > maxBytes) {
              throw new Error("instance.transfer_archive_invalid");
            }
            controller.enqueue(chunk);
          },
        }),
      ).pipeTo(output),
      child.status,
    ]);
    if (!status.success) throw new Error("instance.transfer_source_failed");
  } finally {
    try {
      child.kill("SIGTERM");
    } catch { /* exited */ }
    await child.status;
  }
}

function close(file?: Deno.FsFile) {
  try {
    file?.close();
  } catch { /* A consumed readable closes its file. */ }
}

/** The source Node exports bytes through Swamp's native file writer, never Gateway or workflow JSON. */
export async function captureTransferArchive(
  raw: unknown,
  runId: string,
  ctx: FileContext,
  signal?: AbortSignal,
) {
  const input = z.object({
    source: Path,
    user: User,
    layout: z.enum(["checkout", "worktree"]),
    repository_identity: z.string().min(1),
    branch: Branch.nullable(),
    sqlite_source: Path.nullable(),
  }).strict().parse(raw);
  z.string().uuid().parse(runId);
  owner(input.user);
  signal?.throwIfAborted();
  await parents(input.source);
  const repository = await git(input.source, [
    "config",
    "--get",
    "remote.origin.url",
  ], signal);
  if (
    (await realDirectory(input.source)).uid !== Deno.uid() ||
    await git(input.source, ["rev-parse", "--show-toplevel"], signal) !==
      input.source ||
    repositoryIdentity(repository) !== input.repository_identity
  ) {
    throw new Error("instance.transfer_source_invalid");
  }
  const gitEntry = await Deno.lstat(`${input.source}/.git`);
  if (
    (input.layout === "checkout" && !gitEntry.isDirectory) ||
    (input.layout === "worktree" && !gitEntry.isFile) || gitEntry.isSymlink
  ) {
    throw new Error("instance.transfer_source_invalid");
  }
  if (input.layout === "worktree") {
    for (const name of ["checkout.bundle", "checkout.index", "checkout.pack"]) {
      try {
        await Deno.lstat(`${input.source}/${name}`);
        throw new Error("instance.transfer_archive_invalid");
      } catch (error) {
        if (!(error instanceof Deno.errors.NotFound)) throw error;
      }
    }
  }
  const head = Commit.parse(
    await git(input.source, ["rev-parse", "HEAD^{commit}"], signal),
  );
  const symbolic = await git(input.source, [
    "rev-parse",
    "--abbrev-ref",
    "HEAD",
  ], signal);
  const branch = symbolic === "HEAD" ? null : Branch.parse(symbolic);
  if (branch !== input.branch) {
    throw new Error("instance.source_branch_mismatch");
  }
  if (
    input.sqlite_source !== null &&
    !input.sqlite_source.startsWith(input.source + "/")
  ) {
    throw new Error("instance.transfer_archive_invalid");
  }
  const scratch = await localFilesystem().makeTempDir({
    prefix: "orbit-transfer-",
  });
  let source: Deno.FsFile | undefined, target: Deno.FsFile | undefined;
  try {
    await localFilesystem().chmod(scratch, 0o700);
    const archive = `${scratch}/source.tar`;
    const created = await localFilesystem().open(archive, {
      createNew: true,
      write: true,
      mode: 0o600,
    });
    created.close();
    const exclusions: string[] = [];
    if (input.sqlite_source !== null) {
      const path = "." + input.sqlite_source.slice(input.source.length);
      exclusions.push(
        "--no-wildcards",
        "--anchored",
        ...["", "-wal", "-shm"].map((suffix) => `--exclude=${path}${suffix}`),
      );
    }
    if (input.layout === "worktree") {
      await git(input.source, [
        "bundle",
        "create",
        `${scratch}/checkout.bundle`,
        "HEAD",
      ], signal);
      const index = await git(input.source, [
        "rev-parse",
        "--path-format=absolute",
        "--git-path",
        "index",
      ], signal);
      const info = await Deno.lstat(index);
      if (!info.isFile || info.isSymlink || info.uid !== Deno.uid()) {
        throw new Error("instance.transfer_source_invalid");
      }
      const savedIndex = `${scratch}/checkout.index`;
      await localFilesystem().copyFile(index, savedIndex);
      await localFilesystem().chmod(savedIndex, 0o600);
      await git(
        input.source,
        ["update-index", "--no-split-index"],
        signal,
        savedIndex,
      );
      const entries = await git(
        input.source,
        ["ls-files", "--stage", "-z"],
        signal,
        savedIndex,
      );
      const objects = [
        ...new Set(
          entries.split("\0").filter(Boolean).map((entry) => {
            const match = /^[0-7]{6} ([a-f0-9]{40}(?:[a-f0-9]{24})?) [0-3]\t/
              .exec(entry);
            if (!match) throw new Error("instance.transfer_archive_invalid");
            return match[1];
          }),
        ),
      ].filter((id) => !/^0+$/.test(id));
      const pack = await localFilesystem().open(`${scratch}/checkout.pack`, {
        createNew: true,
        write: true,
        mode: 0o600,
      });
      try {
        await gitStream(
          input.source,
          ["pack-objects", "--stdout", "--revs"],
          new Blob([objects.join("\n") + "\n"]).stream(),
          pack.writable,
          signal,
        );
      } finally {
        close(pack);
      }
      exclusions.push("--no-wildcards", "--anchored", "--exclude=./.git");
    }
    await run(
      "/usr/bin/tar",
      [...exclusions, "--create", "--file", archive, "--", "."],
      input.source,
      signal,
    );
    if (input.layout === "worktree") {
      await run(
        "/usr/bin/tar",
        [
          "--append",
          "--file",
          archive,
          "--directory",
          scratch,
          "--",
          "checkout.bundle",
          "checkout.index",
          "checkout.pack",
        ],
        input.source,
        signal,
      );
    }
    const bytes = (await Deno.stat(archive)).size;
    if (bytes < 1 || bytes > maxBytes) {
      throw new Error("instance.transfer_archive_invalid");
    }
    source = await localFilesystem().open(archive, { read: true });
    const writer = ctx.createFileWriter("archive", `archive-${runId}`);
    const path = await writer.getFilePath();
    target = await localFilesystem().open(path, {
      create: true,
      truncate: true,
      write: true,
      mode: 0o600,
    });
    await localFilesystem().chmod(path, 0o600);
    const facts = await copy(source.readable, target, bytes);
    target.close();
    target = undefined;
    const handle = await writer.finalize();
    return TransferArchive.parse({
      model_id: ctx.modelId,
      name: handle.name,
      version: handle.version,
      ...facts,
      head,
      branch,
      layout: input.layout,
      repository,
    });
  } finally {
    close(source);
    close(target);
    await localFilesystem().remove(scratch, { recursive: true });
  }
}

const extract = `import sys,tarfile
with tarfile.open(sys.argv[1], 'r:') as archive:
    archive.extractall(sys.argv[2], filter='data')
`;

/** The destination worker verifies the native immutable file before publishing the checkout. */
export async function materializeTransferArchive(
  raw: unknown,
  rawArchive: unknown,
  ctx: FileContext,
  signal?: AbortSignal,
) {
  const input = z.object({
    destination: Path,
    root: Path,
    environment: Path,
    user: User,
  }).strict().parse(raw);
  const archive = TransferArchive.parse(rawArchive);
  owner(input.user);
  if (
    !input.destination.startsWith(input.root + "/") ||
    !input.environment.startsWith(input.destination + "/")
  ) {
    throw new Error("instance.transfer_archive_invalid");
  }
  signal?.throwIfAborted();
  await parents(input.destination);
  try {
    await Deno.lstat(input.destination);
    throw new Error("instance.transfer_destination_exists");
  } catch (error) {
    if (!(error instanceof Deno.errors.NotFound)) throw error;
  }
  const parent = input.destination.slice(0, input.destination.lastIndexOf("/"));
  await localFilesystem().mkdir(parent, { recursive: true });
  const scratch = await localFilesystem().makeTempDir({
    dir: parent,
    prefix: ".orbit-transfer-",
  });
  let file: Deno.FsFile | undefined;
  try {
    await localFilesystem().chmod(scratch, 0o700);
    const path = `${scratch}/archive.tar`, checkout = `${scratch}/checkout`;
    file = await localFilesystem().open(path, {
      createNew: true,
      write: true,
      mode: 0o600,
    });
    await copy(
      ctx.dataRepository.stream(
        ctx.modelType,
        archive.model_id,
        archive.name,
        archive.version,
      ),
      file,
      archive.bytes,
      archive.sha256,
    );
    file.close();
    file = undefined;
    await localFilesystem().mkdir(checkout, { mode: 0o700 });
    await run(
      "/usr/bin/python3",
      ["-I", "-c", extract, path, checkout],
      "/",
      signal,
    );
    if (archive.layout === "worktree") {
      try {
        await Deno.lstat(`${checkout}/.git`);
        throw new Error("instance.transfer_archive_invalid");
      } catch (error) {
        if (!(error instanceof Deno.errors.NotFound)) throw error;
      }
      await git(checkout, ["init", "--quiet"], signal);
      await git(checkout, [
        "-c",
        "protocol.file.allow=always",
        "fetch",
        "--quiet",
        "--",
        `${checkout}/checkout.bundle`,
        "HEAD",
      ], signal);
      await localFilesystem().remove(`${checkout}/checkout.bundle`);
      if (archive.branch !== null) {
        await git(checkout, [
          "symbolic-ref",
          "HEAD",
          `refs/heads/${archive.branch}`,
        ], signal);
        await git(checkout, ["update-ref", "HEAD", archive.head], signal);
      } else {
        await git(
          checkout,
          ["update-ref", "--no-deref", "HEAD", archive.head],
          signal,
        );
      }
      repositoryIdentity(archive.repository);
      await git(
        checkout,
        ["remote", "add", "origin", archive.repository],
        signal,
      );
      const pack = await localFilesystem().open(`${checkout}/checkout.pack`, {
        read: true,
      });
      try {
        await gitStream(
          checkout,
          ["index-pack", "--stdin"],
          pack.readable,
          new WritableStream<Uint8Array>({ write() {/* public pack hash */} }),
          signal,
        );
      } finally {
        close(pack);
      }
      await localFilesystem().remove(`${checkout}/checkout.pack`);
      await localFilesystem().rename(
        `${checkout}/checkout.index`,
        `${checkout}/.git/index`,
      );
    }
    if (
      await git(checkout, ["rev-parse", "HEAD^{commit}"], signal) !==
        archive.head
    ) throw new Error("instance.transfer_archive_invalid");
    const environment = checkout +
      input.environment.slice(input.destination.length);
    try {
      const info = await Deno.lstat(environment);
      if (
        !info.isFile || info.isSymlink ||
        await Deno.realPath(environment) !== environment
      ) throw new Error("instance.transfer_archive_invalid");
      await localFilesystem().chmod(environment, (info.mode ?? 0o600) & ~0o007);
    } catch (error) {
      if (!(error instanceof Deno.errors.NotFound)) throw error;
    }
    await localFilesystem().rename(checkout, input.destination);
    return { bytes: archive.bytes, sha256: archive.sha256, head: archive.head };
  } finally {
    close(file);
    await localFilesystem().remove(scratch, { recursive: true });
  }
}
