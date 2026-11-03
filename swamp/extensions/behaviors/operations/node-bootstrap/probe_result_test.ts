import { assertEquals, assertThrows } from "jsr:@std/assert@1";
import { bootstrapProbeResult } from "./probe_result.ts";
const sample = {
    bootstrap: { platform: "linux" as const, expected_architecture: "x86_64" },
    observation: { stdout: "Linux x86_64\n", exitCode: 0 as const },
    host_key: {
        algorithm: "ssh-ed25519",
        publicKey: "ssh-ed25519 AAAA fixture",
        fingerprint: "SHA256:fixture",
    },
};
Deno.test("enrollment decodes public SSH observations", () => {
    assertEquals(JSON.parse(bootstrapProbeResult(sample).stdout), {
        probed: true,
        architecture: "x86_64",
        host_type: "ssh-ed25519",
        public_key: "AAAA",
        host_key_fingerprint: "SHA256:fixture",
    });
});
Deno.test("platform and architecture decisions remain in the public result decoder", () => {
    assertThrows(
        () =>
            bootstrapProbeResult({
                ...sample,
                observation: { stdout: "Darwin arm64", exitCode: 0 },
            }),
        Error,
        "node.platform_mismatch",
    );
    assertThrows(
        () =>
            bootstrapProbeResult({
                ...sample,
                observation: { stdout: "Linux arm64", exitCode: 0 },
            }),
        Error,
        "node.architecture_unsupported",
    );
    assertThrows(
        () =>
            bootstrapProbeResult({
                ...sample,
                bootstrap: {
                    platform: "macos",
                    expected_architecture: "x86_64",
                },
                observation: { stdout: "Darwin arm64", exitCode: 0 },
            }),
        Error,
        "node.architecture_mismatch",
    );
    assertThrows(() =>
        bootstrapProbeResult({
            ...sample,
            observation: { stdout: "Linux x86_64", exitCode: 1 },
        })
    );
});
