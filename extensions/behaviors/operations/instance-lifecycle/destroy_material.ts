import { z } from "npm:zod@4.4.3";
import type { GatewayOperation } from "../_lib/direct.ts";
import { DestroyCode, type DestroyLocalInput } from "./destroy_contract.ts";
import {
  DestroyRefusal,
  finalizeDestroySources,
  remainingWorktreeInventory,
} from "./destroy_local.ts";
import { type DestroyInvoke, destroyNode } from "./destroy_node.ts";
import {
  checkDestroyPublication,
  destroySourcePaths,
  verifyDestroySource,
} from "./destroy_system.ts";

type PrivateGateway = Pick<GatewayOperation, "withPrivateMaterial">;

/** Keep private delivery and all consumers inside one awaited, single-use callback. */
async function useGit(
  gateway: PrivateGateway,
  use: (git: Record<string, unknown>) => Promise<void>,
) {
  let accepting = true, attempted = false, complete = false;
  let code = "instance.removal_refused";
  try {
    await gateway.withPrivateMaterial(async (material) => {
      if (!accepting || attempted) return;
      attempted = true;
      try {
        await use(z.record(z.string(), z.unknown()).parse(material.git_read));
        complete = true;
      } catch (error) {
        if (
          error instanceof DestroyRefusal &&
          DestroyCode.safeParse(error.code).success
        ) code = error.code;
      }
    });
  } catch {
    throw new DestroyRefusal(code);
  } finally {
    accepting = false;
  }
  if (!complete) throw new DestroyRefusal(code);
}

/** Refuse unpublished source before preparing runtime, teardown or database effects. */
export async function assertCapturedDestroyPublication(
  rawInput: unknown,
  rawCapture: unknown,
  gateway: PrivateGateway,
  check = checkDestroyPublication,
  signal?: AbortSignal,
) {
  const input = z.object({
    force: z.boolean(),
    members: z.array(
      z.object({
        instance_id: z.number().int().positive(),
        failed_creation: z.boolean(),
      }),
    ),
  }).parse(rawInput);
  if (input.force) return;
  const capture = z.object({
    members: z.array(z.object({
      instance_id: z.number().int().positive(),
      origin: z.string(),
      source_commit: z.string(),
    })),
  }).parse(rawCapture);
  const pending = input.members.filter((member) => !member.failed_creation);
  if (!pending.length) return;
  await useGit(gateway, async (git) => {
    for (const member of pending) {
      const facts = capture.members.find((row) =>
        row.instance_id === member.instance_id
      );
      if (
        !facts || !await check(facts.origin, facts.source_commit, git, signal)
      ) {
        throw new DestroyRefusal("instance.unsafe_content");
      }
    }
  });
}

/** Teardown may edit source. Check it again before the first database effect. */
export async function verifyDestroySourcesWithMaterial(
  local: DestroyLocalInput,
  gateway: PrivateGateway,
  verify = verifyDestroySource,
  signal?: AbortSignal,
  source: DestroyInvoke = destroyNode,
) {
  const removed = new Set<string>(), moved = new Map<string, string>();
  for (const member of local.members) {
    signal?.throwIfAborted();
    const state = await source(
      "instance.read-source-removal-state",
      {
        ...member.source,
        receipt: member.receipt,
        grouping_directory: member.grouping_directory,
      },
      member.source.user,
      signal,
    );
    if (typeof state.error_code === "string") {
      throw new DestroyRefusal(state.error_code);
    }
    const phase = z.enum([
      "present",
      "completed",
      "quarantined",
      "receipt_pending_cleanup",
    ]).parse(state.state);
    if (phase === "receipt_pending_cleanup") {
      const cleaned = await source(
        "instance.clean-removal-receipt",
        {
          ...member.source,
          receipt: member.receipt,
          grouping_directory: member.grouping_directory,
        },
        member.source.user,
        signal,
      );
      if (
        cleaned.receipt !== member.receipt ||
        typeof cleaned.error_code === "string"
      ) throw new DestroyRefusal("instance.finalization_evidence_invalid");
    }
    if (phase === "completed" || phase === "receipt_pending_cleanup") {
      removed.add(member.source.checkout);
    }
    if (phase === "quarantined") {
      moved.set(
        member.source.checkout,
        destroySourcePaths(member.source).quarantine,
      );
    }
  }
  const pending = local.members.filter((member) =>
    !removed.has(member.source.checkout)
  );
  if (!pending.length) return;
  await useGit(gateway, async (git) => {
    for (const member of pending) {
      const { source, instance_id: _, ...rest } = member;
      try {
        await verify({
          ...source,
          ...rest,
          worktrees: remainingWorktreeInventory(
            member.worktrees,
            removed,
            moved,
          ),
          git_read: git,
        }, signal);
      } catch {
        throw new DestroyRefusal("instance.unsafe_content");
      }
    }
  });
}

/** Finalize inside one awaited private delivery; delayed or repeated callbacks cannot act. */
export async function finalizeDestroySourcesWithMaterial(
  local: DestroyLocalInput,
  gateway: Pick<GatewayOperation, "withPrivateMaterial">,
  source?: DestroyInvoke,
  signal?: AbortSignal,
): Promise<Record<string, unknown>> {
  let result: Record<string, unknown> | undefined;
  let accepting = true, attempted = false;
  let code = "instance.finalization_evidence_invalid";
  try {
    await gateway.withPrivateMaterial(async (material) => {
      if (!accepting || attempted) return;
      attempted = true;
      try {
        signal?.throwIfAborted();
        const git = z.record(z.string(), z.unknown()).parse(material.git_read);
        result = await finalizeDestroySources(local, git, source, signal);
      } catch (error) {
        if (
          error instanceof DestroyRefusal &&
          DestroyCode.safeParse(error.code).success
        ) code = error.code;
      }
    });
  } catch {
    throw new DestroyRefusal(code);
  } finally {
    accepting = false;
  }
  if (result === undefined) throw new DestroyRefusal(code);
  return result;
}
