import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import { monitorAssets } from "./assets.ts";
const runtime = {
    user: "orbit-app-9",
    home: "/home/orbit-app-9",
    version: "8.5",
    marker: "instance:9",
    mode: "monitor",
    application: "/home/orbit-app-9/current",
    initial_application: "/home/orbit-app-9/releases/initial",
    configuration: {
        main: "main",
        pool: "pool",
        initial_pool: "initial",
        local_defaults: "local",
        master_ini: "ini",
        unit: "unit",
    },
};
Deno.test("monitor metadata preserves public runtime and resolves one script per action", () => {
    const args = {
        run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
        target_node_id: 9,
        runtime,
    };
    const result = monitorAssets(args, (name) => "/native-cache/" + name);
    assertEquals(JSON.parse(result.input_json), {
        run_id: args.run_id,
        runtime: args.runtime,
    });
    assertEquals(Object.keys(result.scripts).length, 10);
    assertEquals(result.scripts.publish, "/native-cache/scripts/publish.py");
});
Deno.test("monitor metadata refuses operational modes, traversal and env overflow", () => {
    for (
        const value of [{ ...runtime, mode: "converge" }, {
            ...runtime,
            home: "/home/../etc",
        }, {
            ...runtime,
            configuration: {
                ...runtime.configuration,
                pool: "x".repeat(65536),
            },
        }]
    ) {
        assertThrows(() =>
            monitorAssets({
                run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
                target_node_id: 9,
                runtime: value,
            }, () => "unused")
        );
    }
});

Deno.test("proxy effect placement refuses before resolving command assets", () => {
    for (const target_node_id of [1, 18]) {
        assertThrows(() =>
            monitorAssets({
                run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
                target_node_id,
                runtime,
            }, () => "unused")
        );
    }
});
