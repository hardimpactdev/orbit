/** Exercise the shipped Instance host tasks against disposable local boundaries. */
import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { fixture, hasAnsible } from "./_support/instance_host.ts";

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
