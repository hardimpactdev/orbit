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
