import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { behavior, observerFiles } from "./assets.ts";
import { databaseResult } from "./result.ts";
import { databaseWorkflow, generatedRoleWorkflows } from "./workflows.ts";
const ctx = {
    extensionFile: (name: string) => new URL(name, import.meta.url).pathname,
    writeResource: () => Promise.resolve(),
};
Deno.test("checked-in role workflows match their factory", async () => {
    for (const { path, text } of generatedRoleWorkflows()) {
        assertEquals(await Deno.readTextFile(path), text);
    }
});
Deno.test("observer files preserve the narrowed canonical unit policy", () => {
    assertEquals(
        observerFiles({
            configuration: "public",
            certificate: "root",
            unit: "ProtectHome=yes",
            apps_root: null,
            home: "/home/orbit",
        }).unit,
        "ProtectHome=tmpfs\nBindReadOnlyPaths=-/home/orbit/apps",
    );
});
Deno.test("DNS refusals and mismatched observer delivery cannot emit readiness", async () => {
    await assertRejects(() =>
        behavior.methods.dns_result.execute({
            stdout: '{"error_code":"wireguard.peer_dns_apply_failed"}',
        }, ctx)
    );
    await assertRejects(() =>
        behavior.methods.observer_delivery.execute({
            sha256: "a".repeat(64),
            stdout: JSON.stringify({
                installed: true,
                changed: false,
                sha256: "b".repeat(64),
            }),
        }, ctx)
    );
});
Deno.test("terminal assembly preserves unchanged artifacts and refuses missing required steps", () => {
    const input = {
        intent: {
            assignment_id: 7,
            footprint: { artifacts: [{ name: "agent", required: false }] },
            cli: { skip_reason: "release_unavailable", plan_error: null },
        },
        steps: {},
    };
    const r = databaseResult(input);
    assertEquals(r.role_proven, false);
    assertEquals(r.footprint.artifacts, [{
        name: "agent",
        status: "unchanged",
    }]);
});
Deno.test("the complete Database graph has exactly one209 selector per effect", () => {
    for (const job of databaseWorkflow().jobs) {
        for (const step of job.steps) {
            assertEquals(step.target, "orbit-node-209");
            assertEquals("labels" in step, false);
        }
    }
});
Deno.test("the terminal relay exposes the canonical public JSON stdout receipt", async () => {
    const result = { role_proven: true, cli: { outcome: "skipped" } };
    const out = await (await import("./result.ts")).behavior.methods.relay
        .execute({ result }, ctx);
    assertEquals(JSON.parse(out.stdout), result);
});
