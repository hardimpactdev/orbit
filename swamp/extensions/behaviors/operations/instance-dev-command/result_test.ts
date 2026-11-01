import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import { createResult } from "./result.ts";
import { destroyResult, requireActions } from "./required.ts";
import { vpInstallSteps } from "../instance-vp-command/steps.ts";
const done = (outputs: Record<string, unknown> = {}) => ({
    status: "succeeded",
    outputs,
});
Deno.test("create requires real source/environment and handles no VP or setup work", () => {
    const intent = { instance_id: 7, setup_commands: [] };
    const steps = {
        "source-result": done({
            branch: "main",
            commit: "a".repeat(40),
            php_version: null,
            laravel: false,
        }),
        "env-write": done(),
        "env-capture": done({ keys: ["APP_ENV"] }),
        "vp-requirements": done({ ready: true, runtime: null }),
        "vp-result": done({ ready: true, runtime: null }),
    };
    assertEquals(createResult(intent, steps).ready, true);
    assertEquals(
        createResult(intent, { ...steps, "env-write": { status: "skipped" } })
            .ready,
        false,
    );
    assertEquals(
        createResult(intent, {
            ...steps,
            "vp-requirements": { status: "skipped" },
        }).ready,
        false,
    );
});
Deno.test("create requires every ordered setup action and actual VP verification", () => {
    const intent = { instance_id: 7, setup_commands: [{}] };
    const steps = {
        "source-result": done(),
        "env-write": done(),
        "env-capture": done(),
        "vp-requirements": done({ runtime: {} }),
        "vp-result": done({ ready: true }),
        "finish-setup-0-0": done(),
    };
    assertEquals(createResult(intent, steps).ready, true);
    assertEquals(
        createResult(intent, {
            ...steps,
            "finish-setup-0-0": { status: "skipped" },
        }).ready,
        false,
    );
    assertEquals(
        createResult(intent, { ...steps, "vp-result": { status: "skipped" } })
            .ready,
        false,
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

Deno.test("create retains the typed source branch collision refusal", () => {
    const result = createResult({ instance_id: 782, setup_commands: [] }, {
        "source-branch-available": done({
            available: false,
            error_code: "instance.branch_in_use",
        }),
        "source-branch-accepted": { status: "failed" },
    });
    assertEquals(result.ready, false);
    assertEquals(
        JSON.parse(result.stdout).error_code,
        "instance.branch_in_use",
    );
});
