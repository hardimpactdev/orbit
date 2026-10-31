import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { convergeCli } from "./cli_converge_local.ts";
import type { NodeCall } from "../_lib/node-agent.ts";

const input = {
  user: "orbit",
  release: {
    version: "0.12.0",
    url:
      "https://github.com/hardimpactdev/orbit/releases/download/cli-v0.12.0/orbit-linux-x64",
    sha256: "a".repeat(64),
  },
  profile: "admitted-profile",
  skip_reason: null,
  plan_error: null,
  agent_sha256: "b".repeat(64),
};

Deno.test("local CLI action invokes only the fixed admitted install and returns a bounded summary", async () => {
  const calls: NodeCall[] = [];
  const order: string[] = [];
  const result = await convergeCli(input, {
    beforeEffect: () => {
      order.push("cancel-check");
      return Promise.resolve();
    },
    invoke: (call) => {
      order.push("install");
      calls.push(call);
      return Promise.resolve({
        installed: input.release.version,
        configured: true,
        diagnostic: "omit",
      });
    },
  });
  assertEquals(calls, [{
    name: "cli.install",
    input: { user: input.user, release: input.release, profile: input.profile },
    pin: input.agent_sha256,
    sudo: true,
    mutates: true,
    deadlineMs: 720000,
  }]);
  assertEquals(order, ["cancel-check", "install"]);
  assertEquals(result, {
    completed: true,
    outcome: "cli_installed",
    reason: null,
    version: "0.12.0",
    configured: true,
  });
});

Deno.test("local CLI action preserves present binaries without a fabricated installation", async () => {
  assertEquals(
    await convergeCli({ ...input, release: null }, {
      invoke: () => Promise.resolve({ installed: null, configured: false }),
    }),
    {
      completed: true,
      outcome: "cli_present",
      reason: null,
      version: null,
      configured: false,
    },
  );
});

Deno.test("local CLI selected skips and planning failures invoke no Node action", async () => {
  let calls = 0;
  const invoke = () => {
    calls++;
    throw new Error("Unexpected action");
  };
  assertEquals(
    await convergeCli({ ...input, skip_reason: "release_missing" }, { invoke }),
    {
      completed: true,
      outcome: "skipped",
      reason: "release_missing",
      version: null,
      configured: false,
    },
  );
  assertEquals(
    await convergeCli({ ...input, plan_error: "fleet.cli_plan_failed" }, {
      invoke,
    }),
    {
      error_code: "fleet.cli_plan_failed",
    },
  );
  assertEquals(calls, 0);
});

Deno.test("local CLI maps known refusals and retains foreign ownership without another effect", async () => {
  for (
    const [raw, expected] of [
      ["cli.foreign_binary", "cli.foreign_binary"],
      ["cli.update_busy", "node.update_busy"],
      ["cli.release_needed", "cli.release_unavailable"],
      ["cli.download_failed", "cli.download_failed"],
      ["cli.checksum_mismatch", "cli.checksum_mismatch"],
      ["cli.candidate_invalid", "cli.candidate_invalid"],
      ["cli.install_failed", "cli.install_failed"],
      ["agent.version_mismatch", "agent.version_mismatch"],
    ] as const
  ) {
    let calls = 0;
    assertEquals(
      await convergeCli(input, {
        invoke: () => {
          calls++;
          return Promise.resolve({ error_code: raw, diagnostic: "omit" });
        },
      }),
      { error_code: expected },
    );
    assertEquals(calls, 1);
  }
});

Deno.test("local CLI propagates native uncertainty and rejects an unadmitted installed version", async () => {
  let calls = 0;
  await assertRejects(
    () =>
      convergeCli(input, {
        invoke: () => {
          calls++;
          throw new Error("Uncertain native result");
        },
      }),
    Error,
    "Uncertain native result",
  );
  assertEquals(calls, 1);
  await assertRejects(
    () =>
      convergeCli(input, {
        invoke: () =>
          Promise.resolve({ installed: "0.13.0", configured: true }),
      }),
    Error,
    "different installed version",
  );
  await assertRejects(
    () =>
      convergeCli(input, {
        invoke: () =>
          Promise.resolve({ error_code: "unknown.private-diagnostic" }),
      }),
    Error,
    "unknown refusal",
  );
});

Deno.test("local CLI cancellation before the effect invokes nothing but late abort preserves success", async () => {
  const controller = new AbortController();
  let calls = 0;
  await assertRejects(() =>
    convergeCli(input, {
      signal: controller.signal,
      beforeEffect: () => {
        controller.abort();
        return Promise.resolve();
      },
      invoke: () => {
        calls++;
        throw new Error("Unexpected action");
      },
    })
  );
  assertEquals(calls, 0);
  const late = new AbortController();
  assertEquals(
    await convergeCli(input, {
      signal: late.signal,
      invoke: () => {
        late.abort();
        return Promise.resolve({ installed: null, configured: true });
      },
    }),
    {
      completed: true,
      outcome: "cli_present",
      reason: null,
      version: null,
      configured: true,
    },
  );
});
