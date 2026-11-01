import { assertEquals } from "jsr:@std/assert@1.0.19";
import { behavior } from "./create_assets.ts";

const context = {
    extensionFile: (name: string) => "/native-cache/" + name,
    writeResource: () => Promise.resolve(),
};

Deno.test("create action output accepts exact success and bounded refusal only", async () => {
    const decode = (stdout: string, exit_code = 0) =>
        behavior.methods.action_result.execute({ stdout, exit_code }, context);
    assertEquals(await decode('{"ready":true}'), {
        stdout: '{"backend":"active"}\n',
        exitCode: 0,
    });
    assertEquals(await decode('{"error_code":"database.name_conflict"}', 1), {
        stdout: '{"error_code":"database.name_conflict"}\n',
        exitCode: 1,
    });
    for (
        const value of [
            ['{"ready":true}', 1],
            ['{"backend":"active"}', 1],
            ['{"ready":true,"password":"private"}', 0],
            ['{"error_code":"private-diagnostic"}', 1],
            ["private-diagnostic", 1],
        ] as const
    ) {
        assertEquals(await decode(value[0], value[1]), {
            stdout: '{"error_code":"database.create_outcome_unknown"}\n',
            exitCode: 1,
        });
    }
});

Deno.test("create projection refuses missing outcomes and preserves typed child failure", async () => {
    const result = behavior.methods.result.execute;
    assertEquals(await result({ completed: true, steps: {} }, context), {
        stdout: '{"backend":"active"}\n',
        exitCode: 0,
    });
    assertEquals(await result({ completed: false, steps: {} }, context), {
        stdout: '{"error_code":"database.create_outcome_unknown"}\n',
        exitCode: 1,
    });
    assertEquals(
        await result({
            completed: false,
            steps: {
                action: {
                    status: "failed",
                    outputs: {
                        result: {
                            stdout: '{"error_code":"database.name_conflict"}',
                            exitCode: 1,
                        },
                    },
                },
            },
        }, context),
        {
            stdout: '{"error_code":"database.name_conflict"}\n',
            exitCode: 1,
        },
    );
});
