import { fileURLToPath } from "node:url";

/**
 * Disposable effect-test adapter: runs the shipped database:create phases (stage, create, check,
 * unstage) in chart order against a real server, with each secret on stdin as the workflow sends it.
 * Only the stage directory moves to a private temporary directory, because tests run without root.
 */
export async function createDatabase(
    raw: unknown,
    use: (
        consume: (material: Record<string, unknown>) => Promise<void>,
    ) => Promise<void>,
): Promise<Record<string, unknown>> {
    const intent = {
        ...(raw as Record<string, unknown>),
        stage: "/run/orbit-database-create-1",
    };
    const directory = fileURLToPath(
        new URL(
            "../../extensions/behaviors/operations/database-server-commands/scripts/",
            import.meta.url,
        ),
    );
    const stage = await Deno.makeTempDir({ prefix: "orbit-database-create-" });
    const program = [
        "import sys",
        `sys.path.insert(0, ${JSON.stringify(directory)})`,
        "import database_create",
        `database_create.stage_path = lambda intent: ${JSON.stringify(stage)}`,
        "sys.argv = ['database_create', *sys.argv[1:]]",
        "database_create.main()",
    ].join("\n");
    let result: Record<string, unknown> = { backend: "active" };
    try {
        await use(async (material) => {
            const owner = JSON.stringify(material.payload);
            const phases: Array<[string, string]> = [
                ["stage", owner],
                ["create", String(material.password)],
                ["check", owner],
            ];
            for (const [phase, secret] of phases) {
                const child = new Deno.Command("/usr/bin/python3", {
                    args: ["-B", "-c", program, JSON.stringify(intent), phase],
                    stdin: "piped",
                    stdout: "piped",
                    stderr: "piped",
                    signal: AbortSignal.timeout(120000),
                }).spawn();
                const writer = child.stdin.getWriter();
                await writer.write(new TextEncoder().encode(secret));
                await writer.close();
                const output = await child.output();
                if (output.stderr.length) {
                    throw new Error("Unexpected database creation diagnostic");
                }
                if (!output.success) {
                    result = JSON.parse(
                        new TextDecoder().decode(output.stdout),
                    );
                    return;
                }
            }
        });
    } finally {
        await Deno.remove(stage, { recursive: true });
    }
    return result;
}
