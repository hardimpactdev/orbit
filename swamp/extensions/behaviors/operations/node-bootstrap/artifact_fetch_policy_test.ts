import { assertEquals } from "jsr:@std/assert@1.0.19";
import { behavior, classifyFetchFailure } from "./artifact_fetch_policy.ts";

for (
    const [reason, exitCode] of [
        ["HTTP 404 and other HTTP errors", 22],
        ["DNS resolution", 6],
        ["connection refusal", 7],
        ["timeout", 28],
        ["TLS connection", 35],
        ["receive failure", 56],
    ] as const
) {
    Deno.test(`${reason} permits the upstream candidate`, () => {
        assertEquals(classifyFetchFailure({ exitCode }), { fallback: true });
    });
}
for (
    const [reason, exitCode] of [
        ["successful transfer", 0],
        ["local write failure", 23],
        ["local read failure", 26],
        ["size limit", 63],
        ["unclassified process failure", 1],
        ["outer process timeout", 124],
        ["missing curl", 127],
        ["SSH failure without an exit code", null],
    ] as const
) {
    Deno.test(`${reason} does not permit another candidate`, () => {
        assertEquals(classifyFetchFailure({ exitCode }), { fallback: false });
    });
}
Deno.test("missing current-step output cannot authorize upstream", () => {
    assertEquals(classifyFetchFailure(null), { fallback: false });
});
Deno.test("diagnostic text cannot turn a local write failure into a transport failure", async () => {
    const writes: unknown[] = [];
    await behavior.methods.classify.execute(
        behavior.methods.classify.arguments.parse({
            result: {
                exitCode: 23,
                stderr: "Download failed: curl: (22) HTTP 404",
            },
        }),
        {
            writeResource(_spec, _name, value) {
                writes.push(value);
                return Promise.resolve();
            },
        },
    );
    assertEquals(writes, [{ fallback: false }]);
});
Deno.test("classification exposes the structured decision as native step data", async () => {
    const writes: unknown[] = [];
    await behavior.methods.classify.execute({ result: { exitCode: 22 } }, {
        writeResource(_spec, _name, value) {
            writes.push(value);
            return Promise.resolve();
        },
    });
    assertEquals(writes, [{ fallback: true }]);
});
