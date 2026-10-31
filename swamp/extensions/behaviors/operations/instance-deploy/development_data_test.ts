import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { behavior, developmentResult } from "./development_data.ts";

const command = {
    name: "install",
    phase: "before_activation" as const,
    step_name: "application-000-install",
    required: true,
};
const selected = {
    "resolve-commit": {
        status: "succeeded",
        outputs: { stdout: JSON.stringify({ commit: "a".repeat(40) }) },
    },
    "select-commit": { status: "succeeded" },
};
Deno.test("records confirmed commit from native source and selection outputs", () => {
    const result = developmentResult({
        instance_id: 315,
        steps: selected,
        commands: [],
    });
    assertEquals(result.commit, "a".repeat(40));
    assertEquals(result.selection_confirmed, true);
    assertEquals(result.failed_step, null);
});
Deno.test("keeps a required command failure and its already redacted tail", () => {
    const result = developmentResult({
        instance_id: 315,
        commands: [command],
        steps: {
            ...selected,
            [command.step_name]: {
                status: "failed",
                outputs: {
                    stdout: JSON.stringify({
                        exit_code: 1,
                        output_tail: "token=[REDACTED]",
                        truncated: false,
                    }),
                },
            },
        },
    });
    assertEquals(result.failed_step, "before_activation.install");
    assertEquals(result.error_code, "deployment.step_failed");
    assertEquals(result.output_tail, "token=[REDACTED]");
});
Deno.test("records an optional command warning while retaining later required failures", () => {
    const result = developmentResult({
        instance_id: 315,
        commands: [
            { ...command, required: false },
            { ...command, name: "build", step_name: "application-001-build" },
        ],
        steps: {
            ...selected,
            [command.step_name]: {
                status: "succeeded",
                outputs: {
                    stdout: JSON.stringify({
                        exit_code: 1,
                        output_tail: "",
                        truncated: false,
                    }),
                },
            },
            "application-001-build": { status: "failed" },
        },
    });
    assertEquals(result.warnings, 1);
    assertEquals(result.failed_step, "before_activation.build");
});
Deno.test("a failed native preparation does not claim selection or application execution", () => {
    const result = developmentResult({
        instance_id: 315,
        commands: [command],
        steps: {
            "fetch-source": { status: "failed" },
            [command.step_name]: { status: "skipped" },
        },
    });
    assertEquals(result.commit, null);
    assertEquals(result.selection_confirmed, false);
    assertEquals(result.failed_step, "fetch-source");
});

Deno.test("refuses a checkout changed by an application command", () => {
    const result = developmentResult({
        instance_id: 315,
        commands: [],
        steps: {
            ...selected,
            "verify-checkout": { status: "failed" },
        },
    });
    assertEquals(result.failed_step, "verify-checkout");
    assertEquals(result.error_code, "deployment.checkout_changed");
});

Deno.test("records shell refusal even when ignored exit retains no typed stdout", () => {
    for (const stdout of [undefined, "", "not-json"]) {
        const result = developmentResult({
            instance_id: 315,
            commands: [command],
            steps: {
                ...selected,
                [command.step_name]: {
                    status: "succeeded",
                    outputs: { stdout, exitCode: 1 },
                },
            },
        });
        assertEquals(result.failed_step, "before_activation.install");
        assertEquals(result.error_code, "deployment.step_failed");
    }
});

const runtime = {
    store: "/opt/orbit/vite-plus",
    vp_version: "1.4.0",
    node_version: "24.22.0",
    package_manager: "npm" as const,
    package_manager_version: "11.12.0",
    package_manager_hash: null,
};
Deno.test("keeps verified public Vite references even when an application command fails", () => {
    const result = developmentResult({
        instance_id: 315,
        runtime,
        commands: [command],
        steps: {
            ...selected,
            "verify-vp-environment": { status: "succeeded" },
            [command.step_name]: {
                status: "succeeded",
                outputs: { exitCode: 1 },
            },
        },
    });
    assertEquals(result.error_code, "deployment.step_failed");
    assertEquals(result.vp_environment, { ...runtime, status: "active" });
});
Deno.test("rejects stale manager versions before application commands", async () => {
    const ctx = { writeResource: () => Promise.resolve() };
    await assertRejects(
        () =>
            behavior.methods.managerVersion.execute({
                runtime,
                observed: "vp 1.3.0",
            }, ctx),
        Error,
        "deployment.vp_store_failed",
    );
    assertEquals(
        await behavior.methods.managerVersion.execute({
            runtime,
            observed: "vp 1.5.0",
        }, ctx),
        { ok: true },
    );
});
