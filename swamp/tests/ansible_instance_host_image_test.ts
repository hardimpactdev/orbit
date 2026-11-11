/** Exercise the shipped Instance host tasks against disposable local boundaries. */
import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { fixture, hasAnsible } from "./_support/instance_host.ts";

Deno.test({
    name:
        "a new image definition is built, takes the stable alias and replaces the old image",
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
            assertEquals(images.map((i: { aliases: unknown }) => i.aliases), [[
                { name: "orbit-instance-bbbbbbbbbbbb" },
                { name: "orbit-instance" },
            ]]);
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
