/** Exercise the shipped Instance host tasks against disposable local boundaries. */
import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { fixture, hasAnsible } from "./_support/instance_host.ts";

Deno.test({
    name:
        "a project that lists disk paths loses the list, so a container with the idmapped folders starts",
    ignore: !hasAnsible,
    async fn() {
        const f = await fixture();
        try {
            // Beast after the first release: every restriction, the idmapped folders, and the path list that refused every start.
            let result = await f.run();
            assert(result.ok, result.output);
            const state = f.state();
            state.projects.find((p: { name: string }) =>
                p.name === "orbit-instances"
            )
                .config["restricted.devices.disk.paths"] =
                    `${f.folder}/opt/orbit-runtime,${f.folder}/opt/orbit-agent`;
            Deno.writeTextFileSync(
                `${f.folder}/state.json`,
                JSON.stringify(state),
            );
            result = await f.run();
            assert(result.ok, result.output);
            const project = f.state().projects.find((p: { name: string }) =>
                p.name === "orbit-instances"
            );
            assertEquals(
                project.config["restricted.devices.disk.paths"],
                undefined,
            );
            assertEquals(project.config["restricted.devices.disk"], "allow");
            assert(
                f.calls().some((c) =>
                    c.includes("start") && c.includes("orbit-clone-probe-copy")
                ),
            );
            result = await f.run();
            assert(result.ok, result.output);
            assertStringIncludes(result.output, "changed=0");
        } finally {
            f.cleanup();
        }
    },
});
