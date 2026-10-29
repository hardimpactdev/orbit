import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { ExecutorError } from "../../operation-runner/mod.ts";
import { sweepHost, SweepInput } from "./sweep.ts";
import type { FamilyEffects } from "./runtime.ts";
const input = (provider = "incus") =>
  SweepInput.parse({
    node_id: 4,
    provider,
    sweep_id: 27,
    deadline_at: 4_000_000_000,
  });
class Effects implements FamilyEffects {
  calls: { key: string; kind: string; input: Record<string, unknown> }[] = [];
  refuse: string | null = null;
  read() {
    return Promise.resolve({
      deletions: [{ kind: "instance", name: "tvm-100" }, {
        kind: "volume",
        name: "tvm-200-topology-seed",
      }],
    });
  }
  child(
    key: string,
    kind: string,
    _node: number,
    data: Record<string, unknown>,
  ) {
    this.calls.push({ key, kind, input: data });
    if (key === this.refuse) {
      return Promise.reject(new ExecutorError("task_vm.host_command_failed"));
    }
    return Promise.resolve({ ready: true });
  }
  wait() {
    return Promise.resolve();
  }
}
Deno.test("sweep deletes only Gateway-owned candidates and records one observation identity", async () => {
  const effects = new Effects();
  await sweepHost(input(), effects);
  assertEquals(effects.calls.map((call) => call.key), [
    "delete-instance-tvm-100",
    "delete-volume-tvm-200-topology-seed",
    "observe",
  ]);
  assertEquals(effects.calls.every((call) => call.input.sweep_id === 27), true);
});
Deno.test("one refused deletion does not prevent other candidates or missing observations", async () => {
  const effects = new Effects();
  effects.refuse = "delete-instance-tvm-100";
  await assertRejects(
    () => sweepHost(input(), effects),
    ExecutorError,
    "task_vm.host_command_failed",
  );
  assertEquals(effects.calls.at(-1)!.key, "observe");
  assertEquals(effects.calls.length, 3);
});
Deno.test("sweep lease loss stops before another effect", async () => {
  const effects = new Effects();
  effects.child = () =>
    Promise.reject(new ExecutorError("operation.lease_lost"));
  await assertRejects(
    () => sweepHost(input(), effects),
    ExecutorError,
    "operation.lease_lost",
  );
  assertEquals(effects.calls, []);
});
Deno.test("UpCloud sweep carries an owner-bound reference instead of a credential", async () => {
  const effects = new Effects();
  await sweepHost(input("upcloud"), effects);
  assertEquals(effects.calls[0].kind, "task_vm.sweep_delete_upcloud");
  assertEquals(effects.calls[0].input.token, { $secret: "upcloud.api", id: 4 });
});

Deno.test("UpCloud sweep hard-stops an owned orphan and waits for its deletion", async () => {
  const effects = new Effects();
  const deletion = {
    kind: "server",
    name: "tvm-100",
    uuid: "owned-server",
    label: "orbit-task-vm",
    value: "100",
  };
  let state: string | undefined = "started";
  let waits = 0;
  effects.read = () =>
    Promise.resolve({
      deletions: [deletion],
      server_states: state ? { "owned-server": state } : {},
    });
  effects.wait = () => {
    waits++;
    state =
      effects.calls.some((call) => call.kind === "task_vm.sweep_delete_upcloud")
        ? undefined
        : "stopped";
    return Promise.resolve();
  };
  await sweepHost(input("upcloud"), effects);
  assertEquals(effects.calls.map((call) => call.kind), [
    "task_vm.sweep_stop_upcloud",
    "task_vm.sweep_delete_upcloud",
    "task_vm.sweep_observe",
  ]);
  assertEquals(waits, 2);
});

