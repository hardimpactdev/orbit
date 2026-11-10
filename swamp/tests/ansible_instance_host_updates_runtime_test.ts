/** Exercise the shipped Instance host tasks against disposable local boundaries. */
import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { fixture, hasAnsible } from "./_support/instance_host.ts";

Deno.test({
    name:
        "a new runtime version installs beside the old one, flips current, restarts each running container's Processes and drops the old",
    ignore: !hasAnsible,
    async fn() {
        const f = await fixture();
        try {
            let result = await f.run();
            assert(result.ok, result.output);
            const state = f.state();
            state.instances.push(
                {
                    name: "app-default",
                    project: "orbit-instances",
                    status: "Running",
                },
                {
                    name: "app-asleep",
                    project: "orbit-instances",
                    status: "Stopped",
                },
            );
            Deno.writeTextFileSync(
                `${f.folder}/state.json`,
                JSON.stringify(state),
            );
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
            const before = f.calls().length;
            result = await f.run();
            assert(result.ok, result.output);
            const runtime = `${f.folder}/opt/orbit-runtime`;
            assertEquals(
                Deno.readLinkSync(`${runtime}/current`),
                "333333333333",
            );
            assert(!f.has("opt/orbit-runtime/111111111111"));
            const restarts = f.calls().slice(before).filter((c) =>
                c.includes("exec")
            );
            assertEquals(restarts, [[
                "exec",
                "--project",
                "orbit-instances",
                "app-default",
                "--",
                "systemctl",
                "try-restart",
                "orbit-*.service",
            ]]);
            // The agent folder did not change, so T3 keeps running.
            assert(!restarts.flat().includes("t3.service"));
            assert(!f.has("opt/orbit-runtime/.restart"));
        } finally {
            f.cleanup();
        }
    },
});

