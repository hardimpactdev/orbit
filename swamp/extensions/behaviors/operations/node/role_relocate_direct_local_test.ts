import {
  assertEquals,
  assertRejects,
  assertStringIncludes,
} from "jsr:@std/assert@1.0.19";
import { WebSocketRuntimeError } from "../integrations/websocket_runtime_system.ts";
import {
  installWebSocketRuntime,
  removeWebSocketRuntime,
  type WebSocketRuntimeActions,
} from "./role_relocate_direct_local.ts";
import type { SystemCommand, SystemTransport } from "./system_command.ts";
const plan = {
  node_id: 13,
  agent_sha256: "a".repeat(64),
  user: "nckrtl",
  install_path: "/opt/orbit/websocket",
  repository: "https://github.com/hardimpactdev/orbit-reverb.git",
  ref: "a".repeat(40),
  port: 8080,
};
const privateMaterial = {
  withPrivateMaterial: async (
    callback: (material: Record<string, unknown>) => Promise<void>,
  ) => await callback({ websocket_environment: "APP_KEY=synthetic-secret\n" }),
};
const account: SystemTransport = () =>
  Promise.resolve({
    success: true,
    stdout: '{"user":"nckrtl","group":"operators","home":"/home/nckrtl"}',
  });
function runtime(
  converge: WebSocketRuntimeActions["converge"],
  remove: WebSocketRuntimeActions["remove"] = () => Promise.resolve({}),
): WebSocketRuntimeActions {
  return { converge, remove };
}
Deno.test("WebSocket native install resolves the local account and keeps environment inside its private callback", async () => {
  let opened = false;
  let calls = 0;
  const result = await installWebSocketRuntime(
    plan,
    {
      withPrivateMaterial: async (callback) => {
        opened = true;
        try {
          await privateMaterial.withPrivateMaterial(callback);
        } finally {
          opened = false;
        }
      },
    },
    undefined,
    account,
    runtime((input) => {
      calls++;
      assertEquals(opened, true);
      assertEquals(input.group, "operators");
      assertEquals(input.environment, "APP_KEY=synthetic-secret\n");
      assertEquals(input.ref, plan.ref);
      assertStringIncludes(input.unit, "Group=operators\n");
      return Promise.resolve({
        release: "/opt/orbit/websocket.releases/release." + "b".repeat(32),
      });
    }),
  );
  assertEquals(calls, 1);
  assertEquals(result, { completed: true });
  assertEquals(JSON.stringify(result).includes("synthetic-secret"), false);
});
Deno.test("foreign account refuses before private material or runtime mutation", async () => {
  let reads = 0;
  await assertRejects(() =>
    installWebSocketRuntime(
      plan,
      {
        withPrivateMaterial: () => {
          reads++;
          return Promise.resolve();
        },
      },
      undefined,
      () =>
        Promise.resolve({
          success: true,
          stdout: '{"user":"foreign","group":"foreign","home":"/home/foreign"}',
        }),
    )
  );
  assertEquals(reads, 0);
});
Deno.test("known native WebSocket refusal returns only its bounded code", async () => {
  assertEquals(
    await installWebSocketRuntime(
      plan,
      privateMaterial,
      undefined,
      account,
      runtime(() =>
        Promise.reject(new WebSocketRuntimeError("websocket.runtime_failed"))
      ),
    ),
    { error_code: "websocket.runtime_failed" },
  );
});
Deno.test("uncertain native WebSocket installation cannot become terminal success", async () => {
  await assertRejects(() =>
    installWebSocketRuntime(
      plan,
      privateMaterial,
      undefined,
      account,
      runtime(() => Promise.resolve({ release: "relative" })),
    )
  );
});
Deno.test("pre-effect cancellation prevents install and late cancellation preserves confirmed outcome", async () => {
  let mutations = 0;
  const lower = runtime(() => {
    mutations++;
    return Promise.resolve({
      release: "/opt/orbit/websocket.releases/release." + "b".repeat(32),
    });
  });
  await assertRejects(() =>
    installWebSocketRuntime(
      plan,
      privateMaterial,
      AbortSignal.abort(),
      account,
      lower,
    )
  );
  assertEquals(mutations, 0);
  const late = new AbortController();
  assertEquals(
    await installWebSocketRuntime(
      plan,
      privateMaterial,
      late.signal,
      account,
      runtime(() => {
        late.abort();
        return Promise.resolve({
          release: "/opt/orbit/websocket.releases/release." + "b".repeat(32),
        });
      }),
    ),
    { completed: true },
  );
});
Deno.test("native source teardown has no private read and never purges during relocation", async () => {
  const input = {
    node_id: 14,
    agent_sha256: plan.agent_sha256,
    install_path: plan.install_path,
    purge: false,
  };
  const calls: unknown[] = [];
  assertEquals(
    await removeWebSocketRuntime(
      input,
      undefined,
      account,
      runtime(() => Promise.reject(new Error("cannot converge")), (raw) => {
        calls.push(raw);
        return Promise.resolve({});
      }),
    ),
    { completed: true },
  );
  assertEquals(calls, [{ install_path: plan.install_path, purge: false }]);
  await assertRejects(() =>
    removeWebSocketRuntime(
      input,
      undefined,
      account,
      runtime(
        () => Promise.reject(new Error()),
        () => Promise.resolve({ unknown: true }),
      ),
    )
  );
});
Deno.test("native private errors never escape in WebSocket diagnostics", async () => {
  const secret = "synthetic-private-environment";
  const error = await assertRejects(() =>
    installWebSocketRuntime(
      plan,
      privateMaterial,
      undefined,
      account,
      runtime(() => Promise.reject(new Error(secret))),
    )
  );
  assertEquals(String(error).includes(secret), false);
  assertStringIncludes(
    String(error),
    "Private WebSocket installation did not complete",
  );
});
Deno.test("cancellation while material arrives is known to precede any native runtime effect", async () => {
  const abort = new AbortController();
  let effects = 0;
  assertEquals(
    await installWebSocketRuntime(
      plan,
      {
        withPrivateMaterial: async (callback) => {
          abort.abort();
          await callback({
            websocket_environment: "APP_KEY=synthetic-secret\n",
          });
        },
      },
      abort.signal,
      account,
      runtime(() => {
        effects++;
        return Promise.resolve({ release: "/opt/orbit/release" });
      }),
    ),
    { error_code: "operation.cancelled" },
  );
  assertEquals(effects, 0);
});
Deno.test("default WebSocket runtime dispatches only canonical fixed Python commands and private stdin", async () => {
  const calls: SystemCommand[] = [];
  const transport: SystemTransport = (command) => {
    calls.push(command);
    if (command.command === "/usr/bin/python3") return account(command);
    return Promise.resolve({
      success: true,
      stdout: JSON.stringify({
        release: plan.install_path + ".releases/release." + "b".repeat(32),
      }),
    });
  };
  assertEquals(
    await installWebSocketRuntime(plan, privateMaterial, undefined, transport),
    { completed: true },
  );
  assertEquals(calls.length, 2);
  assertEquals(calls[1].command, "/usr/bin/sudo");
  assertEquals(calls[1].args.slice(0, 4), [
    "-n",
    "--",
    "/usr/bin/python3",
    "-c",
  ]);
  assertEquals(
    calls[1].args.some((arg) => arg.includes("synthetic-secret")),
    false,
  );
  assertEquals(
    JSON.parse(calls[1].stdin!).environment,
    "APP_KEY=synthetic-secret\n",
  );
});
