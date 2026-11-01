import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import { createResult } from "./result.ts";
import { destroyResult, requireActions } from "./required.ts";
import { vpInstallSteps } from "../instance-vp-command/steps.ts";
const done = (outputs: Record<string, unknown> = {}) => ({
    status: "succeeded",
    outputs,
});
const printed = (value: Record<string, unknown>) =>
    done({ exitCode: value.ready ? 0 : 1, stdout: JSON.stringify(value) });
type Phase = { status: string; outputs?: Record<string, unknown> } | null;
const phases: {
    prepare: Phase;
    source: Phase;
    environment: Phase;
    setup: Phase;
    processes: string[];
} = {
    prepare: printed({ ready: true }),
    source: printed({ ready: true, commit: "a".repeat(40) }),
    environment: printed({ ready: true }),
    setup: printed({ ready: true, completed: [] }),
    processes: ["succeeded"],
};
Deno.test("create returns only the commit when every phase finished", () => {
    assertEquals(JSON.parse(createResult({ instance_id: 7 }, phases).stdout), {
        instance_id: 7,
        ready: true,
        name_converted_from: null,
        commit: "a".repeat(40),
    });
    assertEquals(
        createResult({ instance_id: 7 }, { ...phases, processes: [] }).ready,
        true,
    );
});
Deno.test("create names the first unfinished phase and keeps its public error code", () => {
    const cases: [Partial<typeof phases>, string, string][] = [
        [
            {
                prepare: printed({
                    ready: false,
                    error_code: "instance.branch_in_use",
                }),
                source: null,
            },
            "prepare",
            "instance.branch_in_use",
        ],
        [
            { source: { status: "failed" } },
            "source",
            "instance.create_source_failed",
        ],
        [
            { environment: { status: "skipped" } },
            "environment",
            "instance.create_environment_failed",
        ],
        [
            {
                setup: printed({
                    ready: false,
                    error_code: "instance.setup_step_failed",
                    failed_command: "migrate",
                }),
            },
            "setup",
            "instance.setup_step_failed",
        ],
        [
            { processes: ["succeeded", "failed"] },
            "processes",
            "instance.create_processes_failed",
        ],
        [
            { processes: ["skipped"] },
            "processes",
            "instance.create_processes_failed",
        ],
    ];
    for (const [change, step, code] of cases) {
        const result = JSON.parse(
            createResult({ instance_id: 7 }, { ...phases, ...change }).stdout,
        );
        assertEquals([result.ready, result.failed_step, result.error_code], [
            false,
            step,
            code,
        ]);
    }
});
Deno.test("an already active create reports ready without phase evidence", () => {
    const empty = {
        prepare: null,
        source: null,
        environment: null,
        setup: null,
        processes: [],
    };
    assertEquals(
        JSON.parse(
            createResult({ instance_id: 7, already_active: true }, empty)
                .stdout,
        ),
        { ready: true, instance_id: 7, already_active: true },
    );
});
Deno.test("empty fanouts need zero concrete actions and every present action must succeed", () => {
    requireActions(["empty"], [0], {});
    assertThrows(() => requireActions(["apply"], [1], {}));
    assertThrows(() =>
        requireActions(["apply"], [1], { "apply-0": { status: "skipped" } })
    );
    assertThrows(() =>
        requireActions(["apply"], [1], { "apply-0": done(), "apply-1": done() })
    );
    requireActions(["apply"], [2], { "apply-0": done(), "apply-1": done() });
});
Deno.test("destroy cannot accept skipped verification or publication and retains source recovery", () => {
    const intent = { members: [{}] },
        inspection = {
            members: [{ instance_id: 7 }],
            inventory_digest: "a".repeat(64),
        };
    const steps = {
        "destroy-inspect": done(inspection),
        "destroy-verified": done(),
        "destroy-runtime-ready": done(),
        "destroy-teardown-ready": done(),
        "destroy-database-ready": done(),
        "withdraw-0": done({ ready: true }),
        "remove-worktree-ready": done(),
        "remove-checkout-ready": done(),
    };
    assertEquals(destroyResult(intent, steps).ready, true);
    const result = destroyResult(intent, {
        ...steps,
        "destroy-verified": { status: "skipped" },
    });
    assertEquals(result.ready, false);
    assertEquals(JSON.parse(result.stdout).source_inspection, inspection);
});
Deno.test("VP preparation uses stock CLI environment installation and discrete official SSH effects", () => {
    const steps = vpInstallSteps("vp", "inputs.intent");
    const install = steps.find((step) => step.name === "vp-install")!;
    const task = install.task as {
        behaviorType: string;
        methodName: string;
        inputs: { argv: unknown[] };
    };
    assertEquals(task.behaviorType, "@swamp/ssh");
    assertEquals(task.methodName, "exec");
    assertEquals(task.inputs.argv.slice(3, 5), ["env", "install"]);
    assertEquals(steps.every((step) => step.target === "orbit-node-209"), true);
});
