import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import { effectPost } from "./effects.ts";
import type { Context } from "../../operation-runner/mod.ts";

Deno.test("uncertain effects use target receipts and retry only an unknown key", async () => {
  for (const state of ["succeeded", "failed", "running", "unknown"]) {
    const resources = new Map<string, Record<string, unknown>>();
    const { context } = createModelTestContext();
    const ctx = Object.assign(context, {
      readResource: (name: string) =>
        Promise.resolve(resources.get(name) ?? null),
      writeResource: (
        _spec: string,
        name: string,
        value: Record<string, unknown>,
      ) => {
        resources.set(name, value);
        return Promise.resolve({ name });
      },
    }) as unknown as Context;
    let runs = 0;
    const post = effectPost(9, ctx, async (method, path) => {
      await Promise.resolve();
      assertEquals(method, "GET");
      assertEquals(path, "/api/v1/operation-executor/9/receipts/self-update");
      return {
        status: 200,
        body: {
          data: {
            effect_key: "op-9.self-update",
            state,
            exit_code: state === "failed" ? 1 : 0,
            result: { outcome: "updated" },
            finished_at: null,
          },
        },
      };
    }, async () => {
      await Promise.resolve();
      runs++;
      if (runs === 1) throw new Error("lost reply");
      return { outcome: "updated" };
    });
    const body = {
      key: "self-update",
      transport: "agent",
      name: "cli.self-update",
      input: { user: "orbit", downgrade_to: null },
    };
    await assertRejects(() => post("run", body), Error, "lost reply");
    if (state === "running") {
      await assertRejects(
        () => post("run", body),
        Error,
        "operation.effect_running",
      );
    } else {
      assertEquals(
        await post("run", body),
        state === "failed"
          ? { outcome: "updated", error_code: "fleet.remote_effect_failed" }
          : { outcome: "updated" },
      );
    }
    assertEquals(runs, state === "unknown" ? 2 : 1);
  }
});
