import {
  assertEquals,
  assertRejects,
  assertThrows,
} from "jsr:@std/assert@1.0.19";
import { DestroyLocal } from "./destroy_contract.ts";
import {
  assertDestroyNode,
  finalizeDestroySources,
  prepareDestroySources,
  remainingWorktreeInventory,
  removeDestroyRuntime,
} from "./destroy_local.ts";
import type { DestroyInvoke } from "./destroy_node.ts";

import { fixture, inventory, member } from "./fixtures/destroy.ts";

Deno.test("destroy rejects a foreign action Node before local effects", () => {
  assertThrows(() => assertDestroyNode(fixture(), 209), Error, "Foreign Node");
  assertEquals(assertDestroyNode(fixture(), 9).node_id, 9);
});
Deno.test("destroy rejects traversal, duplicate members and foreign grouping roots", () => {
  const input = fixture();
  assertThrows(() =>
    DestroyLocal.parse({ ...input, members: [member(), member()] })
  );
  assertThrows(() =>
    DestroyLocal.parse({
      ...input,
      members: [{ ...member(), grouping_directory: "/srv/other" }],
    })
  );
  assertThrows(() =>
    DestroyLocal.parse({
      ...input,
      members: [{
        ...member(),
        source: { ...member().source, checkout: "/srv/apps/../other" },
      }],
    })
  );
});
Deno.test("present journal resumes without repeating preparation", async () => {
  const calls: string[] = [];
  const invoke: DestroyInvoke = (command, input) => {
    calls.push(command);
    assertEquals(input, {
      ...member().source,
      receipt: member().receipt,
      grouping_directory: member().grouping_directory,
    });
    return Promise.resolve({ state: "present" });
  };
  assertEquals(await prepareDestroySources(fixture(), invoke), {
    prepared: true,
  });
  assertEquals(calls, ["instance.read-source-removal-state"]);
});
Deno.test("missing journal invokes only the independently fenced prepare primitive", async () => {
  const calls: unknown[] = [];
  const invoke: DestroyInvoke = (command, input, user) => {
    calls.push({ command, input, user });
    return Promise.resolve(
      command === "instance.read-source-removal-state"
        ? { error_code: "instance.remove_refused" }
        : {},
    );
  };
  await prepareDestroySources(fixture(), invoke);
  assertEquals(calls.length, 2);
  assertEquals(calls[1], {
    command: "instance.prepare-source-removal",
    input: member().source,
    user: "orbit",
  });
});
Deno.test("schedule refusal stops before processes or source deletion", async () => {
  const calls: string[] = [];
  await assertRejects(
    () =>
      removeDestroyRuntime(fixture(), (command) => {
        calls.push(command);
        return Promise.resolve({ error_code: "schedule.remove_failed" });
      }),
    Error,
    "schedule.remove_failed",
  );
  assertEquals(calls, ["schedule.remove"]);
});
Deno.test("process refusal after schedules prevents runtime completion", async () => {
  const calls: string[] = [];
  await assertRejects(
    () =>
      removeDestroyRuntime(fixture(), (command) => {
        calls.push(command);
        return Promise.resolve(
          command === "process.remove"
            ? { error_code: "process.remove_failed" }
            : {},
        );
      }),
    Error,
    "process.remove_failed",
  );
  assertEquals(calls, ["schedule.remove", "process.remove"]);
});
Deno.test("every schedule stops before the first process", async () => {
  const input = fixture();
  input.runtime.schedules.push({
    ...input.runtime.schedules[0],
    id: "e29ff390-7c73-4a1d-8588-b8729a709c02",
  });
  const calls: string[] = [];
  await removeDestroyRuntime(input, (command, data) => {
    calls.push(`${command}:${data.id ?? data.process_id}`);
    return Promise.resolve({});
  });
  assertEquals(calls, [
    "schedule.remove:4377084c-a00e-4148-b35e-93617a91f6b6",
    "schedule.remove:e29ff390-7c73-4a1d-8588-b8729a709c02",
    "process.remove:1",
  ]);
});
Deno.test("runtime delegates exact admitted Process and Schedule contracts", async () => {
  const input = fixture();
  const calls: unknown[] = [];
  assertEquals(
    await removeDestroyRuntime(input, (command, data) => {
      calls.push({ command, data });
      return Promise.resolve({});
    }),
    { removed: true },
  );
  assertEquals(calls, [
    {
      command: "schedule.remove",
      data: input.runtime.schedules[0],
    },
    {
      command: "process.remove",
      data: input.runtime.processes[0],
    },
  ]);
});
Deno.test("worktree inventory removes only previously completed admitted members", () => {
  const original = inventory([
    "/srv/apps/proof/main",
    "/srv/apps/proof/work",
    "/srv/apps/proof/foreign",
  ]);
  assertEquals(
    remainingWorktreeInventory(original, new Set(["/srv/apps/proof/work"])),
    inventory(["/srv/apps/proof/main", "/srv/apps/proof/foreign"]),
  );
  assertThrows(() =>
    remainingWorktreeInventory(btoa("not-an-inventory"), new Set())
  );
});
Deno.test("worktrees finalize before common checkout and retain source fences", async () => {
  const members = [member(1), member(2, "worktree")];
  const input = { ...fixture(), members };
  const calls: Array<
    { command: string; input: Record<string, unknown>; user?: string }
  > = [];
  const invoke: DestroyInvoke = (command, data, user) => {
    calls.push({ command, input: data, user });
    return Promise.resolve(
      command === "instance.read-source-removal-state"
        ? { state: "present" }
        : { state: "finalized", receipt: "c".repeat(64) },
    );
  };
  assertEquals(await finalizeDestroySources(input, {}, invoke), {
    finalized: true,
    instance_ids: [1, 2],
  });
  const finalized = calls.filter((call) =>
    call.command === "instance.finalize-source-removal"
  );
  assertEquals(finalized.map((call) => call.input.checkout), [
    "/srv/apps/proof/work",
    "/srv/apps/proof/main",
  ]);
  assertEquals(
    finalized[1].input.worktrees,
    inventory(["/srv/apps/proof/main"]),
  );
  assertEquals(finalized[0].input.source_identity, "42:91");
  assertEquals(finalized[0].user, "orbit");
  assertEquals(finalized[0].input.git_read, {});
});
Deno.test("lost finalization reply recovers completed source without deleting again", async () => {
  const calls: string[] = [];
  await finalizeDestroySources(fixture(), {}, (command) => {
    calls.push(command);
    return Promise.resolve({ state: "completed" });
  });
  assertEquals(calls, ["instance.read-source-removal-state"]);
});
Deno.test("wrong finalization proof refuses the parent result", async () => {
  await assertRejects(
    () =>
      finalizeDestroySources(
        fixture(),
        {},
        (command) =>
          Promise.resolve(
            command === "instance.read-source-removal-state"
              ? { state: "present" }
              : { state: "finalized", receipt: "foreign" },
          ),
      ),
    Error,
    "instance.finalization_evidence_invalid",
  );
});
Deno.test("receipt cleanup needs the same native source receipt", async () => {
  await assertRejects(
    () =>
      finalizeDestroySources(
        fixture(),
        {},
        (command) =>
          Promise.resolve(
            command === "instance.read-source-removal-state"
              ? { state: "receipt_pending_cleanup" }
              : { receipt: "foreign" },
          ),
      ),
    Error,
    "instance.finalization_evidence_invalid",
  );
});
Deno.test("unrecognized source state never authorizes a destructive primitive", async () => {
  const calls: string[] = [];
  await assertRejects(
    () =>
      finalizeDestroySources(fixture(), {}, (command) => {
        calls.push(command);
        return Promise.resolve({ state: "unknown" });
      }),
    Error,
    "Invalid source removal state",
  );
  assertEquals(calls, ["instance.read-source-removal-state"]);
});

