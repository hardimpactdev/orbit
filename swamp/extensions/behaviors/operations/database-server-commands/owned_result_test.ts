import { assertEquals } from "jsr:@std/assert@1.0.19";
import { behavior } from "./assets.ts";
const ctx = {
    extensionFile: (path: string) => path,
    writeResource: () => Promise.resolve(),
};
const result = (
    expected: string[],
    steps: Record<
        string,
        { status: string; outputs?: Record<string, unknown> }
    >,
) => behavior.methods.owned_result.execute({ expected, steps }, ctx);
const absent = { stdout: '{"backend":"absent"}', exitCode: 0 };
Deno.test("owned aggregate requires every admitted child result and permits an empty set", async () => {
    assertEquals(await result([], {}), {
        stdout: '{"backend":"absent"}\n',
        exitCode: 0,
    });
    assertEquals(
        await result(["server-1", "sqlite-2"], {
            "server-1": { status: "succeeded", outputs: { result: absent } },
            "sqlite-2": { status: "succeeded", outputs: { result: absent } },
        }),
        { stdout: '{"backend":"absent"}\n', exitCode: 0 },
    );
    for (
        const step of [
            undefined,
            { status: "cancelled" },
            { status: "failed" },
            { status: "succeeded", outputs: {} },
            {
                status: "succeeded",
                outputs: {
                    stdout: '{"backend":"absent","password":"private"}',
                    exitCode: 0,
                },
            },
            { status: "failed", outputs: absent },
        ]
    ) {
        assertEquals(
            await result(["server-1"], step ? { "server-1": step } : {}),
            {
                stdout:
                    '{"error_code":"database.destroy_owned_outcome_unknown"}\n',
                exitCode: 1,
            },
        );
    }
});
Deno.test("owned aggregate retains a bounded child refusal when another outcome is unknown", async () => {
    assertEquals(
        await result(["server-1", "sqlite-2"], {
            "server-1": { status: "cancelled" },
            "sqlite-2": {
                status: "failed",
                outputs: {
                    result: {
                        stdout: '{"error_code":"instance.sqlite_copy_refused"}',
                        exitCode: 1,
                    },
                },
            },
        }),
        {
            stdout: '{"error_code":"instance.sqlite_copy_refused"}\n',
            exitCode: 1,
        },
    );
});
