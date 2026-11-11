/** Exercise the shipped Instance host tasks against disposable local boundaries. */
import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { fixture, hasAnsible } from "./_support/instance_host.ts";

Deno.test({
    name:
        "a new image definition takes the stable alias while retaining the old image",
    ignore: !hasAnsible,
    async fn() {
        const f = await fixture();
        try {
            let result = await f.run();
            assert(result.ok, result.output);
            f.write({
                ...f.instances,
                image: {
                    ...f.instances.image,
                    alias: "orbit-instance-bbbbbbbbbbbb",
                },
            });
            result = await f.run();
            assert(result.ok, result.output);
            const images = f.state().images.filter((i: { aliases: { name: string }[] }) =>
                i.aliases.some((alias) => alias.name.startsWith("orbit-instance"))
            );
            assertEquals(images.map((i: { aliases: unknown }) => i.aliases), [
                [{name: "orbit-instance-aaaaaaaaaaaa"}],
                [{name: "orbit-instance-bbbbbbbbbbbb"}, {name: "orbit-instance"}],
            ]);
        } finally {
            f.cleanup();
        }
    },
});

Deno.test({
    name: "a Node without its Instance pool is refused before anything changes",
    ignore: !hasAnsible,
    async fn() {
        const f = await fixture({ pool: null });
        try {
            const result = await f.run();
            assert(!result.ok);
            assertStringIncludes(result.output, "instance.host_unavailable");
            assertEquals(f.state().projects, []);
        } finally {
            f.cleanup();
        }
    },
});

Deno.test({
    name: "a directory pool skips the clone probe: a copy there is a full copy",
    ignore: !hasAnsible,
    async fn() {
        const f = await fixture({ pool: "dir" });
        try {
            const result = await f.run();
            assert(result.ok, result.output);
            assert(
                !f.calls().some((c) => c.includes("orbit-clone-probe-copy")),
            );
            // No ZFS, so no ARC cap.
            assert(!f.has("arc.conf"));
        } finally {
            f.cleanup();
        }
    },
});

Deno.test({
    name: "an image that keeps a sudo path is never published",
    ignore: !hasAnsible,
    async fn() {
        const f = await fixture();
        try {
            const state = f.state();
            state.sudo_paths =
                "user:ubuntu\nsudoers:/etc/sudoers.d/90-cloud-init-users\nmember:ubuntu";
            Deno.writeTextFileSync(
                `${f.folder}/state.json`,
                JSON.stringify(state),
            );
            const result = await f.run();
            assert(!result.ok);
            assertStringIncludes(
                result.output,
                "the image keeps a sudo path: user:ubuntu, sudoers:/etc/sudoers.d/90-cloud-init-users, member:ubuntu",
            );
            const after = f.state();
            assert(!f.calls().some((c) => c.includes("publish") && c.includes("orbit-instance-build")));
            assertEquals(after.instances, []);
        } finally {
            f.cleanup();
        }
    },
});

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

Deno.test({
    name: "prepared publication clears inherited stock build ownership",
    ignore: !hasAnsible,
    async fn() {
        const f = await fixture();
        try {
            const result = await f.run();
            assert(result.ok, result.output);
            const state = f.state();
            const stock = state.images.find((
                image: { aliases: { name: string }[] },
            ) => image.aliases.some((alias) =>
                alias.name === f.instances.stock
            ));
            const prepared = state.images.find((
                image: { aliases: { name: string }[] },
            ) => image.aliases.some((alias) =>
                alias.name === "orbit-instance"
            ));
            assertEquals(
                stock.properties["user.orbit.stock-build"],
                f.instances.stock,
            );
            assertEquals(prepared.properties["user.orbit.stock-build"], "");
            assertEquals(state.instances, []);
        } finally {
            f.cleanup();
        }
    },
});

Deno.test({
    name:
        "the full prepared-image replacement and failure cleanup use the verified local socket despite the default remote",
    ignore: !hasAnsible,
    async fn() {
        const f = await fixture();
        try {
            let result = await f.run();
            assert(result.ok, result.output);
            const remote = f.state();
            Deno.writeTextFileSync(
                `${f.folder}/remote-state.json`,
                JSON.stringify(remote),
            );
            Deno.writeTextFileSync(
                `${f.folder}/state.json`,
                JSON.stringify({ ...remote, default_remote: true }),
            );
            const before = f.calls().length;
            f.write({
                ...f.instances,
                image: {
                    ...f.instances.image,
                    alias: "orbit-instance-bbbbbbbbbbbb",
                },
            });
            result = await f.run();
            assert(result.ok, result.output);
            assertEquals(
                JSON.parse(
                    Deno.readTextFileSync(`${f.folder}/remote-state.json`),
                ),
                remote,
            );
            assert(
                f.state().images.some((i: { aliases: { name: string }[] }) =>
                    i.aliases.some((a) =>
                        a.name === "orbit-instance-bbbbbbbbbbbb"
                    )
                ),
            );
            const local = f.state();
            Deno.writeTextFileSync(
                `${f.folder}/state.json`,
                JSON.stringify({ ...local, sudo_paths: "user:ubuntu" }),
            );
            f.write({
                ...f.instances,
                image: {
                    ...f.instances.image,
                    alias: "orbit-instance-cccccccccccc",
                },
            });
            result = await f.run();
            assert(!result.ok, result.output);
            assertEquals(f.state().instances, []);
            assertEquals(
                JSON.parse(
                    Deno.readTextFileSync(`${f.folder}/remote-state.json`),
                ),
                remote,
            );
            const targets = Deno.readTextFileSync(`${f.folder}/targets`).trim()
                .split("\n").map((line) => JSON.parse(line)).slice(before);
            const effects = targets.filter((target) =>
                target.args.some((arg: string) =>
                    [
                        "image",
                        "init",
                        "publish",
                        "copy",
                        "start",
                        "stop",
                        "delete",
                        "exec",
                        "file",
                    ].includes(arg)
                )
            );
            assert(effects.length > 0);
            assert(
                effects.every((target) =>
                    !target.remote && target.configRemote === "local" &&
                    target.socket === `${f.folder}/unix.socket`
                ),
            );
        } finally {
            f.cleanup();
        }
    },
});

Deno.test({
    name:
        "a prior prepared-image builder is refused by exact name and recipe without deletion",
    ignore: !hasAnsible,
    async fn() {
        const f = await fixture({ pool: "dir" });
        try {
            const state = f.state();
            const instances = [{
                name: "orbit-instance-build",
                project: "orbit-instances",
                status: "Stopped",
            }];
            Deno.writeTextFileSync(
                `${f.folder}/state.json`,
                JSON.stringify({ ...state, instances }),
            );
            const result = await f.run();
            assert(!result.ok, result.output);
            assertStringIncludes(result.output, "orbit-instance-build");
            assertStringIncludes(
                result.output,
                "/solutions/incus-shared-image-refresh#operator-cleanup",
            );
            assertEquals(f.state().instances, instances);
            assert(
                !f.calls().some((c) =>
                    c.includes("delete") && c.includes("orbit-instance-build")
                ),
            );
        } finally {
            f.cleanup();
        }
    },
});
