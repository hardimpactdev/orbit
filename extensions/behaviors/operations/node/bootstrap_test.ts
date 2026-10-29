import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { Fixture } from "./test-fixture.ts";

function fixture(probe = false) {
  const f = new Fixture();
  f.operation.kind = probe ? "node.bootstrap_probe" : "node.bootstrap_install";
  f.operation.input = {
    bootstrap_host: "192.0.2.8",
    bootstrap_port: 2222,
    bootstrap_fingerprint: `SHA256:${"A".repeat(43)}`,
    bootstrap_user: "root",
    bootstrap_identity: { $secret: "node.bootstrap_ssh_identity", id: 2 },
    ...(probe ? {} : { agent_pin: "a".repeat(64) }),
    command_input: probe ? { expected_architecture: null } : [],
  };
  f.replies.set(
    probe ? "bootstrap-probe" : "agent-install",
    probe
      ? {
        probed: true,
        architecture: "x86_64",
        host_key_fingerprint: `SHA256:${"A".repeat(43)}`,
      }
      : { installed: true, changed: false, architecture: "x86_64" },
  );
  return f;
}

Deno.test("bootstrap forwards the admitted pinned connection and credential reference only", async () => {
  for (const probe of [true, false]) {
    const f = fixture(probe);
    assertEquals((await f.execute()).status, "succeeded");
    const runs = f.calls.filter((c) => c.path.endsWith("/run"));
    assertEquals(runs.length, 1);
    assertEquals(runs[0].body.transport, "bootstrap-ssh");
    assertEquals(
      runs[0].body.name,
      probe ? "node.probe-bootstrap" : "agent.install",
    );
    assertEquals(runs[0].body.input, {
      ...f.operation.input,
      command_input: probe ? { expected_architecture: null } : {},
    });
  }
});
Deno.test("bootstrap recovers the original installer after a lost reply and completion", async () => {
  const f = fixture();
  f.lostRun = "agent-install";
  await assertRejects(() => f.execute(), Error, "Lost run reply");
  f.lostCompletion = true;
  await assertRejects(() => f.execute(), Error, "Lost completion reply");
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.runs(), ["agent.install"]);
  assertEquals(
    f.calls.some((c) => c.path.endsWith("/receipts/agent-install")),
    true,
  );
});
Deno.test("bootstrap preserves a typed refusal and rejects absent success proof", async () => {
  for (
    const reply of [{ error_code: "node.architecture_unsupported" }, {
      installed: false,
    }]
  ) {
    const f = fixture();
    f.replies.set("agent-install", reply);
    assertEquals((await f.execute()).status, "failed");
    assertEquals(
      f.completion()?.error_code,
      "error_code" in reply
        ? reply.error_code
        : "node.bootstrap_verification_failed",
    );
  }
});
Deno.test("bootstrap rejects raw credentials, foreign owners and extra commands before execution", async () => {
  for (const invalid of ["raw", "foreign", "extra"]) {
    const f = fixture();
    if (invalid === "raw") {
      f.operation.input.bootstrap_identity = "/private/key";
    }
    if (invalid === "foreign") {
      f.operation.input.bootstrap_identity = {
        $secret: "node.bootstrap_ssh_identity",
        id: 3,
      };
    }
    if (invalid === "extra") {
      f.operation.input.command_input = { script: "unowned" };
    }
    await assertRejects(() => f.execute());
    assertEquals(f.runs(), []);
  }
});
Deno.test("bootstrap cancellation before dispatch performs no first-contact effect", async () => {
  const f = fixture();
  f.operation.cancel_requested = true;
  assertEquals((await f.execute()).status, "cancelled");
  assertEquals(f.runs(), []);
});
