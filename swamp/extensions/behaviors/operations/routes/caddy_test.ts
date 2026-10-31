import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { agentReply } from "../_lib/node-agent.ts";
import { caddyProgram } from "./caddy-program.ts";
import { publishCaddy } from "./caddy.ts";

const input = {
  node_id: 9,
  user: "nckrtl",
  agent_sha256: "a".repeat(64),
  caddy: {
    content: "",
    version: "e3b0c44298fc1c149afbf4c8996fb924",
    listen_addresses: ["10.44.0.9"],
    unserved: false,
  },
};

Deno.test("Caddy uses the native Swamp program and the complete fresh payload", async () => {
  const calls: unknown[] = [];
  const result = await publishCaddy(input, (program, payload) => {
    calls.push({ program, payload });
    return Promise.resolve({ result: "unchanged" });
  });
  assertEquals(result, {
    ready: true,
    result: "unchanged",
    version: input.caddy.version,
  });
  assertEquals(calls, [{ program: caddyProgram, payload: input.caddy }]);
});

Deno.test("render refusal is proven without a local Caddy effect", async () => {
  let called = false;
  assertEquals(
    await publishCaddy({
      node_id: input.node_id,
      user: input.user,
      agent_sha256: input.agent_sha256,
      error_code: "caddy.render_failed",
      stage: "render",
    }, () => {
      called = true;
      return Promise.resolve({});
    }),
    { error_code: "caddy.render_failed", stage: "render" },
  );
  assertEquals(called, false);
});

Deno.test("mismatched content digest refuses before any local effect", async () => {
  let called = false;
  await assertRejects(() =>
    publishCaddy(
      { ...input, caddy: { ...input.caddy, content: "changed" } },
      () => {
        called = true;
        return Promise.resolve({ result: "published" });
      },
    )
  );
  assertEquals(called, false);
});

Deno.test("only the known target validation stage crosses refusal normalization", async () => {
  for (const stage of ["validate", "reload", "private diagnostic"]) {
    const result = agentReply(
      new TextEncoder().encode(
        JSON.stringify({
          ok: false,
          error: {
            code: "caddy.publication_failed",
            details: { stage, log: "PRIVATE" },
          },
        }),
      ),
      false,
    );
    assertEquals(await publishCaddy(input, () => Promise.resolve(result)), {
      error_code: "caddy.publication_failed",
      ...(stage === "validate" ? { stage } : {}),
    });
  }
});

Deno.test("malformed success and uncertain effects do not become ready or repeat locally", async () => {
  let calls = 0;
  await assertRejects(() =>
    publishCaddy(input, () => {
      calls++;
      return Promise.resolve({ result: "unknown" });
    })
  );
  assertEquals(calls, 1);
  await assertRejects(() =>
    publishCaddy(input, () => {
      calls++;
      return Promise.reject(new Error("lost response"));
    })
  );
  assertEquals(calls, 2);
});
