import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import type { NodeCall } from "../_lib/node-agent.ts";
import { reconcilePeerDns } from "./peer_dns_local.ts";

const input = {
  node_id: 2,
  agent_sha256: "a".repeat(64),
  server: "10.44.0.1",
  dns_server: "10.44.0.1",
  public_key: "A".repeat(43) + "=",
  domains: ["orbit"],
};
const healthy = {
  state: "orbit\n10.44.0.1\norbit",
  hooks: "PostUp = resolvectl dns %i 10.44.0.1; resolvectl domain %i \\~orbit",
  dns: "Link 2 (orbit): 10.44.0.1",
  domains: "Link 2 (orbit): ~orbit",
  default_route: "Link 2 (orbit): no",
  uplink: true,
};

Deno.test("local caller gets unchanged or applied without a Gateway dependency", async () => {
  for (const drift of [false, true]) {
    const calls: NodeCall[] = [];
    const order: string[] = [];
    const result = await reconcilePeerDns(input, {
      beforeEffect: () => {
        order.push("check");
        return Promise.resolve();
      },
      invoke: (call) => {
        calls.push(call);
        order.push(call.name);
        return Promise.resolve(
          call.mutates ? {} : {
            ...healthy,
            state: drift && calls.length === 1 ? "drift" : healthy.state,
          },
        );
      },
    });
    assertEquals(result, { result: healthy, changed: drift });
    assertEquals(
      order,
      drift
        ? [
          "wireguard.inspect-peer-dns",
          "check",
          "wireguard.repair-peer-dns",
          "wireguard.inspect-peer-dns",
        ]
        : ["wireguard.inspect-peer-dns", "check"],
    );
    assertEquals(
      calls.every((call) => call.pin === input.agent_sha256 && call.sudo),
      true,
    );
  }
});

Deno.test("local pre-effect cancellation stops repair and readonly completion", async () => {
  for (const drift of [false, true]) {
    const controller = new AbortController();
    const calls: NodeCall[] = [];
    await assertRejects(() =>
      reconcilePeerDns(input, {
        signal: controller.signal,
        beforeEffect: () => {
          controller.abort();
          return Promise.resolve();
        },
        invoke: (call) => {
          calls.push(call);
          return Promise.resolve({
            ...healthy,
            state: drift ? "drift" : healthy.state,
          });
        },
      }), DOMException);
    assertEquals(calls.map((call) => call.mutates), [false]);
  }
});

Deno.test("local successful repair verifies without an aborted signal", async () => {
  const controller = new AbortController();
  let calls = 0;
  const result = await reconcilePeerDns(input, {
    signal: controller.signal,
    invoke: (call, signal) => {
      ++calls;
      if (call.mutates) {
        controller.abort();
        return Promise.resolve({});
      }
      assertEquals(signal, calls === 1 ? controller.signal : undefined);
      return Promise.resolve({
        ...healthy,
        state: calls === 1 ? "drift" : healthy.state,
      });
    },
  });
  assertEquals(result, { result: healthy, changed: true });
});

Deno.test("local refusals stay bounded and report acknowledged repair accurately", async () => {
  for (const stage of ["inspect", "repair", "verify"]) {
    let calls = 0;
    const result = await reconcilePeerDns(input, {
      invoke: (call) => {
        ++calls;
        if (
          (stage === "inspect" && calls === 1) ||
          (stage === "repair" && call.mutates) ||
          (stage === "verify" && calls === 3)
        ) {
          return Promise.resolve({
            error_code: "wireguard.peer_dns_unreadable",
            diagnostic: "private",
          });
        }
        return Promise.resolve(
          call.mutates ? {} : { ...healthy, state: "drift" },
        );
      },
    });
    assertEquals(result, {
      result: { error_code: "vpn.peer_dns_unreadable" },
      changed: stage === "verify",
    });
    assertEquals(calls, stage === "inspect" ? 1 : stage === "repair" ? 2 : 3);
  }
});

Deno.test("local uncertain writes throw even if the signal aborts", async () => {
  const controller = new AbortController();
  const uncertainty = new Error("Uncertain effect");
  await assertRejects(
    () =>
      reconcilePeerDns(input, {
        signal: controller.signal,
        invoke: (call) => {
          if (call.mutates) {
            controller.abort();
            throw uncertainty;
          }
          return Promise.resolve({ ...healthy, state: "drift" });
        },
      }),
    Error,
    "Uncertain effect",
  );
});

Deno.test("invalid local input or an already aborted signal invokes nothing", async () => {
  let calls = 0;
  const invoke = () => {
    ++calls;
    return Promise.resolve(healthy);
  };
  await assertRejects(() =>
    reconcilePeerDns({ ...input, dns_server: "10.44.0.2" }, { invoke })
  );
  await assertRejects(
    () => reconcilePeerDns(input, { signal: AbortSignal.abort(), invoke }),
    DOMException,
  );
  assertEquals(calls, 0);
});
