import { assertEquals, assertFalse } from "jsr:@std/assert@1.0.19";
import { fromFileUrl } from "jsr:@std/path@1.1.2";
import { behavior } from "./assets.ts";

Deno.test("Create and destroy deliver the sole dotenv reader inside the isolated runner", async () => {
    const directory = await Deno.makeTempDir();
    try {
        const secret = "first-private-fixture\nsecond-private-fixture";
        await Deno.writeTextFile(`${directory}/.env`, `APP_KEY="${secret}"\n`);
        await Deno.chmod(`${directory}/.env`, 0o600);
        const command = `printf '%s\\n' '${secret}' >&2; exit 7`;
        const digest = [
            ...new Uint8Array(
                await crypto.subtle.digest(
                    "SHA-256",
                    new TextEncoder().encode(command),
                ),
            ),
        ].map((byte) => byte.toString(16).padStart(2, "0")).join("");
        const context = {
            extensionFile: (path: string) =>
                fromFileUrl(new URL(path, import.meta.url)),
            writeResource: async () => ({}),
        };
        const assets = [
            await behavior.methods.create_assets.execute(
                { intent: {} },
                context,
            ),
            await behavior.methods.destroy_assets.execute({
                intent: {},
                teardown: {},
            }, context),
        ];
        for (const { runner } of assets) {
            const child = new Deno.Command("/usr/bin/python3", {
                args: ["-I", "-c", runner],
                stdin: "piped",
                stdout: "piped",
                stderr: "piped",
            }).spawn();
            const writer = child.stdin.getWriter();
            await writer.write(new TextEncoder().encode(JSON.stringify({
                command,
                sha256: digest,
                directory,
                instance_id: 17,
                instance_name: "proof",
                seed_path: "",
                seed_commit: "",
            })));
            await writer.close();
            const result = await child.output();
            const stdout = new TextDecoder().decode(result.stdout);
            const stderr = new TextDecoder().decode(result.stderr);
            assertEquals(result.code, 7, stderr);
            assertEquals(JSON.parse(stdout), {
                exitCode: 7,
                stderr: "[REDACTED]\n",
            });
            assertFalse(stdout.includes("private-fixture"));
            assertEquals(stderr, "");
        }
    } finally {
        await Deno.remove(directory, { recursive: true });
    }
});
