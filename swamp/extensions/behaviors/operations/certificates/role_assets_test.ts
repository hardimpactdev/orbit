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
Deno.test("role metadata resolves one script directory and validates public intent", async () => {
    const result = await behavior.methods.read.execute({ leaf }, ctx);
    assertEquals(JSON.parse(result.input_json), leaf);
    assert(result.scripts_directory.endsWith("/scripts"));
    await assertRejects(() =>
        behavior.methods.read.execute({
            leaf: { ...leaf, scope: "route-1" as "role-metrics" },
        }, ctx)
    );
    const scripts = [
        "role_request.py",
        "role_publish.py",
        "role_verify.py",
        "role_cleanup.py",
        "role_paths.py",
        "local.py",
    ];
    for (const script of scripts) {
        const source = await Deno.readTextFile(
            new URL("scripts/" + script, import.meta.url),
        );
        for (
            const forbidden of [
                "publication_lock",
                "flock",
                "time.sleep",
                "ctypes",
                ".previous",
                "def restore(",
                "def finish(",
            ]
        ) {
            assertEquals(
                source.includes(forbidden),
                false,
                script + ": " + forbidden,
            );
        }
    }
});
Deno.test("role decoder refuses private CSR, foreign signer response and failed publication", async () => {
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
            stdout: '{"error_code":"certificate.role_failed"}',
        }, ctx)
    );
    assertEquals(
        await behavior.methods.result.execute({
            leaf,
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

Deno.test("Swamp role leaf is closed to its reserved hostname and public descriptors", async () => {
    const swamp = {
        ...leaf,
        scope: "role-swamp" as const,
        domain: "swamp.orbit",
    };
    const result = await behavior.methods.read.execute(
        { leaf: swamp } as never,
        ctx,
    );
    assertEquals(JSON.parse(result.input_json), swamp);
    for (
        const rejected of [
            { ...swamp, domain: "foreign.orbit" },
            { ...swamp, path: "/tmp/another-key" },
            { ...swamp, key: "private-key-bytes" },
        ]
    ) {
        await assertRejects(() =>
            behavior.methods.read.execute({ leaf: rejected } as never, ctx)
        );
    }
});
