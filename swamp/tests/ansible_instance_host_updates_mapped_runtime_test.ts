/** Exercise the shipped Instance host tasks against disposable local boundaries. */
import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { fixture, hasAnsible } from "./_support/instance_host.ts";

Deno.test({
    name:
        "an old folder version that a process still maps stays; a restart that failed runs again on the next converge",
    ignore: !hasAnsible,
    async fn() {
        const f = await fixture();
        try {
            let result = await f.run();
            assert(result.ok, result.output);
            const parts = await f.artifacts("1.1");
            f.write({
                ...f.instances,
                folders: {
                    ...f.instances.folders,
                    runtime: {
                        ...f.instances.folders.runtime,
                        version: "333333333333",
                        parts: parts.runtime,
                    },
                },
            });
            // A queue worker of a stopped container's last run still maps the old Node binary.
            Deno.writeTextFileSync(
                `${f.folder}/maps`,
                `7f00-7f01 r-xp 0 00:00 1 ${f.folder}/opt/orbit-runtime/111111111111/tool/tool-1.0/tool\n`,
            );
            // A run that stopped between the flip and the restarts leaves the mark.
            const state = f.state();
            state.instances.push({
                name: "app-default",
                project: "orbit-instances",
                status: "Running",
            });
            Deno.writeTextFileSync(
                `${f.folder}/state.json`,
                JSON.stringify(state),
            );
            result = await f.run();
            assert(result.ok, result.output);
            assert(f.has("opt/orbit-runtime/111111111111"));
            Deno.writeTextFileSync(
                `${f.folder}/opt/orbit-runtime/.restart`,
                "",
            );
            const before = f.calls().length;
            result = await f.run();
            assert(result.ok, result.output);
            assert(
                f.calls().slice(before).some((c) =>
                    c.includes("exec") && c.includes("orbit-*.service")
                ),
            );
            assert(!f.has("opt/orbit-runtime/.restart"));
        } finally {
            f.cleanup();
        }
    },
});
