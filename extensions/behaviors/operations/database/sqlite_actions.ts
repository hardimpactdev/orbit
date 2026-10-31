import { z } from "npm:zod@4.4.3";
import { createHash } from "node:crypto";
import { userInfo } from "node:os";
import { runSqliteLocal, type SqliteInvoke } from "./sqlite_local.ts";

export const SqlitePath = z.string().startsWith("/").refine((v) =>
  v.length > 1 && !v.endsWith("/") && !/[\0\r\n]/.test(v) &&
  !/(?:^|\/)\.{1,2}(?:\/|$)|\/\//.test(v)
);
const User = z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/);
const Pin = z.string().regex(/^[a-f0-9]{64}$/);
const Placement = z.object({
  node_id: z.number().int().positive(),
  transport_user: User,
});
export const CloneSource = Placement.extend({
  variant: z.literal("clone"),
  checkout: SqlitePath,
  source: SqlitePath,
  directory: z.string().regex(/^\/tmp\/orbit-sqlite-clone-[a-f0-9]{32}$/),
}).strict().refine((v) => v.source.startsWith(v.checkout + "/"));
export const SeedSource = Placement.extend({
  variant: z.literal("seed"),
  target_instance_id: z.number().int().positive(),
  base: SqlitePath,
  source_path: SqlitePath,
  source_user: User,
  operation: Pin,
  state_dir: SqlitePath,
  snapshot_path: SqlitePath,
}).strict().refine((v) =>
  v.source_path.startsWith(v.base + "/") &&
  v.snapshot_path === `/tmp/orbit-sqlite-${v.operation}.sqlite` &&
  new RegExp(
    `^/var/lib/orbit/app-instance-sqlite-seeds/source-[1-9][0-9]*-target-${v.target_instance_id}(?:-${v.operation})?$`,
  ).test(v.state_dir)
);
export const SnapshotSource = z.union([CloneSource, SeedSource]);
export const SeedTarget = Placement.extend({
  target_instance_id: z.number().int().positive(),
  base: SqlitePath,
  target_user: User,
  operation: Pin,
  state_dir: SqlitePath,
  environment: z.enum(["development", "production"]),
  relative_path: z.string().min(1).refine((v) =>
    !v.startsWith("/") && !/[\0\r\n]/.test(v) &&
    !/(?:^|\/)\.{1,2}(?:\/|$)|\/\//.test(v)
  ),
}).strict().refine((v) =>
  new RegExp(
    `^/var/lib/orbit/app-instance-sqlite-seeds/target-${v.target_instance_id}(?:-${v.operation})?$`,
  ).test(v.state_dir) &&
  (v.environment !== "production" ||
    (v.base === `/home/${v.target_user}` &&
      v.relative_path === "database.sqlite"))
);
export const SnapshotRef = z.object({
  model_id: z.string().uuid(),
  name: z.string().regex(/^snapshot-[a-f0-9-]{36}$/),
  version: z.number().int().positive(),
  bytes: z.number().int().min(16).max(2 ** 40),
  sha256: Pin,
  mode: z.number().int().min(0).max(0o7777).optional(),
}).strict();
export type Snapshot = z.infer<typeof SnapshotRef>;
export type Source = z.infer<typeof SnapshotSource>;
export type Target = z.infer<typeof SeedTarget>;
export type Invoke = SqliteInvoke;
export type Handle = { name: string; version: number };
export type FileContext = {
  modelId: string;
  modelType: { normalized: string };
  createFileWriter(spec: string, name: string): {
    getFilePath(): Promise<string>;
    finalize(): Promise<Handle>;
  };
  dataRepository: {
    stream(
      type: { normalized: string },
      modelId: string,
      name: string,
      version: number,
    ): AsyncIterable<Uint8Array>;
  };
};
export const snapshotFile = {
  description: "Private database snapshot for one native run",
  contentType: "application/vnd.sqlite3",
  lifetime: "workflow",
  garbageCollection: 1,
};

