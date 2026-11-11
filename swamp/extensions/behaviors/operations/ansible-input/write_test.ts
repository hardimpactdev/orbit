import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { behavior } from "./write.ts";

Deno.test("the full intent reaches a private file beyond the environment size limit", async () => {
    const id = crypto.randomUUID();
    const folder = `/tmp/orbit-ansible-${id}`;
    const intent = { node_id: 12, dashboard: "{{ literal }}".repeat(20000) };
    const rows: unknown[] = [];
    try {
        const result = await behavior.methods.write.execute({
            intent,
            path: `${folder}/intent.json`,
        }, {
            extensionFile: (name) =>
                new URL(`../../../../${name}`, import.meta.url).pathname,
            writeResource: (_spec, _name, value) => {
                rows.push(value);
                return Promise.resolve(null);
            },
        });
        assertEquals(JSON.parse(await Deno.readTextFile(result.path)), intent);
        assertEquals((await Deno.stat(folder)).mode! & 0o777, 0o700);
        assertEquals((await Deno.stat(result.path)).mode! & 0o777, 0o600);
        assertEquals(result.requirements, "ansible-core==2.20.11\n");
        assertEquals(
            result.assets,
            new URL("../../../../ansible", import.meta.url).pathname,
        );
        assertEquals(rows, [result]);
    } finally {
        await Deno.remove(folder, { recursive: true });
    }
});

Deno.test("an intent write refuses paths outside its run folder", async () => {
    await assertRejects(() =>
        behavior.methods.write.execute({ intent: {}, path: "/tmp/elsewhere" }, {
            extensionFile: () => "",
            writeResource: () => Promise.resolve(null),
        })
    );
});

Deno.test("the fleet reboot finalizer publishes only its redacted public evidence", async () => {
    const folder = `/tmp/orbit-ansible-${crypto.randomUUID()}`;
    await Deno.mkdir(folder, { mode: 0o700 });
    const rows: unknown[] = [];
    try {
        await Deno.writeTextFile(`${folder}/intent.json`, JSON.stringify({ nodes: { "9": { name: "fixture", defer: null } } }));
        await Deno.writeTextFile(`${folder}/drain-9.json`, JSON.stringify({ stats: { node: { unreachable: 1 } }, plays: [{ tasks: [{ hosts: {
            node: { unreachable: true, msg: "ssh: connect to host 192.0.2.9 port 22: Connection timed out\nAuthorization: Bearer fixture-private-credential" },
        } }] }] }));
        const result = await behavior.methods.fleetRebootResult.execute({ folder, node_id: 9, position: 0 }, {
            extensionFile: () => "",
            writeResource: (_spec, _name, value) => { rows.push(value); return Promise.resolve(null); },
        });
        assertEquals(rows, [result]);
        const publicResult = JSON.parse(result.stdout);
        assertEquals([publicResult.node_id, publicResult.outcome, publicResult.error_code], [9, "failed", "fleet.node_unreachable"]);
        assertEquals(publicResult.error_detail.includes("fixture-private-credential"), false);
        assertEquals(publicResult.error_detail.includes("Connection timed out"), true);
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("the fleet reboot finalizer reports a fixed error when private callback inputs cannot be parsed", async () => {
    const folder = `/tmp/orbit-ansible-${crypto.randomUUID()}`;
    await Deno.mkdir(folder, { mode: 0o700 });
    try {
        await Deno.writeTextFile(`${folder}/intent.json`, 'invalid password=fixture-private-credential');
        const error = await assertRejects(() => behavior.methods.fleetRebootResult.execute({ folder, node_id: 9, position: 0 }, {
            extensionFile: () => "", writeResource: () => Promise.resolve(null),
        }), Error, "fleet.reboot_record_failed");
        assertEquals(error.message, "fleet.reboot_record_failed");
    } finally { await Deno.remove(folder, { recursive: true }); }
});
