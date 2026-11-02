import {
    assertEquals,
    assertRejects,
    assertStringIncludes,
} from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1.0.12";
import { behavior } from "./assets.ts";

const read = (path: string) =>
    Deno.readTextFile(new URL(path, import.meta.url));
Deno.test("known-hosts script metadata is fixed209 and written as a native resource", async () => {
    const writes: unknown[] = [];
    const result = await behavior.methods.known_hosts.execute(
        { node_id: 209 },
        {
            extensionFile: (path) => new URL(path, import.meta.url).pathname,
            writeResource: (...args) => Promise.resolve(writes.push(args)),
        },
    );
    assertEquals(writes, [["known_hosts_script", "script", result]]);
    assertStringIncludes(result.script_path, "/scripts/write_known_hosts.py");
    for (
        const input of [{ node_id: 9 }, {
            node_id: 209,
            private_key: "refused",
        }]
    ) {
        await assertRejects(() =>
            behavior.methods.known_hosts.execute(input, {
                extensionFile: () => {
                    throw new Error("must not read assets");
                },
                writeResource: () => {
                    throw new Error("must not write resources");
                },
            })
        );
    }
});

Deno.test("manifest scripts reach a remote cache without source checkout access", async () => {
    const manifest = parse(
        await Deno.readTextFile(new URL("manifest.yaml", import.meta.url)),
    ) as {
        version: string;
        additionalFiles: string[];
    };
    const cache = await Deno.makeTempDir({ prefix: "known-hosts-assets-" });
    const cacheRoot = new URL(`file://${cache}/`);
    try {
        for (const relative of manifest.additionalFiles) {
            const destination = new URL(relative, cacheRoot);
            await Deno.mkdir(new URL(".", destination), { recursive: true });
            await Deno.copyFile(
                new URL(relative, import.meta.url),
                destination,
            );
        }
        const context = {
            extensionFile: (relative: string) =>
                new URL(relative, cacheRoot).pathname,
            writeResource: () => Promise.resolve(),
        };
        const known = await behavior.methods.known_hosts.execute({
            node_id: 209,
        }, context);
        assertEquals(
            await Deno.readTextFile(known.script_path),
            await read("scripts/write_known_hosts.py"),
        );
        const credential = await behavior.methods.credential_check.execute({
            node_id: 209,
        }, context);
        assertEquals(
            await Deno.readTextFile(credential.script_path),
            await read("scripts/credential_check.py"),
        );
        assertEquals(manifest.version, behavior.version);
    } finally {
        await Deno.remove(cache, { recursive: true });
    }
});
