import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { behavior } from "./assets.ts";

const context = {
    extensionFile: (name: string) => "/native-cache/" + name,
    writeResource: () => Promise.resolve(),
};

Deno.test("server files contain only public admitted inputs and native bundle paths", async () => {
    const value = await behavior.methods.read.execute({
        intent: { driver: "mysql", process_id: 42 },
    }, context);
    assertEquals(value, {
        directory: "/native-cache/scripts",
        intent_json: '{"driver":"mysql","process_id":42}',
    });
});
Deno.test("server result refuses missing completion and retains bounded action refusal", async () => {
    const result = behavior.methods.result.execute;
    assertEquals(
        await result({
            completed: true,
            steps: {
                run: { status: "succeeded", outputs: { exitCode: 0 } },
                skip: { status: "skipped" },
            },
        }, context),
        { stdout: '{"backend":"active"}\n', exitCode: 0 },
    );
    assertEquals(
        await result(
            { completed: false, steps: { run: { status: "skipped" } } },
            context,
        ),
        {
            stdout: '{"error_code":"database.server_create_outcome_unknown"}\n',
            exitCode: 1,
        },
    );
    assertEquals(
        await result({
            completed: false,
            steps: {
                run: {
                    status: "succeeded",
                    outputs: {
                        exitCode: 1,
                        stdout:
                            '{"error_code":"database.server_adoption_refused"}',
                    },
                },
            },
        }, context),
        {
            stdout: '{"error_code":"database.server_adoption_refused"}\n',
            exitCode: 1,
        },
    );
    assertEquals(
        await result({
            completed: false,
            steps: {
                run: {
                    status: "failed",
                    outputs: { stdout: "private diagnostic" },
                },
            },
        }, context),
        {
            stdout: '{"error_code":"database.server_create_outcome_unknown"}\n',
            exitCode: 1,
        },
    );
});

Deno.test("server result reads bounded native child output before assertion diagnostics", async () => {
    const value = await behavior.methods.result.execute({
        completed: false,
        steps: {
            initial: {
                status: "succeeded",
                outputs: {
                    result: {
                        exitCode: 1,
                        stdout:
                            '{"error_code":"database.server_adoption_refused"}',
                    },
                },
            },
            scrub: { status: "skipped" },
            assertion: {
                status: "failed",
                outputs: { stderr: "private diagnostic" },
            },
        },
    }, context);
    assertEquals(value, {
        stdout: '{"error_code":"database.server_adoption_refused"}\n',
        exitCode: 1,
    });
});

Deno.test("SQL user result uses its fixed fallback without exporting diagnostics", async () => {
    const value = await behavior.methods.result.execute({
        completed: false,
        failure_code: "database.user_create_outcome_unknown",
        steps: {
            apply: {
                status: "failed",
                outputs: { stdout: "private SQL diagnostic" },
            },
        },
    }, context);
    assertEquals(value, {
        stdout: '{"error_code":"database.user_create_outcome_unknown"}\n',
        exitCode: 1,
    });
});

Deno.test("every script a behavior resolves ships in the manifest and exists", async () => {
    const dir = new URL(".", import.meta.url);
    const manifest = await Deno.readTextFile(new URL("manifest.yaml", dir));
    const shipped = new Set([...manifest.matchAll(/^\s+- (scripts\/\S+)$/gm)].map((m) => m[1]));
    for await (const entry of Deno.readDir(dir)) {
        if (!entry.name.endsWith(".ts") || entry.name.endsWith("_test.ts")) continue;
        const source = await Deno.readTextFile(new URL(entry.name, dir));
        for (const [, path] of source.matchAll(/extensionFile\("([^"]+)"\)/g)) {
            assertEquals(shipped.has(path), true, `${entry.name} resolves ${path}, which the manifest does not ship`);
            await Deno.stat(new URL(path, dir));
        }
    }
});
