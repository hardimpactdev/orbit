import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import { DirectOperationCancelled } from "../_lib/direct.ts";
import { Input, type Pi, turn, type Workspace } from "./turn.ts";

const head = "a".repeat(40);
const tree = "b".repeat(40);
function fixture(role = "implementer") {
  const input = Input.parse({
    new_session: true,
    group_id: 10,
    instance_id: 12,
    node_id: 30,
    subtask: 11,
    thread_id: 14,
    checkout_path: "/srv/task",
    session_id: "session-14",
    role,
    prompt: "Fix the requested task",
    model: "orbit-task-vm/gpt-6.1-sol",
    effort: "high",
    pi_host: "10.44.0.30",
    pi_port: 3774,
    candidate: role === "reviewer" ? head : null,
    deliverables: [],
    analysis_evidence: null,
  });
  const sends: string[] = [];
  const resets: string[] = [];
  let receipt: unknown = {
    thread: 14,
    outcome: role === "reviewer" ? "approved" : "ready_for_review",
    summary: "Implemented the requested change",
  };
  const terminal = {
    state: "done",
    turnId: "operation-15",
    usage: {
      input: 10,
      output: 5,
      cacheRead: 8,
      cacheWrite: 2,
      total: 25,
      calls: 1,
      peakContext: 20,
    },
  };
  const pi: Pi = {
    create: () => Promise.resolve(),
    send: (_session, key) => {
      sends.push(key);
      return Promise.resolve();
    },
    states: async function* () {
      yield { state: "working", turnId: "older" };
      yield terminal;
    },
    interrupt: () => Promise.resolve(),
  };
  const workspace: Workspace = {
    difference: () =>
      Promise.resolve({
        files: [],
        diff: "",
        files_complete: true,
        diff_available: true,
        summary: { files: 0, insertions: 0, deletions: 0 },
      }),
    prepare: () => Promise.resolve({ head, tree }),
    snapshot: () => Promise.resolve({ head, tree }),
    receipt: () => Promise.resolve(receipt),
    reset: (sha) => {
      resets.push(sha);
      return Promise.resolve();
    },
  };
  return {
    input,
    pi,
    workspace,
    sends,
    resets,
    terminal,
    setReceipt: (value: unknown) => {
      receipt = value;
    },
  };
}

Deno.test("Pi turn returns the matching completion and board token metrics", async () => {
  const f = fixture();
  const result = await turn(
    f.input,
    "operation-15",
    { head, tree },
    f.pi,
    f.workspace,
  );
  assertEquals(result.state, "done");
  assertEquals(
    (result.receipt as Record<string, unknown>).outcome,
    "ready_for_review",
  );
  assertEquals(result.metrics, {
    tokens: 25,
    input_tokens: 12,
    cached_input_tokens: 8,
    output_tokens: 5,
    model_calls: 1,
    peak_context_tokens: 20,
  });
  assertEquals(f.sends, ["operation-15"]);
});
Deno.test("native resume uses the same Pi session and Durable send key", async () => {
  const f = fixture();
  await turn(f.input, "operation-15", { head, tree }, f.pi, f.workspace);
  await turn(f.input, "operation-15", { head, tree }, f.pi, f.workspace);
  assertEquals(f.sends, ["operation-15", "operation-15"]);
});
Deno.test("reviewer restores the checked candidate after collecting its receipt", async () => {
  const f = fixture("reviewer");
  const result = await turn(
    f.input,
    "operation-15",
    { head, tree },
    f.pi,
    f.workspace,
  );
  assertEquals(f.resets, [head]);
  assertEquals(result.after_reset, { head, tree });
});
Deno.test("a moved review candidate starts no Pi turn", async () => {
  const f = fixture("reviewer");
  assertEquals(
    await turn(
      f.input,
      "operation-15",
      { head: "c".repeat(40), tree },
      f.pi,
      f.workspace,
    ),
    { error_code: "tasks.head_moved" },
  );
  assertEquals(f.sends, []);
});
Deno.test("receipts from another thread or role never authorize task progression", async () => {
  const f = fixture();
  for (
    const receipt of [
      { thread: 99, outcome: "ready_for_review", summary: "Old" },
      { thread: 14, outcome: "approved", summary: "Wrong role" },
      { thread: 14, outcome: "blocked", summary: "Blocked" },
    ]
  ) {
    f.setReceipt(receipt);
    assertEquals(
      (await turn(f.input, "operation-15", { head, tree }, f.pi, f.workspace))
        .receipt,
      null,
    );
  }
});
Deno.test("disconnect without a terminal Pi observation remains uncertain", async () => {
  const f = fixture();
  f.pi.states = async function* () {
    yield { state: "working", turnId: "operation-15" };
  };
  await assertRejects(
    () => turn(f.input, "operation-15", { head, tree }, f.pi, f.workspace),
    Error,
    "no terminal observation",
  );
});
Deno.test("cancellation interrupts Pi and waits for its terminal observation", async () => {
  const f = fixture();
  const signal = new AbortController();
  let interrupts = 0;
  f.pi.interrupt = () => {
    interrupts++;
    return Promise.resolve();
  };
  f.pi.states = async function* () {
    signal.abort();
    yield { ...f.terminal, state: "failed" };
  };
  await assertRejects(
    () =>
      turn(
        f.input,
        "operation-15",
        { head, tree },
        f.pi,
        f.workspace,
        signal.signal,
      ),
    DirectOperationCancelled,
  );
  assertEquals(interrupts, 1);
});
Deno.test("stale analysis does not start a Pi turn", async () => {
  const f = fixture();
  f.input.analysis_evidence = {
    input: {},
    result: { provenance: { instance_id: 999, node_id: 30, head, tree } },
  };
  assertEquals(
    await turn(f.input, "operation-15", { head, tree }, f.pi, f.workspace),
    { error_code: "tasks.analysis_stale" },
  );
  assertEquals(f.sends, []);
});

Deno.test("a continued thread retains its existing Pi session settings", async () => {
  const f = fixture();
  f.input.new_session = false;
  f.pi.create = () => {
    throw new Error("A continued session must not be recreated");
  };
  await turn(f.input, "operation-15", { head, tree }, f.pi, f.workspace);
  assertEquals(f.sends, ["operation-15"]);
});
