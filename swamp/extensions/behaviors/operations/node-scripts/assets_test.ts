import { assertEquals } from "jsr:@std/assert@1";
import { convergeResult } from "./assets.ts";

const artifact = (name: string, required = true) => ({
    name,
    digest: "b".repeat(64),
    required,
    repair: false,
});
const intent = (names: string[]) => ({
    refusal: null,
    first_visit: false,
    artifacts: names.map((n) => artifact(n)),
});
const ok = { status: "succeeded" };
const role = (artifacts: unknown[]) => ({
    status: "succeeded",
    outputs: { result: { stdout: JSON.stringify({ artifacts }) } },
});

Deno.test("every chart step succeeded: each part is applied", () => {
    const result = convergeResult({
        intent: intent(["agent", "peer-dns", "tmpfiles"]),
        steps: {
            "Configure the Node agent": ok,
            "Apply private DNS policy": ok,
            "Re-apply role files": role([{ name: "tmpfiles", status: "applied" }]),
            "Check the footprint": ok,
        },
    });
    assertEquals(result, {
        artifacts: [
            { name: "agent", status: "applied" },
            { name: "peer-dns", status: "applied" },
            { name: "tmpfiles", status: "applied" },
        ],
        footprint_proven: true,
    });
});

Deno.test("a failed role child keeps role parts unproven and records the agent", () => {
    const result = convergeResult({
        intent: intent(["agent", "caddy", "tmpfiles"]),
        steps: {
            "Configure the Node agent": ok,
            "Re-apply role files": { status: "failed" },
        },
    });
    assertEquals(result, {
        artifacts: [{ name: "agent", status: "applied" }],
        footprint_proven: false,
        failed_artifact: "caddy",
        error_code: "operation.native_failed",
    });
});

Deno.test("a key mismatch records nothing for the agent; an admission refusal keeps its code", () => {
    assertEquals(
        convergeResult({
            intent: intent(["agent"]),
            steps: { "Configure the Node agent": { status: "failed" } },
        }).failed_artifact,
        "agent",
    );
    assertEquals(
        convergeResult({
            intent: { ...intent(["agent"]), refusal: "node.footprint_failed" },
            steps: {},
        }),
        { artifacts: [], footprint_proven: false, error_code: "node.footprint_failed" },
    );
});

Deno.test("a refused Caddyfile on a first visit fails the footprint", () => {
    const result = convergeResult({
        intent: { ...intent(["agent", "caddy"]), first_visit: true },
        steps: {
            "Configure the Node agent": ok,
            "Re-apply role files": role([{
                name: "caddy",
                status: "skipped",
                reason: "caddy_validate_refused",
            }]),
            "Check the footprint": ok,
        },
    });
    assertEquals(result.footprint_proven, false);
    assertEquals(result.error_code, "node.footprint_caddy_failed");
});
