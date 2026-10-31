import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { behavior } from "./role_assets.ts";
import { roleCertificateSteps } from "./role_steps.ts";
const leaf = {
    node_id: 9,
    scope: "role-metrics" as const,
    domain: "metrics.orbit",
    version: "0123456789abcdef",
    root_hash: "a".repeat(64),
};
const writes: unknown[] = [];
const ctx = {
    extensionFile: (path: string) => new URL(path, import.meta.url).pathname,
    writeResource: (...args: unknown[]) => Promise.resolve(writes.push(args)),
};
Deno.test("role native asset has one action and excludes the retained whole-Node engine", async () => {
    const result = await behavior.methods.read.execute({
        action: "request",
        leaf,
    }, ctx);
    assert(result.script_body.includes('\\"operation\\":\\"request\\"'));
    for (
        const forbidden of [
            "publication_lock",
            "flock",
            "time.sleep",
            "reserve_backup",
            "def publish(value)",
        ]
    ) {
        assertEquals(result.script_body.includes(forbidden), false, forbidden);
    }
    await assertRejects(() =>
        behavior.methods.read.execute({ action: "publish", leaf }, ctx)
    );
    await assertRejects(() =>
        behavior.methods.read.execute({
            action: "request",
            leaf: { ...leaf, scope: "route-1" as "role-metrics" },
        }, ctx)
    );
});
Deno.test("role decoder refuses private CSR, foreign signer response and restored readiness", async () => {
    await assertRejects(() =>
        behavior.methods.decodeRequest.execute({
            leaf,
            stdout: JSON.stringify({
                state: "request",
                request_base64: btoa("PRIVATE KEY"),
            }),
        }, ctx)
    );
    const request = {
        state: "request" as const,
        request_base64: btoa("-----BEGIN CERTIFICATE REQUEST-----\npublic"),
    };
    await assertRejects(() =>
        behavior.methods.decodeSigned.execute({
            leaf,
            request,
            stdout: '{"certificates":[]}',
        }, ctx)
    );
    await assertRejects(() =>
        behavior.methods.result.execute({
            leaf,
            restored: true,
            stdout: '{"applied":true}',
        }, ctx)
    );
    await assertRejects(() =>
        behavior.methods.result.execute({
            leaf,
            restored: false,
            stdout: '{"error_code":"certificate.role_failed"}',
        }, ctx)
    );
    assertEquals(
        await behavior.methods.result.execute({
            leaf,
            restored: false,
            stdout: '{"applied":true}',
        }, ctx),
        { ready: true, node_id: 9, scope: leaf.scope, version: leaf.version },
    );
});
Deno.test("role child factory retains full globals and the actual root signing identity", () => {
    const globals = {
        name: "role-fleet",
        transport: { kind: "ssh", identityFile: "/approved/identity" },
        hosts: [{
            name: "target",
            address: "10.44.0.9",
            transport: { hostKeys: ["ssh-ed25519 pinned"] },
        }],
    };
    const [step] = roleCertificateSteps(
        "cert",
        "self.leaf",
        globals,
        "packages",
        "inputs.ready",
    );
    const task = step.task as Record<string, unknown>,
        input = task.inputs as Record<string, unknown>;
    assertEquals(task.workflowIdOrName, "node-role-certificate-publish");
    assertEquals(input.ssh, globals);
    assertEquals(input.parent_run_id, "${{ run.id }}");
    assertEquals(step.guard, "${{ inputs.ready }}");
});
