import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import { prepareMonitorInput } from "./assets.ts";
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
Deno.test("monitor selects the canonical captured pool and dedicated service", () => {
    const result = prepareMonitorInput({
        runtime,
        observation: '{"present":true,"initial":false}',
    });
    assertEquals(result, {
        present: true,
        pool: "pool",
        path: "/etc/orbit/php-fpm/orbit-app-9/generated/pool.conf",
        main: "/etc/orbit/php-fpm/orbit-app-9/generated/php-fpm.conf",
        service: "orbit-orbit-app-9-php8.5-fpm.service",
        version: "8.5",
        scan_dir:
            "/etc/php/8.5/fpm/conf.d:/etc/orbit/php-fpm/orbit-app-9/generated",
    });
    assertEquals(
        prepareMonitorInput({
            runtime,
            observation: '{"present":true,"initial":true}',
        }).pool,
        "initial",
    );
});
Deno.test("monitor projects absent runtimes and refuses non-monitor intent", () => {
    assertEquals(
        prepareMonitorInput({
            runtime,
            observation: '{"present":false,"initial":true}',
        }).present,
        false,
    );
    assertThrows(() =>
        prepareMonitorInput({
            runtime: { ...runtime, mode: "converge" },
            observation: "{}",
        })
    );
    assertThrows(() =>
        prepareMonitorInput({
            runtime: { ...runtime, user: "bad;command" },
            observation: "{}",
        })
    );
});
