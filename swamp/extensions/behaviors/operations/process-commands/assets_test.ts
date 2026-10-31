import {
    assertEquals,
    assertRejects,
    assertThrows,
} from "jsr:@std/assert@1.0.14";
import { behavior } from "./assets.ts";

const control = {
    process_id: 17,
    runtime: "systemd" as const,
    name: "orbit-process-17-worker.service",
    on_demand: false,
    vite: null,
};
Deno.test("Process metadata resolves shipped files and serializes only public input", async () => {
    const writes: unknown[] = [];
    const result = await behavior.methods.read.execute({ control }, {
        extensionFile: (path) => "/native-cache/" + path,
        writeResource: (_spec, _name, value) => {
            writes.push(value);
            return Promise.resolve({});
        },
    });
    assertEquals(result.control_script, "/native-cache/scripts/control.py");
    assertEquals(result.scripts_directory, "/native-cache/scripts");
    assertEquals(JSON.parse(result.control_json), control);
    assertEquals(result.installation_json, "null");
    assertEquals(
        result.stage_unit_script,
        "/native-cache/scripts/stage_unit.py",
    );
    assertEquals(
        result.create_container_script,
        "/native-cache/scripts/create_container.py",
    );
    assertEquals(writes, [result]);
});
Deno.test("typed command results support native state and publication guards without diagnostics", async () => {
    const ctx = { writeResource: () => Promise.resolve({}) };
    assertEquals(
        await behavior.methods.decode.execute({
            stdout: '{"present":true,"state":"running","spec":"' +
                "a".repeat(64) + '"}',
        }, ctx),
        { present: true, state: "running", spec: "a".repeat(64) },
    );
    assertEquals(
        await behavior.methods.decode.execute({
            stdout: '{"ready":true,"previous_hash":null}',
        }, ctx),
        { ready: true, previous_hash: null },
    );
    await assertRejects(
        () =>
            behavior.methods.decode.execute({
                stdout: '{"ready":true,"environment":"private-sentinel"}',
            }, ctx),
        Error,
        "Invalid public Process command result",
    );
});
Deno.test("public metadata refuses private payload and mismatched member identity", () => {
    assertThrows(() =>
        behavior.methods.read.arguments.parse({
            control,
            material: { environment: "sentinel" },
        })
    );
    assertThrows(() =>
        behavior.methods.read.arguments.parse({
            control,
            installation: {
                process_id: 18,
                runtime: "systemd",
                name: "orbit-process-18-other.service",
                desired: "running",
                on_demand: false,
                content_hash: "a".repeat(64),
                activation_pending: false,
                retired: null,
            },
        })
    );
});
