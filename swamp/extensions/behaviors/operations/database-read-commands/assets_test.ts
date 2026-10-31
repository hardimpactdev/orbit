import { assertEquals } from "jsr:@std/assert@1.0.19";
import { behavior } from "./assets.ts";

const context = () => {
    const saved: unknown[] = [];
    return {
        saved,
        extensionFile: (name: string) => "/native/bundle/" + name,
        writeResource: (_spec: string, _name: string, value: unknown) => {
            saved.push(value);
            return Promise.resolve(value);
        },
    };
};

Deno.test("read assets describe only shipped files and public inputs", async () => {
    const ctx = context();
    const intent = { driver: "sqlite", path: "/tmp/fixture.sqlite" };
    assertEquals(await behavior.methods.read.execute({ intent }, ctx), {
        directory: "/native/bundle/scripts",
        intent_json: JSON.stringify(intent),
    });
});

Deno.test("read result decoder preserves bounded typed rows and metadata", async () => {
    for (
        const result of [
            {
                columns: ["value"],
                rows: [{ value: 47 }],
                row_count: 1,
                truncated: false,
            },
            { tables: ["proof"] },
            { tables: [{ name: "proof", columns: [] }] },
            { table: "proof", columns: [] },
        ]
    ) {
        const ctx = context();
        const reply = await behavior.methods.result.execute({
            stdout: JSON.stringify(result),
            exit_code: 0,
        }, ctx);
        assertEquals(JSON.parse(reply.stdout), result);
        assertEquals(reply.exitCode, 0);
        assertEquals(ctx.saved, [reply]);
    }
});

Deno.test("read result decoder fails missing, invalid, oversized and nonzero observations", async () => {
    for (
        const [stdout, exit_code] of [
            ["", 0],
            ["invalid", 0],
            [JSON.stringify({ tables: ["unconfirmed"] }), 255],
            [JSON.stringify({ tables: [], private: "private-diagnostic" }), 0],
            [JSON.stringify({ error_code: "private-diagnostic" }), 1],
            [JSON.stringify({ tables: ["x".repeat(15000)] }), 0],
        ] as const
    ) {
        const reply = await behavior.methods.result.execute({
            stdout,
            exit_code,
        }, context());
        assertEquals(JSON.parse(reply.stdout), {
            error_code: "database.query_failed",
        });
        assertEquals(reply.exitCode, 1);
    }
});

Deno.test("read result decoder preserves only the declared domain refusals", async () => {
    for (
        const error_code of [
            "database.query_failed",
            "database.table_missing",
            "database.sqlite_unreadable",
        ]
    ) {
        const reply = await behavior.methods.result.execute({
            stdout: JSON.stringify({ error_code }),
            exit_code: 1,
        }, context());
        assertEquals(JSON.parse(reply.stdout), { error_code });
        assertEquals(reply.exitCode, 1);
    }
});
