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

Deno.test({
    name:
        "a Squid configuration that does not parse never replaces the live one, and the run fails",
    ignore: !hasAnsible,
    async fn() {
        const f = await fixture();
        try {
            let result = await f.run();
            assert(result.ok, result.output);
            f.write({
                ...f.instances,
                egress: { ...f.instances.egress, squid: "BAD\n" },
            });
            result = await f.run();
            assert(!result.ok);
            assertStringIncludes(
                result.output,
                "Parse the staged Squid configuration",
            );
            assertEquals(
                Deno.readTextFileSync(
                    `${f.folder}/egress/orbit-instance-egress.conf`,
                ),
                "http_port 10.253.0.1:3128\n",
            );
        } finally {
            f.cleanup();
        }
    },
});

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
