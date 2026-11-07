import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import { AlgorithmMark, behavior, bindEnrollment, KeyMark } from "./plan.ts";
const input = {
    platform: "linux",
    bootstrap_user: "bootstrap",
    bootstrap_account: {
        stdout: "bootstrap:x:1000:1000::/srv/bootstrap:/bin/bash\n",
        exitCode: 0 as const,
    },
    expected_architecture: "x86_64",
    observation: { stdout: "Linux x86_64\n", exitCode: 0 as const },
    host_key: {
        algorithm: "ssh-ed25519",
        publicKey: "ssh-ed25519 public-key",
        fingerprint: "SHA256:approved",
    },
    roles: [{
        kind: "node.role_add" as const,
        intent: {
            ssh: { target: { host_keys: [`${AlgorithmMark} ${KeyMark}`] } },
        },
        execution_secrets: {
            password: {
                vault: "orbit-control-inputs" as const,
                key: "node-role-operation-7-password",
            },
        },
    }],
};
Deno.test("enrollment binds the observed host key through every frozen role connection and preserves vault references", async () => {
    const { context, getWrittenResources } = createModelTestContext();
    const result = await behavior.methods.bind.execute({
        ...input,
        platform: "linux",
        dns: [],
        cli: null,
    }, context);
    assertEquals(
        getWrittenResources().map(({ specName, name, data }) => ({
            specName,
            name,
            data,
        })),
        [
            { specName: "plan", name: "plan", data: result },
        ],
    );
    assertEquals(result.roles[0].intent.ssh, {
        target: { host_keys: ["ssh-ed25519 public-key"] },
    });
    assertEquals(
        result.roles[0].execution_secrets,
        input.roles[0].execution_secrets,
    );
    assertEquals(JSON.parse(result.stdout).architecture, "x86_64");
    assertEquals(
        result.bootstrap_authorized_keys,
        "/srv/bootstrap/.ssh/authorized_keys",
    );
});
Deno.test("enrollment binds the observed host key into every private DNS publication entry", () => {
    const entry = {
        node_id: 9,
        connection: { nodeHost: "10.44.0.9", nodeUser: "orbit", nodePort: 22, nodeIdentityFile: "/swamp/identity", become: true },
        host_keys: [`${AlgorithmMark} ${KeyMark}`],
        dns: { revision: "1", listen: ["10.44.0.1"], tlds: [], records: [] },
    };
    const result = bindEnrollment({ ...input, dns: [entry] });
    assertEquals(result.dns, [{ ...entry, host_keys: ["ssh-ed25519 public-key"] }]);
    assertEquals("private_dns" in result, false);
});
Deno.test("enrollment refuses an unresolved bootstrap account instead of inventing its home", () => {
    assertThrows(
        () => bindEnrollment({ ...input, bootstrap_account: null }),
        Error,
        "node.bootstrap_account_unavailable",
    );
});
Deno.test("enrollment refuses an unsupported observed Linux architecture before binding role effects", () => {
    assertThrows(
        () =>
            bindEnrollment({
                ...input,
                observation: { stdout: "Linux aarch64\n", exitCode: 0 },
            }),
        Error,
        "node.architecture_unsupported",
    );
});
Deno.test("enrollment refuses a platform mismatch before binding role effects", () => {
    assertThrows(
        () =>
            bindEnrollment({
                ...input,
                observation: { stdout: "Darwin arm64\n", exitCode: 0 },
            }),
        Error,
        "node.platform_mismatch",
    );
});
