import { assertEquals } from "jsr:@std/assert@1";
import { macosInspection } from "./macos_result.ts";
const sample = {
    expected_architecture: "arm64",
    address: "10.44.0.12",
    platform: { stdout: "Darwin arm64\n", exitCode: 0 },
    network: {
        stdout: "utun1:\n\tinet 10.44.0.12 --> 10.44.0.12 netmask 0xffffffff\n",
        exitCode: 0,
    },
};
Deno.test("Mac inspection shapes only public checks without an agent or account read", () => {
    assertEquals(macosInspection(sample), {
        architecture: "arm64",
        verified: true,
        error_code: null,
    });
    for (
        const [changes, code] of [
            [{ expected_architecture: "x86_64" }, "node.architecture_mismatch"],
            [
                { platform: { stdout: "Linux arm64", exitCode: 0 } },
                "node.platform_mismatch",
            ],
            [
                { platform: { stdout: "Darwin other", exitCode: 0 } },
                "node.architecture_unsupported",
            ],
            [{
                network: {
                    stdout: "\tinet 10.44.0.120 netmask 0xffffffff\n",
                    exitCode: 0,
                },
            }, "node.macos_wireguard_required"],
        ] as const
    ) {
        const result = macosInspection({ ...sample, ...changes });
        assertEquals(result.verified, false);
        assertEquals(result.error_code, code);
    }
});
