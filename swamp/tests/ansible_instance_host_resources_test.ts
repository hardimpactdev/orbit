/** Exercise the shipped Instance host tasks against disposable local boundaries. */
import {
    assert,
    assertEquals,
    assertRejects,
    assertStringIncludes,
} from "jsr:@std/assert@1";
import {
    bridgeAdmits,
    bridgeAnswersDns,
    caddy,
    fixture,
    GiB,
    hasAnsible,
    nodeAddresses,
    peer,
} from "./_support/instance_host.ts";

Deno.test({
    name:
        "a task VM host keeps the ZFS ARC its task side sets: the Instance side writes no cap",
    ignore: !hasAnsible,
    async fn() {
        const f = await fixture();
        try {
            f.write({ ...f.instances, arc: false });
            const result = await f.run();
            assert(result.ok, result.output);
            assertEquals(Deno.readTextFileSync(`${f.folder}/arc`).trim(), "0");
            assert(!f.has("arc.conf"));
            // The rest of the Instance side is there.
            assert(
                f.state().networks.some((n: { name: string }) =>
                    n.name === "orbitinst0"
                ),
            );
        } finally {
            f.cleanup();
        }
    },
});

Deno.test({
    name:
        "an Instance bridge range that another interface of the Node holds refuses before any change",
    ignore: !hasAnsible,
    async fn() {
        const f = await fixture({
            addresses: [
                ...nodeAddresses.slice(0, 2),
                "6: orbitci0    inet 10.253.0.1/24 scope global orbitci0\\       valid_lft forever preferred_lft forever",
            ],
        });
        try {
            const result = await f.run();
            assert(!result.ok);
            assertStringIncludes(
                result.output,
                "instance.host_unavailable: the Instance bridge range 10.253.0.0/24 overlaps orbitci0 10.253.0.1/24",
            );
            assertEquals(f.state().projects, []);
            assertEquals(f.state().networks, []);
            assertEquals(Deno.readTextFileSync(`${f.folder}/arc`).trim(), "0");
        } finally {
            f.cleanup();
        }
    },
});
