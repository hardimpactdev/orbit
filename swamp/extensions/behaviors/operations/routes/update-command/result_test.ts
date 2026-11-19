import { assertEquals } from "jsr:@std/assert@1.0.19";
import { phaseResult } from "./result.ts";

Deno.test("a mutation phase is ready only when its single child succeeded", () => {
    const state = (status: string) => ({ status });
    assertEquals(
        phaseResult({
            mode: "forward",
            steps: { "forward-0": state("succeeded") },
        }),
        { ready: true },
    );
    assertEquals(
        phaseResult({
            mode: "withdraw",
            steps: { "withdraw-0": state("failed") },
        }),
        { ready: false },
    );
    assertEquals(phaseResult({ mode: "restore", steps: {} }), { ready: false });
});

Deno.test("a failed phase carries the refusal that a Node transaction wrote", () => {
    const line =
        '{"error_code":"caddy.owned_revision_conflict","detail":"Withdraw the Route from the shared files: caddy.owned_revision_conflict"}\n';
    const refusal = (stdout: string) => ({
        status: "succeeded",
        outputs: { stdout },
    });
    assertEquals(
        phaseResult({
            mode: "forward",
            steps: {
                "forward-0": { status: "failed" },
                "withdraw_node-0": { status: "failed" },
                refusal: refusal(line),
            },
        }),
        {
            ready: false,
            error_code: "caddy.owned_revision_conflict",
            detail:
                "Withdraw the Route from the shared files: caddy.owned_revision_conflict",
        },
    );
    // No refusal line (an unreachable Node), or one that is not a code, names nothing.
    for (
        const stdout of [
            "",
            "not json",
            '{"error_code":"Command exited with code 1","detail":""}',
        ]
    ) {
        assertEquals(
            phaseResult({
                mode: "forward",
                steps: {
                    "withdraw_node-0": { status: "failed" },
                    refusal: refusal(stdout),
                },
            }),
            {
                ready: false,
            },
        );
    }
    assertEquals(
        phaseResult({
            mode: "forward",
            steps: {
                "forward-0": { status: "succeeded" },
                refusal: refusal(line),
            },
        }),
        {
            ready: true,
        },
    );
});
