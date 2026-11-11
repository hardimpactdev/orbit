/** Exercise the shipped Instance host tasks against disposable local boundaries. */
import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { fixture, hasAnsible } from "./_support/instance_host.ts";

for (const [pool, temporary] of [["dir", "orbit-instance-build"], ["zfs", "orbit-clone-probe-copy"]]) {
    Deno.test({
        name: `${temporary} refuses an inherited unguarded profile NIC before starting`,
        ignore: !hasAnsible,
        async fn() {
            const f = await fixture({pool});
            try {
                const state = f.state();
                state.projects.push({name: "orbit-instances", config: {"features.networks": "false", "features.profiles": "true", "features.images": "true"}});
                state.profiles["orbit-instances"] = {config: {}, devices: {eth1: {type: "nic", network: "orbitinst0"}}};
                Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));

                const result = await f.run();

                assert(!result.ok, result.output);
                assertStringIncludes(result.output, "instance.container_network_unsafe");
                assert(f.calls().some((call) => call.includes(temporary) && call.includes(temporary === "orbit-instance-build" ? "init" : "copy")));
                assert(!f.calls().some((call) => call.includes("start") && call.includes(temporary)));
                assert(!f.state().instances.some((row: {name: string}) => ["orbit-instance-build", "orbit-clone-probe", "orbit-clone-probe-copy"].includes(row.name)));
            } finally { f.cleanup(); }
        },
    });
}
