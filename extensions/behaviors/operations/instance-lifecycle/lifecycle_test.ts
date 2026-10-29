import { assertEquals } from "jsr:@std/assert@1.0.19";
import {
  type Child,
  lifecycle,
  type Receipt,
  type Session,
} from "./lifecycle.ts";

class Fixture implements Session {
  id = 100;
  nodeId = 9;
  kind = "instance.create";
  input: Receipt = {
    instance_id: 501,
    initial_status: "reserved",
    first_setup_pending: true,
    route_id: 30,
    database_server_id: null,
    database_clone_source_id: null,
    setup_digest: "a".repeat(64),
    teardown_digest: "b".repeat(64),
  };
  calls: string[] = [];
  children: Child[] = [];
  results = new Map<string, Receipt>();
  saved = new Map<string, Receipt>();
  effects = new Map<string, number>();
  loseReply: string | null = null;
  run(key: string): Promise<Receipt> {
    return this.effect(key, this.results.get(key) ?? { instance_id: 501 });
  }
  child(child: Child): Promise<Receipt> {
    this.children.push(child);
    return this.effect(
      child.key,
      this.results.get(child.key) ??
        (child.kind === "instance.run_steps"
          ? { outcome: "succeeded", error_code: null, failed_step: null }
          : { instance_id: 501 }),
    );
  }
  async effect(key: string, result: Receipt): Promise<Receipt> {
    this.calls.push(key);
    await Promise.resolve();
    if (!this.saved.has(key)) {
      this.effects.set(key, (this.effects.get(key) ?? 0) + 1);
      this.saved.set(key, structuredClone(result));
    }
    if (this.loseReply === key) {
      this.loseReply = null;
      throw new Error("reply lost");
    }
    return this.saved.get(key)!;
  }
}

Deno.test("rename waits for the Route child before it records branch or environment", async () => {
  const f = new Fixture();
  f.kind = "instance.rename";
  f.input.domain = "preview.test";
  f.results.set("route-update", { error_code: "route.domain_conflict" });
  assertEquals((await lifecycle(f)).error_code, "route.domain_conflict");
  assertEquals(f.calls, ["rename-inspect", "route-update"]);
});
