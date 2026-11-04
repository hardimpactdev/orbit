import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { behavior } from "./public.ts";

const result = {
    wireguard_public_key: "A".repeat(43) + "=",
    ssh_host_key: "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAA",
    observer_digest: "a".repeat(64),
    revision: "b".repeat(64),
};
const context = (rows: unknown[]) => ({
    writeResource: (_spec: string, _name: string, value: unknown) => {
        rows.push(value);
        return Promise.resolve(null);
    },
});

Deno.test("the public result of the identity phase is read and recorded", async () => {
    const folder = `/tmp/orbit-ansible-${crypto.randomUUID()}`;
    const rows: unknown[] = [];
    await Deno.mkdir(folder, { mode: 0o700 });
    try {
        await Deno.writeTextFile(`${folder}/public.json`, JSON.stringify(result));
        const value = await behavior.methods.read.execute({ path: `${folder}/public.json` }, context(rows));
        assertEquals(value, result);
        assertEquals(rows, [result]);
    } finally {
        await Deno.remove(folder, { recursive: true });
    }
});

Deno.test("a result with a private field or a bad key is refused", async () => {
    const folder = `/tmp/orbit-ansible-${crypto.randomUUID()}`;
    await Deno.mkdir(folder, { mode: 0o700 });
    try {
        for (const bad of [{ ...result, private_key: "secret" }, { ...result, wireguard_public_key: "short" }]) {
            await Deno.writeTextFile(`${folder}/public.json`, JSON.stringify(bad));
            await assertRejects(() => behavior.methods.read.execute({ path: `${folder}/public.json` }, context([])));
        }
    } finally {
        await Deno.remove(folder, { recursive: true });
    }
});

Deno.test("a read refuses paths outside a run folder", async () => {
    await assertRejects(() => behavior.methods.read.execute({ path: "/tmp/public.json" }, context([])));
});
