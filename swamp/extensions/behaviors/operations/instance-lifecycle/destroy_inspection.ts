import { z } from "npm:zod@4.4.3";
import type { DestroyReadCall } from "./destroy_system.ts";
import { destroySystemRead } from "./destroy_system.ts";
import { DestroyPath } from "./destroy_contract.ts";
import { DestroyRefusal } from "./destroy_local.ts";
import { repositoryIdentity } from "./source_identity.ts";

const Account = z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/).refine((v) =>
  v !== "root"
);
const Member = z.object({
  instance_id: z.number().int().positive(),
  checkout_path: DestroyPath,
  source_root: DestroyPath,
  source_layout: z.enum(["checkout", "worktree"]),
  repository_identity: z.string().min(1),
  branch: z.string().nullable(),
  starting_commit: z.string().nullable(),
  user: Account,
  source_prepare_id: z.string().nullable(),
  registration_request_id: z.string().nullable(),
  stored_status: z.string(),
  failed_creation: z.boolean(),
});
const Input = z.object({
  instance_id: z.number().int().positive(),
  source_pending: z.literal(true),
  force: z.boolean(),
  repository: z.string().min(1),
  protected_source_roots: z.array(
    z.string().max(4096).regex(/^\/(?:[^/]+\/)*[^/]+$/),
  ),
  members: z.array(Member).min(1).max(1024),
  direct_destroy: z.object({
    node_user: Account,
  }),
});
function refused(): never {
  throw new DestroyRefusal("instance.source_identity_invalid");
}
function decode(value: unknown): string {
  try {
    const encoded = z.string().max(262144).parse(value);
    const bytes = Uint8Array.from(atob(encoded), (c) => c.charCodeAt(0));
    if (
      btoa(Array.from(bytes, (b) => String.fromCharCode(b)).join("")) !==
        encoded
    ) refused();
    return new TextDecoder("utf-8", { fatal: true }).decode(bytes);
  } catch {
    refused();
  }
}
function parent(path: string) {
  return path.slice(0, path.lastIndexOf("/"));
}
function overlap(a: string, b: string) {
  return a === b || a.startsWith(b + "/") || b.startsWith(a + "/");
}
function worktreePaths(inventory: string): string[] {
  if (!inventory.endsWith("\0\0")) refused();
  const paths = [];
  for (const entry of inventory.slice(0, -2).split("\0\0")) {
    const fields = entry.split("\0");
    if (!fields[0].startsWith("worktree ")) refused();
    const path = DestroyPath.parse(fields[0].slice(9));
    if (
      !fields.some((field) =>
        field === "prunable" || field.startsWith("prunable ")
      )
    ) paths.push(path);
  }
  return [...new Set(paths)].sort();
}
async function digest(value: unknown): Promise<string> {
  const bytes = new Uint8Array(
    await crypto.subtle.digest(
      "SHA-256",
      new TextEncoder().encode(JSON.stringify(value)),
    ),
  );
  return Array.from(bytes, (byte) => byte.toString(16).padStart(2, "0")).join(
    "",
  );
}

