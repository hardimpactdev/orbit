import {
  assertLocalExecution,
  localCommand,
  localFilesystem,
} from "../_lib/local-worker.ts";
import { z } from "npm:zod@4.4.3";
import { userInfo } from "node:os";

export const TransferPath = z.string().min(2).max(4096).startsWith("/").refine((
  v,
) =>
  [...v].every((c) => c.charCodeAt(0) > 32 && c.charCodeAt(0) !== 127) &&
  v.split("/").slice(1).every((p) => p !== "" && p !== "." && p !== "..")
);
export const TransferPathProof = z.object({
  path: TransferPath,
  user: z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/),
  device: z.number().int().nonnegative(),
  inode: z.number().int().positive(),
  layout: z.enum(["checkout", "worktree"]),
  common: TransferPath,
}).strict();
async function git(path: string, args: string[], signal?: AbortSignal) {
  const reply = await localCommand("/usr/bin/git", {
    args: [
      "-c",
      "core.hooksPath=/dev/null",
      "-c",
      "core.fsmonitor=false",
      "-C",
      path,
      ...args,
    ],
    clearEnv: true,
    env: {
      PATH: "/usr/bin:/bin",
      GIT_CONFIG_GLOBAL: "/dev/null",
      GIT_CONFIG_NOSYSTEM: "1",
    },
    stdin: "null",
    stdout: "piped",
    stderr: "null",
    signal,
  }).output();
  if (!reply.success || reply.stdout.length > 65536) {
    throw new Error("instance.transfer_path_unproven");
  }
  return new TextDecoder().decode(reply.stdout).trimEnd();
}
function owner(user: string) {
  assertLocalExecution();
  if (Deno.uid() === 0 || userInfo().username !== user) {
    throw new Error("instance.transfer_source_owner_invalid");
  }
}
/** Native filesystem evidence stays in this run, never in Gateway orchestration state. */
export async function inspectTransferPath(
  path: string,
  user: string,
  signal?: AbortSignal,
) {
  TransferPath.parse(path);
  owner(user);
  const stat = await Deno.lstat(path);
  if (
    !stat.isDirectory || stat.isSymlink || stat.uid !== Deno.uid() ||
    await Deno.realPath(path) !== path ||
    await git(path, ["rev-parse", "--show-toplevel"], signal) !== path
  ) throw new Error("instance.transfer_path_unproven");
  const entry = await Deno.lstat(`${path}/.git`);
  if (entry.isSymlink || (!entry.isDirectory && !entry.isFile)) {
    throw new Error("instance.transfer_path_unproven");
  }
  const common = await git(path, [
    "rev-parse",
    "--path-format=absolute",
    "--git-common-dir",
  ], signal);
  if (entry.isDirectory) {
    const inventory = await git(
      path,
      ["worktree", "list", "--porcelain"],
      signal,
    );
    if (
      inventory.split("\n").filter((line) => line.startsWith("worktree "))
        .length !== 1
    ) {
      throw new Error("instance.transfer_shared_checkout");
    }
  }
  return TransferPathProof.parse({
    path,
    user,
    device: stat.dev,
    inode: stat.ino,
    layout: entry.isFile ? "worktree" : "checkout",
    common,
  });
}
/** Destination readiness is checked on its own worker before the source is paused. */
export async function assertTransferDestination(
  path: string,
  root: string,
  user: string,
) {
  TransferPath.parse(path);
  TransferPath.parse(root);
  owner(user);
  if (!path.startsWith(root + "/")) {
    throw new Error("instance.transfer_destination_invalid");
  }
  let current = "";
  for (const part of path.split("/").slice(1)) {
    current += `/${part}`;
    try {
      const stat = await Deno.lstat(current);
      if (
        !stat.isDirectory || stat.isSymlink ||
        await Deno.realPath(current) !== current
      ) throw new Error("instance.transfer_destination_invalid");
      if (current === path) {
        throw new Error("instance.transfer_destination_exists");
      }
    } catch (error) {
      if (!(error instanceof Deno.errors.NotFound)) throw error;
    }
  }
  return { ready: true as const };
}
/** Refuse a replaced checkout; linked worktree removal preserves the common repository. */
export async function removeTransferPath(raw: unknown, signal?: AbortSignal) {
  const proof = TransferPathProof.parse(raw);
  owner(proof.user);
  try {
    await Deno.lstat(proof.path);
  } catch (error) {
    if (error instanceof Deno.errors.NotFound) {
      return { removed: true as const };
    }
    throw error;
  }
  const current = await inspectTransferPath(proof.path, proof.user, signal);
  if (JSON.stringify(current) !== JSON.stringify(proof)) {
    throw new Error("instance.transfer_path_unproven");
  }
  if (proof.layout === "worktree") {
    await git(proof.path, [
      "worktree",
      "remove",
      "--force",
      "--force",
      "--",
      proof.path,
    ], signal);
  } else {
    await localFilesystem().remove(proof.path, { recursive: true });
  }
  return { removed: true as const };
}
