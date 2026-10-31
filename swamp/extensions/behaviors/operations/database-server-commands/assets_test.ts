import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { behavior } from "./assets.ts";

const context = {
    extensionFile: (name: string) => "/native-cache/" + name,
    writeResource: () => Promise.resolve(),
};

Deno.test("server files contain only public admitted inputs and native bundle paths", async () => {
    const value = await behavior.methods.read.execute({
        intent: { driver: "mysql", process_id: 42 },
    }, context);
    assertEquals(value, {
        directory: "/native-cache/scripts",
        intent_json: '{"driver":"mysql","process_id":42}',
    });
});
Deno.test("server plan selects creation, no-op and replacement using observed specs", async () => {
    const spec = "b".repeat(64);
    const plan = (state: unknown) =>
        behavior.methods.plan.execute(
            { stdout: JSON.stringify(state), spec },
            context,
        );
    assertEquals(await plan({ present: false, state: "absent" }), {
        present: false,
        state: "absent",
        replace: false,
        rollback_slot: "rollback-stopped",
    });
    assertEquals(
        (await plan({ present: true, state: "running", spec })).replace,
        false,
    );
    assertEquals(
        await plan({ present: true, state: "running", spec: "c".repeat(64) }),
        {
            present: true,
            state: "running",
            spec: "c".repeat(64),
            replace: true,
            rollback_slot: "rollback-running",
        },
    );
    await assertRejects(() =>
        plan({ present: true, state: "running", spec: "not-a-spec" })
    );
});
Deno.test("server result refuses missing completion and retains bounded action refusal", async () => {
    const result = behavior.methods.result.execute;
    assertEquals(
        await result({
            completed: true,
            steps: {
                run: { status: "succeeded", outputs: { exitCode: 0 } },
                skip: { status: "skipped" },
            },
        }, context),
        { stdout: '{"backend":"active"}\n', exitCode: 0 },
    );
    assertEquals(
        await result(
            { completed: false, steps: { run: { status: "skipped" } } },
            context,
        ),
        {
            stdout: '{"error_code":"database.server_create_outcome_unknown"}\n',
            exitCode: 1,
        },
    );
    assertEquals(
        await result({
            completed: false,
            steps: {
                run: {
                    status: "succeeded",
                    outputs: {
                        exitCode: 1,
                        stdout:
                            '{"error_code":"database.server_adoption_refused"}',
                    },
                },
            },
        }, context),
        {
            stdout: '{"error_code":"database.server_adoption_refused"}\n',
            exitCode: 1,
        },
    );
    assertEquals(
        await result({
            completed: false,
            steps: {
                run: {
                    status: "failed",
                    outputs: { stdout: "private diagnostic" },
                },
            },
        }, context),
        {
            stdout: '{"error_code":"database.server_create_outcome_unknown"}\n',
            exitCode: 1,
        },
    );
});

Deno.test("server result reads bounded native child output before assertion diagnostics", async () => {
    const value = await behavior.methods.result.execute({
        completed: false,
        steps: {
            initial: {
                status: "succeeded",
                outputs: {
                    result: {
                        exitCode: 1,
                        stdout:
                            '{"error_code":"database.server_adoption_refused"}',
                    },
                },
            },
            scrub: { status: "skipped" },
            assertion: {
                status: "failed",
                outputs: { stderr: "private diagnostic" },
            },
        },
    }, context);
    assertEquals(value, {
        stdout: '{"error_code":"database.server_adoption_refused"}\n',
        exitCode: 1,
    });
});

Deno.test("SQL user result uses its fixed fallback without exporting diagnostics", async () => {
    const value = await behavior.methods.result.execute({
        completed: false,
        failure_code: "database.user_create_outcome_unknown",
        steps: {
            apply: {
                status: "failed",
                outputs: { stdout: "private SQL diagnostic" },
            },
        },
    }, context);
    assertEquals(value, {
        stdout: '{"error_code":"database.user_create_outcome_unknown"}\n',
        exitCode: 1,
    });
});