Deno.test("runtime refuses mismatched Process identity and a foreign Vite owner", () => {
  const input = fixture();
  for (
    const process of [
      { ...input.runtime.processes[0], process_id: 2 },
      {
        ...input.runtime.processes[0],
        vite: { path: "/etc/orbit/vite/app-instance-2.env", instance_id: 2 },
      },
      {
        ...input.runtime.processes[0],
        vite: {
          path: "/etc/orbit/vite/app-instance-1.env/foreign",
          instance_id: 1,
        },
      },
    ]
  ) {
    assertThrows(() =>
      DestroyLocal.parse({
        ...input,
        runtime: { ...input.runtime, processes: [process] },
      })
    );
  }
});

Deno.test("native cancellation between runtime records stops before the next effect", async () => {
  const controller = new AbortController();
  const calls: string[] = [];
  await assertRejects(() =>
    removeDestroyRuntime(fixture(), (command) => {
      calls.push(command);
      controller.abort();
      return Promise.resolve({});
    }, controller.signal)
  );
  assertEquals(calls, ["schedule.remove"]);
});

Deno.test("already cancelled source phases admit no Node call", async () => {
  let calls = 0;
  const invoke: DestroyInvoke = () => {
    calls++;
    return Promise.resolve({});
  };
  await assertRejects(() =>
    prepareDestroySources(fixture(), invoke, AbortSignal.abort())
  );
  await assertRejects(() =>
    finalizeDestroySources(fixture(), {}, invoke, AbortSignal.abort())
  );
  assertEquals(calls, 0);
});