Deno.test("clone sweep reuses its admitted orphan deletion after a lost reply and preserves other volumes", async () => {
  const effects = new Effects();
  const volumes = new Set([
    "tvm-5-topology-seed",
    "tvm-999-topology-seed",
    "orbit-topology-seed-cccccccccccc",
    "operator-data",
  ]);
  const calls: {
    key: string;
    kind: string;
    node: number;
    data: Record<string, unknown>;
  }[] = [];
  const receipts = new Map<string, { ready: boolean }>();
  let lost = false;
  effects.read = () =>
    Promise.resolve({
      deletions: [{ kind: "volume", name: "tvm-999-topology-seed" }],
    });
  effects.child = (key, kind, node, data) => {
    if (!receipts.has(key)) {
      calls.push({ key, kind, node, data });
      if (kind === "task_vm.sweep_delete") {
        volumes.delete(String((data.deletion as Record<string, unknown>).name));
      }
      receipts.set(key, { ready: true });
      if (!lost) {
        lost = true;
        return Promise.reject(new ExecutorError("operation.outcome_unknown"));
      }
    }
    return Promise.resolve(receipts.get(key)!);
  };

  await assertRejects(
    () => sweepHost(input(), effects),
    ExecutorError,
    "operation.outcome_unknown",
  );
  assertEquals(await sweepHost(input(), effects), { ready: true });
  assertEquals(calls, [
    {
      key: "delete-volume-tvm-999-topology-seed",
      kind: "task_vm.sweep_delete",
      node: 4,
      data: {
        node_id: 4,
        provider: "incus",
        sweep_id: 27,
        deletion: { kind: "volume", name: "tvm-999-topology-seed" },
      },
    },
    {
      key: "observe",
      kind: "task_vm.sweep_observe",
      node: 4,
      data: { node_id: 4, provider: "incus", sweep_id: 27 },
    },
  ]);
  assertEquals([...volumes], [
    "tvm-5-topology-seed",
    "orbit-topology-seed-cccccccccccc",
    "operator-data",
  ]);
});

Deno.test("a refused clone deletion remains failed while other cleanup and observation finish", async () => {
  const effects = new Effects();
  effects.refuse = "delete-volume-tvm-200-topology-seed";

  await assertRejects(
    () => sweepHost(input(), effects),
    ExecutorError,
    "task_vm.host_command_failed",
  );

  assertEquals(effects.calls.map((call) => call.key), [
    "delete-instance-tvm-100",
    "delete-volume-tvm-200-topology-seed",
    "observe",
  ]);
});

Deno.test("UpCloud orphan deletion resumes its accepted request and waits for absence before observation", async () => {
  const effects = new Effects();
  const deletion = {
    kind: "server",
    name: "tvm-100",
    uuid: "owned-server",
    label: "orbit-task-vm",
    value: "100",
  };
  let state: string | undefined = "started";
  let lost = false;
  const receipts = new Map<string, { ready: boolean }>();
  effects.read = () =>
    Promise.resolve({
      deletions: [deletion],
      server_states: state ? { "owned-server": state } : {},
    });
  effects.child = (key, kind, node, data) => {
    assertEquals(node, 4);
    if (!receipts.has(key)) {
      effects.calls.push({ key, kind, input: data });
      receipts.set(key, { ready: true });
    }
    if (kind === "task_vm.sweep_delete_upcloud" && !lost) {
      lost = true;
      state = "maintenance";
      return Promise.reject(new ExecutorError("operation.outcome_unknown"));
    }
    if (key === "observe") assertEquals(state, undefined);
    return Promise.resolve(receipts.get(key)!);
  };
  effects.wait = () => {
    state = lost ? undefined : "stopped";
    return Promise.resolve();
  };

  await assertRejects(
    () => sweepHost(input("upcloud"), effects),
    ExecutorError,
    "operation.outcome_unknown",
  );
  assertEquals(effects.calls.some((call) => call.key === "observe"), false);
  assertEquals(await sweepHost(input("upcloud"), effects), { ready: true });
  assertEquals(effects.calls.map((call) => call.kind), [
    "task_vm.sweep_stop_upcloud",
    "task_vm.sweep_delete_upcloud",
    "task_vm.sweep_observe",
  ]);
});