function owner(user: string) {
  if (Deno.uid() === 0 || userInfo().username !== user) {
    throw new Error("database.sqlite_owner_unavailable");
  }
}
async function privateFile(path: string, write = false) {
  if (await Deno.realPath(path) !== path) {
    throw new Error("database.sqlite_path_refused");
  }
  const before = await Deno.lstat(path);
  if (
    !before.isFile || before.isSymlink || before.uid !== Deno.uid() ||
    before.mode === null || (before.mode & 0o7777) !== 0o600 ||
    before.nlink !== 1
  ) {
    throw new Error("database.sqlite_file_refused");
  }
  const file = await Deno.open(path, { read: !write, write });
  const after = await file.stat();
  if (
    before.ino !== after.ino || before.dev !== after.dev || !after.isFile ||
    after.uid !== Deno.uid() || after.nlink !== 1 || after.mode === null ||
    (after.mode & 0o7777) !== 0o600
  ) {
    file.close();
    throw new Error("database.sqlite_file_refused");
  }
  return file;
}
async function copy(
  stream: AsyncIterable<Uint8Array>,
  target: Deno.FsFile,
  expected: { bytes: number; sha256: string },
) {
  const hash = createHash("sha256");
  let size = 0;
  let header = new Uint8Array();
  for await (const chunk of stream) {
    size += chunk.length;
    if (size > expected.bytes) {
      throw new Error("database.sqlite_snapshot_invalid");
    }
    if (header.length < 16) {
      const next = new Uint8Array(Math.min(16, header.length + chunk.length));
      next.set(header);
      next.set(chunk.subarray(0, next.length - header.length), header.length);
      header = next;
    }
    hash.update(chunk);
    let offset = 0;
    while (offset < chunk.length) {
      const count = await target.write(chunk.subarray(offset));
      if (count === 0) throw new Error("database.sqlite_write_failed");
      offset += count;
    }
  }
  if (
    size !== expected.bytes || hash.digest("hex") !== expected.sha256 ||
    new TextDecoder().decode(header) !== "SQLite format 3\0"
  ) {
    throw new Error("database.sqlite_snapshot_invalid");
  }
  await target.sync();
}
async function cloneCleanupPreflight(source: z.infer<typeof CloneSource>) {
  let directory: Deno.FileInfo;
  try {
    directory = await Deno.lstat(source.directory);
  } catch (error) {
    if (error instanceof Deno.errors.NotFound) return;
    throw error;
  }
  if (
    !directory.isDirectory || directory.isSymlink ||
    directory.uid !== Deno.uid() ||
    directory.mode === null || (directory.mode & 0o7777) !== 0o700 ||
    await Deno.realPath(source.directory) !== source.directory
  ) {
    throw new Error("database.sqlite_cleanup_refused");
  }
  const partials: string[] = [];
  for await (const entry of Deno.readDir(source.directory)) {
    if (
      entry.name !== "snapshot.sqlite" &&
      !/^\.orbit-sqlite-[a-f0-9]{16}$/.test(entry.name)
    ) {
      throw new Error("database.sqlite_cleanup_refused");
    }
    const path = source.directory + "/" + entry.name;
    const file = await privateFile(path);
    file.close();
    if (entry.name !== "snapshot.sqlite") partials.push(path);
  }
  for (const path of partials) await Deno.remove(path);
}

async function command(
  source: Source,
  mode: "prepare" | "cleanup",
  invoke: Invoke,
  signal?: AbortSignal,
) {
  owner(source.transport_user);
  if (source.variant === "clone") {
    if (mode === "cleanup") await cloneCleanupPreflight(source);
    return await invoke({
      action: "copy",
      input: mode === "cleanup"
        ? { operation: "cleanup", directory: source.directory }
        : {
          operation: "export",
          checkout: source.checkout,
          source: source.source,
          directory: source.directory,
        },
    }, signal);
  }
  const { variant: _, node_id: __, target_instance_id: ___, ...input } = source;
  return await invoke(
    { action: "seedSource", input: { ...input, mode } },
    signal,
  );
}

/** Snapshot bytes enter only a native file writer; the returned value is metadata. */
export async function exportSnapshot(
  raw: unknown,
  ctx: FileContext,
  name: string,
  invoke: Invoke = runSqliteLocal,
  signal?: AbortSignal,
): Promise<Snapshot> {
  let sourceFile: Deno.FsFile | undefined;
  let nativeFile: Deno.FsFile | undefined;
  try {
    const source = SnapshotSource.parse(raw);
    if (!/^snapshot-[a-f0-9-]{36}$/.test(name)) throw new Error("invalid name");
    if (source.variant === "clone") {
      const cleaned = await command(source, "cleanup", invoke, signal);
      if (cleaned.error_code !== undefined) throw new Error("cleanup refused");
    }
    const output = z.object({
      bytes: z.number().int().min(16).max(2 ** 40),
      sha256: Pin,
      mode: z.number().int().min(0).max(0o7777).optional(),
    }).parse(await command(source, "prepare", invoke, signal));
    const path = source.variant === "clone"
      ? source.directory + "/snapshot.sqlite"
      : source.snapshot_path;
    sourceFile = await privateFile(path);
    if ((await sourceFile.stat()).size !== output.bytes) {
      throw new Error("invalid size");
    }
    const writer = ctx.createFileWriter("snapshot", name);
    const nativePath = await writer.getFilePath();
    // Local writer allocation returns an absent path; remote spool allocation creates it.
    nativeFile = await Deno.open(nativePath, {
      create: true,
      truncate: true,
      write: true,
      mode: 0o600,
    });
    await Deno.chmod(nativePath, 0o600);
    await copy(sourceFile.readable, nativeFile, output);
    nativeFile.close();
    nativeFile = undefined;
    const handle = await writer.finalize();
    return SnapshotRef.parse({
      model_id: ctx.modelId,
      name: handle.name,
      version: handle.version,
      ...output,
    });
  } catch {
    throw new Error("database.sqlite_export_failed");
  } finally {
    try {
      sourceFile?.close();
    } catch { /* Stream iteration closes its file. */ }
    nativeFile?.close();
  }
}

