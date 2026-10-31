import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { configureNodeAgent } from "./agent_configure_local.ts";
import type { SystemTransport } from "./system_command.ts";

const pin = "a".repeat(64);
const secretHash = "c".repeat(64);
const input = {
  configuration: "node_id = 2\n",
  certificate: "fixture-public-ca",
  unit: "[Service]\nProtectHome=yes\n",
  user: "orbit",
  apps_root: null,
  checkouts: ["/home/orbit/apps/one", "/home/other/apps/two"],
  url: null,
};
const account = () => Promise.resolve({ home: "/home/orbit", group: "orbit" });

Deno.test("shared agent configuration takes resolved data and returns only the public result", async () => {
  let calls = 0;
  const invoke: SystemTransport = (command) => {
    calls++;
    const payload = JSON.parse(command.stdin!);
    assertEquals(payload.secret_hash, secretHash);
    assertEquals(payload.checkouts, ["/home/orbit/apps/one"]);
    assertEquals(payload.allow_secret_rotation, false);
    assertEquals(
      payload.unit,
      "[Service]\nProtectHome=tmpfs\nBindReadOnlyPaths=-/home/orbit/apps\n",
    );
    return Promise.resolve({
      success: true,
      stdout: JSON.stringify({
        configured: true,
        secret_hash: secretHash,
        restarted: false,
        unclosed: [],
      }),
    });
  };
  assertEquals(
    await configureNodeAgent(
      input,
      pin,
      false,
      { secret_hash: secretHash },
      invoke,
      account,
      undefined,
      (worker, credentials) => {
        assertEquals([worker, credentials], [null, null]);
        return Promise.resolve({ configured: true, changed: true });
      },
    ),
    { changed: true },
  );
  assertEquals(calls, 1);
});

Deno.test("resolved agent data is validated before effects and refusals skip worker installation", async () => {
  let calls = 0;
  const invoke: SystemTransport = () => {
    calls++;
    return Promise.resolve({
      success: true,
      stdout: '{"configured":false,"error_code":"agent.secret_mismatch"}',
    });
  };
  await assertRejects(() =>
    configureNodeAgent(
      input,
      pin,
      false,
      { secret_hash: "invalid" },
      invoke,
      account,
    )
  );
  assertEquals(calls, 0);
  assertEquals(
    await configureNodeAgent(
      input,
      pin,
      false,
      { secret_hash: secretHash },
      invoke,
      account,
      undefined,
      () => {
        throw new Error("Worker must not install after refusal");
      },
    ),
    { changed: false, error_code: "agent.secret_mismatch" },
  );
  assertEquals(calls, 1);
});
