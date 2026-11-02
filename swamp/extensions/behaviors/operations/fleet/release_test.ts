import { assertEquals } from "jsr:@std/assert@1.0.19";
import { observed } from "./release.ts";

const record = { node_id: 12, outcome: "unchanged" as const, error_code: null, cli_version: "0.100.0", agent_version: null, step: "cli" as const, ok: true };
const agent = { sha256: "b".repeat(64), version: "0.5.0" };
const running = `${"b".repeat(64)}  /proc/41/exe\nactive\n`;
const run = (over: Record<string, unknown>) => observed(record, { agent, fetch: "succeeded", changed: false, restart: "skipped", running, ...over });

Deno.test("a pinned, running observer is unchanged; a fetch or restart makes it converged", () => {
    assertEquals(run({}), { ...record, agent_version: "0.5.0", step: "verify" });
    assertEquals(run({ changed: true, restart: "succeeded" }).outcome, "converged");
    assertEquals(run({ restart: "succeeded" }).outcome, "converged");
});

Deno.test("each failed observer step names its own refusal and stops the fleet", () => {
    assertEquals(run({ fetch: "failed" }), { ...record, step: "agent", error_code: "agent.download_failed", outcome: "failed", ok: false });
    assertEquals(run({ restart: "failed" }).error_code, "agent.restart_failed");
    assertEquals(run({ running: null }).error_code, "agent.observer_unhealthy");
    assertEquals(run({ running: `${"c".repeat(64)}  /proc/41/exe\nactive\n` }).error_code, "agent.observer_unhealthy");
    assertEquals(run({ running: `${"b".repeat(64)}  /proc/41/exe\nfailed\n` }).ok, false);
});