/** Native immutable artifact read; preserves the exact prepared incoming inode. */
export async function receiveSnapshot(
  raw: {
    transport_user: string;
    base: string;
    path: string;
  },
  rawRef: unknown,
  ctx: FileContext,
): Promise<void> {
  let file: Deno.FsFile | undefined;
  try {
    const target = z.object({
      transport_user: User,
      base: SqlitePath,
      path: SqlitePath,
    }).strict().parse(raw);
    const ref = SnapshotRef.parse(rawRef);
    owner(target.transport_user);
    const seedPath = /^\/tmp\/orbit-sqlite-[a-f0-9]{16}-[a-f0-9]{16}$/.test(
      target.path,
    );
    const clonePath = target.path.startsWith(target.base + "/") &&
      /\/\.orbit-sqlite-[a-f0-9]{32}$/.test(target.path);
    if (!seedPath && !clonePath) throw new Error("invalid target");
    if (clonePath) {
      const parent = target.path.slice(0, target.path.lastIndexOf("/"));
      if (await Deno.realPath(parent) !== parent) {
        throw new Error("invalid parent");
      }
      const info = await Deno.lstat(parent);
      if (!info.isDirectory || info.uid !== Deno.uid()) {
        throw new Error("foreign parent");
      }
      try {
        const created = await Deno.open(target.path, {
          createNew: true,
          write: true,
          mode: 0o600,
        });
        created.close();
      } catch (error) {
        if (!(error instanceof Deno.errors.AlreadyExists)) throw error;
      }
    }
    file = await privateFile(target.path, true);
    await file.truncate(0);
    await copy(
      ctx.dataRepository.stream(
        ctx.modelType,
        ref.model_id,
        ref.name,
        ref.version,
      ),
      file,
      ref,
    );
  } catch {
    throw new Error("database.sqlite_transfer_failed");
  } finally {
    file?.close();
  }
}

export async function cleanupSnapshot(
  raw: unknown,
  invoke: Invoke = runSqliteLocal,
  signal?: AbortSignal,
): Promise<void> {
  try {
    const result = await command(
      SnapshotSource.parse(raw),
      "cleanup",
      invoke,
      signal,
    );
    if (result.error_code !== undefined) throw new Error("cleanup refused");
  } catch {
    throw new Error("database.sqlite_cleanup_failed");
  }
}
export async function seedTarget(
  raw: unknown,
  mode: "prepare" | "install" | "cleanup",
  ref: Snapshot | undefined,
  invoke: Invoke = runSqliteLocal,
  signal?: AbortSignal,
) {
  try {
    const { node_id: _, target_instance_id: __, ...input } = SeedTarget.parse(
      raw,
    );
    owner(input.transport_user);
    const result = await invoke({
      action: "seedTarget",
      input: {
        ...input,
        mode,
        expected_size: mode === "cleanup" ? 0 : SnapshotRef.parse(ref).bytes,
        expected_digest: mode === "cleanup"
          ? ""
          : SnapshotRef.parse(ref).sha256,
      },
    }, signal);
    return z.discriminatedUnion("state", [
      z.object({
        state: z.literal("ready"),
        incoming: z.string().regex(
          /^\/tmp\/orbit-sqlite-[a-f0-9]{16}-[a-f0-9]{16}$/,
        ),
      }).strict(),
      z.object({ state: z.enum(["resume", "complete", "changed", "cleaned"]) })
        .strict(),
    ]).parse(result);
  } catch {
    throw new Error("database.sqlite_target_failed");
  }
}
