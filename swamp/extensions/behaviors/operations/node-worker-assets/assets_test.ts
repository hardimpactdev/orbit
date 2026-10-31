import {
    assertEquals,
    assertRejects,
    assertStringIncludes,
} from "jsr:@std/assert@1";
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
