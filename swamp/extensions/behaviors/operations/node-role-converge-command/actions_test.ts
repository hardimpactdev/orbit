import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { behavior, observerFiles } from "../fleet-recipe/observer_assets.ts";
import { roleResult } from "./result.ts";
import { generatedRoleWorkflows } from "./workflows.ts";
const ctx = {
    extensionFile: (name: string) =>
        new URL("../fleet-recipe/" + name, import.meta.url).pathname,
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
            role: "database",
            footprint: { artifacts: [{ name: "agent", required: false }] },
            cli: {
                skip_reason: "release_unavailable",
                plan_error: null,
                release: null,
            },
        },
        steps: {},
    };
    const r = roleResult(input);
    assertEquals(r.role_proven, false);
    assertEquals(r.footprint.artifacts, [{
        name: "agent",
        status: "unchanged",
    }]);
});
Deno.test("the terminal relay exposes the canonical public JSON stdout receipt", async () => {
    const result = { role_proven: true, cli: { outcome: "skipped" } };
    const out = await (await import("./result.ts")).behavior.methods.relay
        .execute({ result }, ctx);
    assertEquals(JSON.parse(out.stdout), result);
});

Deno.test("Ingress requires Caddy publication and firewall proof", () => {
    const intent = {
        role: "ingress",
        assignment_id: 7,
        footprint: { artifacts: [] },
        cli: {
            skip_reason: "release_unavailable",
            plan_error: null,
            release: null,
        },
    };
    const steps = Object.fromEntries(
        [
            "Install the Node agent",
            "Install prerequisites",
            "Install Caddy",
            "Start Caddy",
            "Publish Caddy sites",
            "Prove the ports",
        ].map((name) => [name, { status: "succeeded" }]),
    );
    assertEquals(roleResult({ intent, steps }).role_proven, true);
    steps["Publish Caddy sites"].status = "failed";
    const failed = roleResult({ intent, steps });
    assertEquals(failed.role_proven, false);
    assertEquals(failed.failed_step, "Publish Caddy sites");
});

Deno.test("the agent part applies only when the key matches and the agent runs again", () => {
    const intent = {
        role: "database",
        assignment_id: 7,
        footprint: { artifacts: [{ name: "agent", required: true }] },
        cli: {
            skip_reason: null,
            plan_error: null,
            release: { version: "0.9.0" },
        },
    };
    const steps: Record<string, { status: string }> = Object.fromEntries(
        [
            "Install the Node agent",
            "Install prerequisites",
            "Prove the agent key",
            "Check the Node agent",
            "Link the Orbit CLI",
            "Write the CLI profile",
        ].map((name) => [name, { status: "succeeded" }]),
    );
    const applied = roleResult({ intent, steps });
    assertEquals(applied.footprint.artifacts, [{ name: "agent", status: "applied" }]);
    assertEquals(applied.cli, { completed: true, outcome: "cli_present", version: "0.9.0", configured: true });
    steps["Prove the agent key"].status = "failed";
    assertEquals(roleResult({ intent, steps }).footprint.failed_artifact, "agent");
});
