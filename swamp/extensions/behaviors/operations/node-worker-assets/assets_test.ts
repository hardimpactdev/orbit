import {
    assertEquals,
    assertRejects,
    assertStringIncludes,
} from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1.0.12";
import { assets, behavior } from "./assets.ts";
import { Swamp, workerUnit } from "../../node-worker/config.ts";

const read = (path: string) =>
    Deno.readTextFile(new URL(path, import.meta.url));
Deno.test("public assets retain the canonical unit, official pin and checker prerequisite", async () => {
    const result = await assets({ node_id: 209, user: "orbit" }, read);
    assertEquals(result.unit, workerUnit({ node_id: 209, user: "orbit" }));
    assertEquals(result.runtime_version, Swamp.version);
    assertEquals(result.artifact_sha256, Swamp.sha256);
    assertEquals(result.artifact_bytes, Swamp.bytes);
    assertStringIncludes(result.artifact_url, "/swamp-club/swamp/");
    assertStringIncludes(result.unit, `ExecStartPre=+${result.checker_path}`);
    assertStringIncludes(
        result.checker,
        `MINIMUM_MEMORY_BYTES = ${Swamp.bytes + Swamp.headroom}`,
    );
    assertStringIncludes(result.preflight, 'WORKER_USER = "orbit"');
    assertStringIncludes(result.unit, "proxy-ssh-identity");
});
Deno.test("asset metadata is written as a native resource, not only returned", async () => {
    const writes: unknown[] = [];
    const result = await behavior.methods.read.execute({
        node_id: 209,
        user: "candidate",
    }, {
        extensionFile: (path) => new URL(path, import.meta.url).pathname,
        writeResource: (...args) => Promise.resolve(writes.push(args)),
    });
    assertEquals(writes, [["assets", "assets", result]]);
    assertStringIncludes(result.preflight, 'WORKER_USER = "candidate"');
});
Deno.test("non209 or invalid targets and private fields fail before asset reads", async () => {
    let reads = 0;
    const reject = async (input: unknown) => {
        await assertRejects(() =>
            assets(input, () => {
                reads++;
                return Promise.resolve("");
            })
        );
    };
    for (const node_id of [1, 4, 9, 12, 13, 14, 17, 18, 202, 212, 0, -1]) {
        await reject({ node_id, user: "orbit" });
    }
    for (const user of ["root", "orbit-swamp-package", "bad\nname"]) {
        await reject({ node_id: 209, user });
    }
    await reject({ node_id: 209, user: "orbit", enrollment_token: "private" });
    assertEquals(reads, 0);
});
Deno.test("Node209 alone retains its approved proxy identity in shared unit policy", async () => {
    const result = await assets({ node_id: 209, user: "orbit" }, read);
    assertStringIncludes(
        result.unit,
        "LoadCredential=proxy-ssh-identity:/etc/orbit/node-proxy/id_ed25519",
    );
});

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
        const installed = await behavior.methods.read.execute({
            node_id: 209,
            user: "orbit",
        }, context);
        assertStringIncludes(installed.preflight, 'WORKER_USER = "orbit"');
        assertStringIncludes(installed.checker, "MINIMUM_MEMORY_BYTES");
        assertEquals(manifest.version, behavior.version);
    } finally {
        await Deno.remove(cache, { recursive: true });
    }
});