/** The first local native step captures source facts. No Gateway SSH or guessed account group. */
export async function inspectDestroySources(
  raw: unknown,
  invoke: (
    call: DestroyReadCall,
    signal?: AbortSignal,
  ) => Promise<Record<string, unknown>> = destroySystemRead,
  signal?: AbortSignal,
) {
  const input = Input.parse(raw);
  if (
    !input.members.some((member) => member.instance_id === input.instance_id) ||
    new Set(input.members.map((member) => member.instance_id)).size !==
      input.members.length ||
    new Set(input.members.map((member) => member.checkout_path)).size !==
      input.members.length
  ) refused();
  const admittedPaths = new Set(
    input.members.map((member) => member.checkout_path),
  );
  const captured = [];
  for (const member of input.members) {
    if (
      member.user !== input.direct_destroy.node_user
    ) refused();
    if (
      !member.checkout_path.startsWith(member.source_root + "/") ||
      parent(parent(member.checkout_path)) !== member.source_root
    ) refused();
    const call = async (name: string, fields: Record<string, unknown>) => {
      signal?.throwIfAborted();
      const result = await invoke({
        name,
        input: fields,
        sudo: false,
        mutates: false,
        deadlineMs: name === "account.lookup" ? 10000 : 300000,
        maxOutputBytes: 524288,
      }, signal);
      if (typeof result.error_code === "string") {
        throw new DestroyRefusal(result.error_code);
      }
      return result;
    };
    const account = z.object({
      user: z.literal(member.user),
      group: Account,
      home: DestroyPath,
    }).parse(await call("account.lookup", { user: member.user }));
    if (
      account.home === member.source_root ||
      account.home.startsWith(member.source_root + "/") ||
      input.protected_source_roots.some((root) =>
        overlap(root, member.source_root)
      ) ||
      member.source_root.startsWith(account.home + "/.")
    ) {
      throw new DestroyRefusal("instance.checkout_path_unsafe");
    }
    const unresolved = member.failed_creation &&
      member.starting_commit === null;
    const reply = await call("instance.inspect-source", {
      checkout: member.checkout_path,
      root: member.source_root,
      user: account.user,
      group: account.group,
      layout: member.source_layout,
      branch: member.branch ?? "",
      inspect_content: !input.force && !member.failed_creation,
      unresolved,
      allow_absent: input.force || member.failed_creation,
      origin: input.repository,
      prepare_id: member.source_prepare_id ?? "",
    });
    const [top, common, origin, branch, commit, dirty, identity, inventory] = [
      "top",
      "common",
      "origin",
      "branch",
      "commit",
      "dirty",
      "source_identity",
      "worktrees",
    ].map((field) => decode(reply[field]));
    if (
      top !== member.checkout_path || !DestroyPath.safeParse(common).success ||
      (member.source_layout === "checkout" && common !== top + "/.git") ||
      (member.source_layout === "worktree" && identity !== "absent" &&
        common === top + "/.git") ||
      !/^(?:[a-f0-9]{40}|[a-f0-9]{64}|)$/.test(commit) ||
      !["", "0", "1"].includes(dirty) ||
      (!/^\d+:\d+$/.test(identity) &&
        !(identity === "absent" && (input.force || member.failed_creation)))
    ) refused();
    try {
      if (repositoryIdentity(origin) !== member.repository_identity) refused();
    } catch {
      refused();
    }
    if (!unresolved && (branch || null) !== member.branch) {
      throw new DestroyRefusal("instance.source_branch_mismatch");
    }
    if (
      !input.force && !member.failed_creation &&
      member.starting_commit !== null && dirty !== "0"
    ) throw new DestroyRefusal("instance.unsafe_content");
    if (
      member.failed_creation && member.stored_status === "reserved" &&
      identity !== "absent" &&
      (member.source_prepare_id === null ||
        member.registration_request_id !== null)
    ) throw new DestroyRefusal("instance.remove_refused");
    const paths = worktreePaths(inventory);
    if (!paths.includes(top)) refused();
    // A checkout may remove its linked set only when every path belongs to this admission.
    if (
      member.source_layout === "checkout" &&
      paths.some((path) => !admittedPaths.has(path))
    ) throw new DestroyRefusal("instance.remove_refused");
    const sourceDigest = await digest({
      instance_id: member.instance_id,
      layout: member.source_layout,
      repository_identity: member.repository_identity,
      checkout_path: top,
      root: member.source_root,
      branch: member.branch,
      starting_commit: commit,
      common_repository_path: parent(common),
      source_identity: identity,
      linked_worktree_paths: paths,
    });
    captured.push({
      instance_id: member.instance_id,
      user: account.user,
      group: account.group,
      source_root: member.source_root,
      source_digest: sourceDigest,
      source_commit: commit,
      common_repository_path: parent(common),
      source_identity: identity,
      linked_worktree_paths: paths,
      worktree_inventory: z.string().parse(reply.worktrees),
      origin,
    });
  }
  return { members: captured, inventory_digest: await digest(captured) };
}
