import { assertEquals } from "jsr:@std/assert@1.0.19";
async function closeServer(
    input: Record<string, unknown>,
    use: (
        fn: (material: Record<string, unknown>) => Promise<void>,
    ) => Promise<void>,
) {
    let result: Record<string, unknown> = {
        error_code: "database.server_command_failed",
    };
    await use(async (material) => {
        const action = async (phase: string) => {
            const child = new Deno.Command("python3", {
                args: [
                    "-B",
                    new URL(
                        "../database-server-commands/scripts/server_close.py",
                        import.meta.url,
                    ).pathname,
                    JSON.stringify(input),
                    phase,
                ],
                stdin: "piped",
                stdout: "piped",
                stderr: "piped",
            }).spawn();
            const writer = child.stdin.getWriter();
            await writer.write(
                new TextEncoder().encode(JSON.stringify(material)),
            );
            await writer.close();
            const reply = await child.output();
            const stdout = new TextDecoder().decode(reply.stdout);
            assertEquals(stdout.includes(String(material.password)), false);
            assertEquals(
                new TextDecoder().decode(reply.stderr).includes(
                    String(material.password),
                ),
                false,
            );
            return JSON.parse(stdout);
        };
        const probe = await action("probe");
        if (probe.error_code) {
            result = probe;
            return;
        }
        if (input.default_open) {
            for (const phase of ["close", "save"]) {
                const reply = await action(phase);
                if (reply.error_code) {
                    if (
                        probe.was_open && (await action("restore")).error_code
                    ) {
                        result = {
                            error_code: "database.server_restore_failed",
                            default_open: "unknown",
                        };
                    } else result = reply;
                    return;
                }
            }
            const disconnected = await action("disconnect");
            if (disconnected.error_code) {
                result = disconnected;
                return;
            }
        }
        result = { backend: "active" };
    });
    return result;
}
import { ValkeyClient } from "../../../../tests/_support/valkey_client.ts";

Deno.test({
    name:
        "disposable Valkey closes anonymous login and resumes with its original admin password",
    fn: async () => {
        const name = `orbit-database-close-test-${crypto.randomUUID()}`;
        const dir = await Deno.makeTempDir({ prefix: "database-close-" });
        const docker = async (...args: string[]) => {
            const result = await new Deno.Command("docker", {
                args,
                stdout: "piped",
                stderr: "null",
                // A fresh CI runner also downloads the image during container creation.
                signal: AbortSignal.timeout(args[0] === "run" ? 120000 : 15000),
            }).output();
            if (!result.success) {
                throw new Error(
                    `Disposable Valkey fixture ${
                        args[0]
                    } failed (exit ${result.code})`,
                );
            }
            return new TextDecoder().decode(result.stdout).trim();
        };
        try {
            await Deno.chmod(dir, 0o777);
            await Deno.writeTextFile(
                `${dir}/users.acl`,
                "user default on nopass ~* &* +@all\n",
                { mode: 0o666 },
            );
            await docker(
                "run",
                "--detach",
                "--rm",
                "--name",
                name,
                "--label",
                "orbit.test=database-server-close",
                "--user",
                `${Deno.uid()}:${Deno.gid()}`,
                "--publish",
                "127.0.0.1::6379",
                "--volume",
                `${dir}:/data`,
                "valkey/valkey:8.1",
                "valkey-server",
                "--aclfile",
                "/data/users.acl",
                "--save",
                "",
                "--appendonly",
                "no",
                "--protected-mode",
                "no",
            );
            const binding = await docker("port", name, "6379/tcp");
            const port = Number(binding.split(":").at(-1));
            const input = { host: "127.0.0.1", port, default_open: true };
            const password = `private-disposable-${crypto.randomUUID()}`;
            const use = (
                callback: (value: Record<string, unknown>) => Promise<void>,
            ) => callback({ password });
            for (let attempt = 0; attempt < 20; attempt++) {
                try {
                    const client = await ValkeyClient.connect(input.host, port);
                    await client.command("PING");
                    client.close();
                    break;
                } catch {
                    if (attempt === 19) {
                        throw new Error("Disposable Valkey not ready");
                    }
                    await new Promise((r) => setTimeout(r, 50));
                }
            }
            const existing = await ValkeyClient.connect(input.host, port);
            try {
                assertEquals(await closeServer(input, use), {
                    error_code: "database.server_in_use",
                });
            } finally {
                existing.close();
            }
            await Deno.rename(`${dir}/users.acl`, `${dir}/users.acl.saved`);
            await Deno.mkdir(`${dir}/users.acl`);
            try {
                assertEquals(await closeServer(input, use), {
                    error_code: "database.server_command_failed",
                });
                const anonymous = await ValkeyClient.connect(input.host, port);
                try {
                    assertEquals(await anonymous.command("PING"), "PONG");
                } finally {
                    anonymous.close();
                }
            } finally {
                await Deno.remove(`${dir}/users.acl`, { recursive: true });
                await Deno.rename(`${dir}/users.acl.saved`, `${dir}/users.acl`);
            }
            assertEquals(await closeServer(input, use), { backend: "active" });
            const anon = await ValkeyClient.connect(input.host, port);
            let refused = false;
            try {
                await anon.command("PING");
            } catch {
                refused = true;
            } finally {
                anon.close();
            }
            assertEquals(refused, true);
            assertEquals(await closeServer(input, use), { backend: "active" });
            assertEquals(
                (await Deno.readTextFile(`${dir}/users.acl`)).includes(
                    password,
                ),
                false,
            );
        } finally {
            await docker("rm", "--force", name).catch(() => {});
            await Deno.remove(dir, { recursive: true });
        }
    },
});
