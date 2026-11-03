import { assert, assertEquals } from "jsr:@std/assert@1.0.19";

Deno.test("parallel setup CLI observations preserve the whole fixture state and call log", async () => {
    const directory = await Deno.makeTempDir({ prefix: "orbit-bw-setup-" });
    try {
        const script = `${directory}/bw.ts`, path = `${directory}/state.json`;
        await Deno.copyFile(
            new URL(
                "../../../../tests/_support/bw_setup_cli.ts",
                import.meta.url,
            ),
            script,
        );
        const organizations = [{ id: "org", name: "Orbit" }];
        const collections = [{
            id: "collection",
            organizationId: "org",
            name: "Disposable",
        }];
        await Deno.writeTextFile(
            path,
            JSON.stringify({
                commands: [],
                organizations,
                collections,
                padding: "x".repeat(1024 * 1024),
            }),
        );
        const commands = Array.from(
            { length: 12 },
            (_, index) =>
                [["status"], ["list", "organizations"], [
                    "list",
                    "collections",
                ]][index % 3],
        );
        const results = await Promise.all(
            commands.map((args) =>
                new Deno.Command(Deno.execPath(), {
                    args: [
                        "run",
                        "-A",
                        "--quiet",
                        "--no-config",
                        "--no-lock",
                        script,
                        "--yes",
                        "--package=@bitwarden/cli@2026.6.0",
                        "bw",
                        ...args,
                    ],
                    env: {
                        ORBIT_BW_FIXTURE_STATE: path,
                        BW_SESSION: "fixture-session",
                        FIXTURE_SESSION: "fixture-session",
                    },
                    stdout: "piped",
                    stderr: "piped",
                }).output()
            ),
        );
        for (const result of results) {
            assert(result.success, new TextDecoder().decode(result.stderr));
        }
        const state = JSON.parse(await Deno.readTextFile(path));
        assertEquals(
            state.commands.map((value: string[]) => JSON.stringify(value))
                .sort(),
            commands.map((value) => JSON.stringify(value)).sort(),
        );
        assertEquals(state.organizations, organizations);
        assertEquals(state.collections, collections);
    } finally {
        await Deno.remove(directory, { recursive: true });
    }
});
