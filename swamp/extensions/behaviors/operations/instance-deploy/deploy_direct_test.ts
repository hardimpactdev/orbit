import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import {
    checked,
    observation,
    runtime,
    summary,
} from "../development-deploy/mod.ts";
const receipt = (value: unknown) => ({ stdout: JSON.stringify(value) });
Deno.test("native development barriers reject typed failures before later actions", () => {
    assertEquals(checked(receipt({ ready: true, commit: "a".repeat(40) })), {
        ready: true,
        commit: "a".repeat(40),
    });
    for (
        const error_code of [
            "deployment.checkout_dirty",
            "deployment.branch_diverged",
            "deployment.step_failed",
            "instance.lifecycle_busy",
        ]
    ) {
        assertThrows(
            () => checked(receipt({ ready: false, error_code })),
            Error,
            error_code,
        );
    }
    assertThrows(() => observation({ stdout: "not JSON" }));
});
Deno.test("native result retains failure and optional warnings without application output", () => {
    const result = summary({
        dev_app_0: {
            status: "succeeded",
            outputs: receipt({
                ready: true,
                warning: true,
                output: "not admitted",
            }),
        },
        dev_check: {
            status: "succeeded",
            outputs: receipt({
                ready: false,
                error_code: "deployment.checkout_dirty",
            }),
        },
        dev_check_check: { status: "failed" },
    }, 12);
    assertEquals(result.warnings, 1);
    assertEquals(result.error_code, "deployment.checkout_dirty");
    assertEquals(result.commit, null);
    assertEquals(result.output_tail, null);
});
Deno.test("only completed native inspection produces successful development commit", () => {
    const commit = "a".repeat(40);
    const result = summary({
        dev_inspect: {
            status: "succeeded",
            outputs: receipt({ ready: true, commit }),
        },
        dev_inspect_check: { status: "succeeded" },
    }, 12);
    assertEquals(result.commit, commit);
    assertEquals(result.failed_step, null);
    assertEquals(summary({}, 12).error_code, "operation.native_failed");
});

Deno.test("native Vite pins enforce locked Node engines and global Vite version without raw package data", () => {
    const pins = {
        store: "/opt/orbit/vite-plus",
        vp_version: "1.4.0",
        node_version: "24.22.0",
        package_manager: "pnpm" as const,
        package_manager_version: "10.5.0",
        package_manager_hash: null,
    };
    assertEquals(
        runtime(
            receipt({
                ready: true,
                runtime: pins,
                version: "vp 1.4.1",
                constraints: [["node", ">=22.12.0"]],
            }),
        ).runtime,
        pins,
    );

    const prerelease = { ...pins, node_version: "26.0.0-rc.1" };
    assertEquals(
        runtime(
            receipt({
                ready: true,
                runtime: prerelease,
                version: "1.4.1",
                constraints: [["node", ">=26.0.0-rc.1"]],
            }),
        ).runtime,
        prerelease,
    );
    assertThrows(
        () =>
            runtime(
                receipt({
                    ready: true,
                    runtime: pins,
                    version: "1.4.1",
                    constraints: [["node", ">=26"]],
                }),
            ),
        Error,
        "deployment.vp_requirements_invalid",
    );
    assertThrows(
        () =>
            runtime(
                receipt({
                    ready: true,
                    runtime: pins,
                    version: "1.3.0",
                    constraints: [],
                }),
            ),
        Error,
        "deployment.vp_store_failed",
    );
});
