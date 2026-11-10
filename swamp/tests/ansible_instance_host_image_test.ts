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
            const images = f.state().images.filter((i: { source?: string }) =>
                !i.source
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
            assert(!f.calls().some((c) => c.includes("publish")));
            assertEquals(after.instances, []);
        } finally {
            f.cleanup();
        }
    },
});
