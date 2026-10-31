import {
  destroyRuntimeSystem,
  type RuntimeInvoke,
} from "./destroy_runtime_system.ts";
import {
  DestroyLocal,
  type DestroyLocalInput,
  DestroyMember,
} from "./destroy_contract.ts";
import { type DestroyInvoke, destroyNode } from "./destroy_node.ts";
import { z } from "npm:zod@4.4.3";

export class DestroyRefusal extends Error {
  constructor(readonly code: string) {
    super(code);
  }
}
function requireSuccess(result: Record<string, unknown>) {
  if (typeof result.error_code === "string") {
    throw new DestroyRefusal(result.error_code);
  }
  return result;
}
function stateInput(member: z.infer<typeof DestroyMember>) {
  const { source } = member;
  return {
    ...source,
    receipt: member.receipt,
    grouping_directory: member.grouping_directory,
  };
}

/** Read/reconcile only the source journal owned by this immutable removal. */
export async function prepareDestroySources(
  raw: unknown,
  invoke: DestroyInvoke = destroyNode,
  signal?: AbortSignal,
) {
  const input = DestroyLocal.parse(raw);
  for (const member of input.members) {
    signal?.throwIfAborted();
    const state = await invoke(
      "instance.read-source-removal-state",
      stateInput(member),
      member.source.user,
      signal,
    );
    if (typeof state.error_code !== "string") {
      if (
        ![
          "present",
          "quarantined",
          "receipt_pending_cleanup",
          "completed",
        ].includes(String(state.state))
      ) {
        throw new Error("Invalid source removal state");
      }
      if (state.state === "receipt_pending_cleanup") {
        const cleaned = requireSuccess(
          await invoke(
            "instance.clean-removal-receipt",
            stateInput(member),
            member.source.user,
            signal,
          ),
        );
        if (cleaned.receipt !== member.receipt) {
          throw new DestroyRefusal("instance.finalization_evidence_invalid");
        }
      }
      continue;
    }
    // Prepare independently checks inode/layout/journal ownership. A refused read
    // cannot authorize a different source or overwrite another journal.
    requireSuccess(
      await invoke(
        "instance.prepare-source-removal",
        member.source,
        member.source.user,
        signal,
      ),
    );
  }
  return { prepared: true };
}

/** Stop schedules before processes; rows remain until complete parent projection. */
export async function removeDestroyRuntime(
  raw: unknown,
  invoke: RuntimeInvoke = destroyRuntimeSystem,
  signal?: AbortSignal,
) {
  const input = DestroyLocal.parse(raw);
  for (const schedule of input.runtime.schedules) {
    signal?.throwIfAborted();
    requireSuccess(
      await invoke("schedule.remove", schedule, signal),
    );
  }
  for (const process of input.runtime.processes) {
    signal?.throwIfAborted();
    requireSuccess(
      await invoke("process.remove", process, signal),
    );
  }
  return { removed: true };
}

/** A preceding member may remove only its own entry from the accepted inventory. */
export function remainingWorktreeInventory(
  encoded: string,
  removed: Set<string>,
  moved = new Map<string, string>(),
): string {
  let bytes: string;
  try {
    bytes = new TextDecoder("utf-8", { fatal: true }).decode(
      Uint8Array.from(atob(encoded), (char) => char.charCodeAt(0)),
    );
  } catch {
    throw new Error("Invalid admitted worktree inventory");
  }
  if (!bytes.endsWith("\0\0")) {
    throw new Error("Invalid admitted worktree inventory");
  }
  const entries = bytes.slice(0, -2).split("\0\0");
  const kept = entries.filter((entry) => {
    const first = entry.split("\0", 1)[0];
    if (!first.startsWith("worktree ")) {
      throw new Error("Invalid admitted worktree inventory");
    }
    return !removed.has(first.slice("worktree ".length));
  });
  const current = kept.map((entry) => {
    const fields = entry.split("\0");
    const path = fields[0].slice("worktree ".length);
    if (moved.has(path)) fields[0] = "worktree " + moved.get(path);
    return fields.join("\0");
  });
  return btoa(
    Array.from(
      new TextEncoder().encode(current.join("\0\0") + "\0\0"),
      (byte) => String.fromCharCode(byte),
    ).join(""),
  );
}

/** Caller obtains Git material only inside GatewayOperation.withPrivateMaterial. */
export async function finalizeDestroySources(
  raw: unknown,
  gitRead: Record<string, unknown>,
  invoke: DestroyInvoke = destroyNode,
  signal?: AbortSignal,
) {
  const input = DestroyLocal.parse(raw);
  const members = [...input.members].sort((a, b) =>
    Number(a.source.layout === "checkout") -
    Number(b.source.layout === "checkout")
  );
  const removed = new Set<string>();
  for (const member of members) {
    signal?.throwIfAborted();
    const state = requireSuccess(
      await invoke(
        "instance.read-source-removal-state",
        stateInput(member),
        member.source.user,
        signal,
      ),
    );
    if (state.state === "completed") {
      removed.add(member.source.checkout);
      continue;
    }
    if (state.state === "receipt_pending_cleanup") {
      const cleaned = requireSuccess(
        await invoke(
          "instance.clean-removal-receipt",
          {
            ...member.source,
            receipt: member.receipt,
            grouping_directory: member.grouping_directory,
          },
          member.source.user,
          signal,
        ),
      );
      if (cleaned.receipt !== member.receipt) {
        throw new DestroyRefusal("instance.finalization_evidence_invalid");
      }
    } else {
      if (state.state !== "present" && state.state !== "quarantined") {
        throw new Error("Invalid source removal state");
      }
      const { source, instance_id: _, ...finalize } = member;
      const result = requireSuccess(
        await invoke(
          "instance.finalize-source-removal",
          {
            ...source,
            ...finalize,
            worktrees: remainingWorktreeInventory(member.worktrees, removed),
            git_read: gitRead,
          },
          source.user,
          signal,
        ),
      );
      if (result.state !== "finalized" || result.receipt !== member.receipt) {
        throw new DestroyRefusal(
          result.state === "cross_device"
            ? "instance.removal_incomplete"
            : "instance.finalization_evidence_invalid",
        );
      }
    }
    removed.add(member.source.checkout);
  }
  return {
    finalized: true,
    instance_ids: members.map((member) => member.instance_id).sort((a, b) =>
      a - b
    ),
  };
}

/** The action Node must be the admitted source/runtime Node, including canaries. */
export function assertDestroyNode(
  raw: unknown,
  nodeId: number,
): DestroyLocalInput {
  const input = DestroyLocal.parse(raw);
  if (input.node_id !== nodeId) throw new Error("Foreign Node removal intent");
  return input;
}
