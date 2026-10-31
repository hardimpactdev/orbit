import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { behavior } from "./metadata.ts";
const ctx = { writeResource: () => Promise.resolve() };
const intent = { process_id: 42, container: "orbit-process-42-proof" };
Deno.test("removal serializes only the admitted Process members and supports record-only cleanup", async () => {
    const { controls } = await behavior.methods.controls.execute(
        { intent },
        ctx,
    );
    assertEquals(controls.map((v) => v.slot), [
        "canonical",
        "candidate",
        "rollback-running",
        "rollback-stopped",
        "failed",
    ]);
    assertEquals(JSON.parse(controls[1].control), {
        process_id: 42,
        runtime: "docker",
        name: intent.container,
        vite: null,
        on_demand: false,
        slot: "candidate",
    });
    assertEquals(
        await behavior.methods.controls.execute({
            intent: { process_id: null, container: null },
        }, ctx),
        { controls: [] },
    );
    await assertRejects(() =>
        behavior.methods.controls.execute({
            intent: { ...intent, container: "orbit-process-43-foreign" },
        }, ctx)
    );
});
Deno.test("removal plan refuses a missing or foreign observation before exposing any mutation member", async () => {
    const { controls } = await behavior.methods.controls.execute(
        { intent },
        ctx,
    );
    const steps = Object.fromEntries(
        controls.map(
            (c) => ["inspect-" + c.slot, {
                status: "succeeded",
                outputs: {
                    exitCode: 0,
                    stdout: JSON.stringify({
                        present: c.slot === "canonical",
                        state: c.slot === "canonical" ? "running" : "absent",
                    }),
                },
            }],
        ),
    );
    const valid = await behavior.methods.plan.execute({ controls, steps }, ctx);
    assertEquals(
        valid.controls,
        controls.map((member, index) => ({
            ...member,
            present: index === 0,
            stop: index === 0,
        })),
    );
    steps["inspect-failed"] = {
        status: "succeeded",
        outputs: {
            exitCode: 1,
            stdout:
                '{"error_code":"process.name_collision","private":"hidden"}',
        },
    };
    const refused = await behavior.methods.plan.execute(
        { controls, steps },
        ctx,
    );
    assertEquals(refused.controls, []);
    assertEquals(refused.exitCode, 1);
    assertEquals(JSON.parse(refused.stdout), {
        error_code: "process.name_collision",
    });
    delete steps["inspect-failed"];
    assertEquals(
        (await behavior.methods.plan.execute({ controls, steps }, ctx))
            .exitCode,
        1,
    );
});
Deno.test("removal completion requires every native child result and discards arbitrary diagnostics", async () => {
    const result = behavior.methods.result.execute;
    const child = {
        status: "succeeded",
        outputs: { result: { stdout: '{"backend":"absent"}', exitCode: 0 } },
    };
    assertEquals(
        await result({
            completed: true,
            steps: { "remove-canonical": child },
            expected: ["remove-canonical"],
        }, ctx),
        { stdout: '{"backend":"absent"}\n', exitCode: 0 },
    );
    for (
        const steps of (<Parameters<typeof result>[0]["steps"][]> [{}, {
            "remove-canonical": { status: "succeeded", outputs: {} },
        }])
    ) {
        assertEquals(
            (await result({
                completed: true,
                steps,
                expected: ["remove-canonical"],
            }, ctx)).exitCode,
            1,
        );
    }
    assertEquals(
        await result({
            completed: false,
            steps: {
                stop: {
                    status: "succeeded",
                    outputs: {
                        exitCode: 1,
                        stdout:
                            '{"error_code":"process.stop_failed","private":"hidden"}',
                    },
                },
            },
        }, ctx),
        { stdout: '{"error_code":"process.stop_failed"}\n', exitCode: 1 },
    );
    assertEquals(
        await result({
            completed: false,
            steps: {
                stop: {
                    status: "failed",
                    outputs: { stdout: "private diagnostic" },
                },
            },
        }, ctx),
        {
            stdout:
                '{"error_code":"database.server_destroy_outcome_unknown"}\n',
            exitCode: 1,
        },
    );
});
