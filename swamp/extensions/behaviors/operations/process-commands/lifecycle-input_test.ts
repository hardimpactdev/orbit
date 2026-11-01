import {
    assertEquals,
    assertRejects,
    assertThrows,
} from "jsr:@std/assert@1.0.14";
import { LifecycleArgs, lifecycleAssets } from "./lifecycle-input.ts";
const control = {
    process_id: 17,
    runtime: "systemd",
    name: "orbit-process-17-worker.service",
    on_demand: false,
    vite: null,
};
const unit =
    "[Unit]\nX-Orbit-Process-ID=17\n[Service]\nExecStart=/bin/sleep 30\n";
Deno.test("lifecycle public unit is digest-bound, serializable, and has no private material input", async () => {
    const hash = Array.from(
        new Uint8Array(
            await crypto.subtle.digest(
                "SHA-256",
                new TextEncoder().encode(unit),
            ),
        ),
        (b) => b.toString(16).padStart(2, "0"),
    ).join("");
    const args = LifecycleArgs.parse({
        control,
        installation: {
            process_id: 17,
            runtime: "systemd",
            name: control.name,
            on_demand: false,
            unit,
            desired: "running",
            content_hash: hash,
            has_environment: false,
            environment_path: "/etc/orbit/processes/17/" + "a".repeat(64) +
                ".env",
            activation_pending: false,
            retired: null,
        },
    });
    const result = await lifecycleAssets(args, "/scripts");
    assertEquals(result.unit, unit);
    assertEquals(JSON.parse(result.intent_json), {
        installation: args.installation,
    });
    await assertRejects(() =>
        lifecycleAssets({
            ...args,
            installation: {
                ...args.installation!,
                content_hash: "b".repeat(64),
            },
        }, "/scripts")
    );
    await assertRejects(() =>
        lifecycleAssets({
            ...args,
            installation: {
                ...args.installation!,
                retired: { runtime: "systemd", name: control.name },
            },
        }, "/scripts")
    );
});
Deno.test("Docker public input refuses private environment and shell image syntax before effects", () => {
    const value = {
        control: {
            ...control,
            runtime: "docker",
            name: "orbit-process-17-worker",
        },
        installation: {
            process_id: 17,
            runtime: "docker",
            name: "orbit-process-17-worker",
            desired: "stopped",
            on_demand: false,
            spec: "a".repeat(64),
            has_environment: true,
            retired: null,
            definition: {
                image: "example:1",
                command: ["sleep", "30"],
                restart: "no",
                working_directory: null,
                ports: [],
                mounts: [],
            },
        },
    };
    LifecycleArgs.parse(value);
    for (
        const definition of [{
            ...value.installation.definition,
            environment: { PASSWORD: "private" },
        }, {
            ...value.installation.definition,
            image: "image; touch /tmp/other",
        }]
    ) {
        assertThrows(() =>
            LifecycleArgs.parse({
                ...value,
                installation: { ...value.installation, definition },
            })
        );
    }
});
