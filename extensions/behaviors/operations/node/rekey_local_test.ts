import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import type { NodeCall } from "../_lib/node-agent.ts";
import { DirectOperationCancelled } from "../_lib/direct.ts";
import { agentRekeyAction } from "./rekey_local.ts";

const input = {
  schema_version: 1,
  node_id: 13,
  configure_operation_id: 71,
  worker_user: "operator",
  agent_sha256: "a".repeat(64),
  configuration: {
    configuration:
      'gateway_url = "https://gateway.orbit"\n[swamp_worker]\nnode_id = 13\nuser = "operator"\n',
    certificate: "public-ca",
    unit: "admitted-unit",
    checkouts: ["/home/operator/apps/example"],
    binary_changed: true,
  },
};
const hash = "b".repeat(64);
const configured = { secret_hash: hash, restarted: true, unclosed: [] };

Deno.test("rekey uses only the admitted configuration and stores a private hash before success", async () => {
  const calls: NodeCall[] = [];
  const stored: unknown[] = [];
  const action = agentRekeyAction((call) => {
    calls.push(call);
    return Promise.resolve(configured);
  });
  const result = await action(input, (outputs) => {
    stored.push(outputs);
    return Promise.resolve();
  });
  assertEquals(calls, [{
    name: "agent.configure",
    input: {
      ...input.configuration,
      secret_hash: null,
      allow_secret_rotation: true,
      worker_credentials: null,
    },
    pin: input.agent_sha256,
    sudo: true,
    mutates: true,
    deadlineMs: 420000,
    maxOutputBytes: 65536,
  }]);
  assertEquals(stored, [{ secret_hash: hash }]);
  assertEquals(result, { rekeyed: true, restarted: true });
  assertEquals(JSON.stringify(result).includes(hash), false);
});

Deno.test("rekey cannot replay credentials or caller secret fields", async () => {
  for (
    const bad of [
      { ...input, worker_credentials: { enrollment_token: "private" } },
      {
        ...input,
        configuration: { ...input.configuration, worker_credentials: null },
      },
      {
        ...input,
        configuration: { ...input.configuration, secret_hash: hash },
      },
      { ...input, worker_user: "root" },
      { ...input, node_id: 209 },
      {
        ...input,
        configuration: { ...input.configuration, configuration: "no-worker" },
      },
      { ...input, agent_sha256: "invalid" },
      { ...input, configure_operation_id: 0 },
      {
        ...input,
        configuration: { ...input.configuration, checkouts: ["/home/../root"] },
      },
    ]
  ) {
    let calls = 0;
    assertEquals(
      await agentRekeyAction(() => {
        calls++;
        return Promise.resolve(configured);
      })(bad, () => Promise.resolve()),
      { rekeyed: false, error_code: "node.agent_rekey_unavailable" },
    );
    assertEquals(calls, 0);
  }
});

Deno.test("rekey refuses cancellation before the effect but stores a completed late result", async () => {
  const cancelled = new AbortController();
  cancelled.abort("private-abort-reason");
  let calls = 0;
  const before = agentRekeyAction(() => {
    calls++;
    return Promise.resolve(configured);
  });
  await assertRejects(
    () => before(input, () => Promise.resolve(), cancelled.signal),
    DirectOperationCancelled,
  );
  assertEquals(calls, 0);
  const late = new AbortController();
  let stored = false;
  const after = agentRekeyAction(() => {
    late.abort();
    return Promise.resolve(configured);
  });
  assertEquals(
    await after(input, () => {
      stored = true;
      return Promise.resolve();
    }, late.signal),
    { rekeyed: true, restarted: true },
  );
  assertEquals(stored, true);
});

Deno.test("rekey returns only a closed proven refusal and stores no hash", async () => {
  let stored = false;
  const result = await agentRekeyAction(() =>
    Promise.resolve({ error_code: "agent.version_mismatch" })
  )(input, () => {
    stored = true;
    return Promise.resolve();
  });
  assertEquals(result, {
    rekeyed: false,
    error_code: "agent.version_mismatch",
  });
  assertEquals(stored, false);
});

Deno.test("rekey leaves unknown, malformed, lost and unstored replies uncertain without private errors", async () => {
  for (
    const reply of [
      { ...configured, secret_hash: "malformed-private-hash" },
      { ...configured, restarted: false },
      { ...configured, credential: "private-unexpected-output" },
      { error_code: "private.unknown_refusal" },
      { error_code: "node.agent_rekey_unavailable" },
      { error_code: "agent.configure_failed" },
      { error_code: "swamp.node_worker_not_ready" },
      { error_code: "swamp.node_worker_memory_low" },
      null,
    ]
  ) {
    let stored = false;
    const error = await assertRejects(
      () =>
        agentRekeyAction(() => Promise.resolve(reply))(input, () => {
          stored = true;
          return Promise.resolve();
        }),
      Error,
      "Agent recovery has no proven private output storage",
    );
    assertEquals(stored, false);
    assertEquals(error.message.includes("private-hash"), false);
  }
  await assertRejects(
    () =>
      agentRekeyAction(() => Promise.reject(new Error(hash)))(
        input,
        () => Promise.resolve(),
      ),
    Error,
    "Agent recovery has no proven private output storage",
  );
  await assertRejects(
    () =>
      agentRekeyAction(() => Promise.resolve(configured))(
        input,
        () => Promise.reject(new Error(hash)),
      ),
    Error,
    "Agent recovery has no proven private output storage",
  );
});
