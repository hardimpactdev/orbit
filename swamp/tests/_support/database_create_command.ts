import { parse } from "jsr:@std/yaml@1.0.12";
import { fileURLToPath } from "node:url";
import { behavior } from "../../extensions/behaviors/operations/database-server-commands/create_assets.ts";

/** Disposable effect-test adapter reads the actual workflow action order and invokes shipped scripts. */
export async function createDatabase(
    raw: unknown,
    use: (
        consume: (material: Record<string, unknown>) => Promise<void>,
    ) => Promise<void>,
): Promise<Record<string, unknown>> {
    const intent = raw as Record<string, unknown>;
    const directory = fileURLToPath(
        new URL(
            "../../extensions/behaviors/operations/database-server-commands/scripts/",
            import.meta.url,
        ),
    );
    const assets = await behavior.methods.read.execute({ intent }, {
        extensionFile: (name) => directory + name.split("/").at(-1),
        writeResource: () => Promise.resolve({}),
    });
    const workflow = parse(
        await Deno.readTextFile(
            new URL(
                "../../workflows/workflow-operation-database-create.yaml",
                import.meta.url,
            ),
        ),
    ) as {
        jobs: Array<
            {
                steps: Array<
                    {
                        guard?: string;
                        forEach?: { in: string };
                        task: {
                            type: string;
                            inputs?: {
                                action?: string;
                                item?: Record<string, unknown>;
                            };
                        };
                    }
                >;
            }
        >;
    };
    let result: Record<string, unknown> = { backend: "active" };
    await use(async (material) => {
        for (const step of workflow.jobs[0].steps) {
            if (step.task.type !== "workflow") continue;
            const branch = step.guard!.match(
                /inputs.intent.driver (==|!=) "([^"]+)"/,
            )!;
            if (
                branch[1] === "=="
                    ? intent.driver === branch[2]
                    : intent.driver !== branch[2]
            ) continue;
            if (
                step.guard?.includes("inputs.intent.test_prefix == null") &&
                intent.test_prefix === null
            ) continue;
            const selected = step.forEach
                ? intent.databases as string[]
                : [null];
            for (const database of selected) {
                const item = JSON.stringify(
                    Object.fromEntries(
                        Object.entries(step.task.inputs!.item ?? {}).map((
                            [key, value],
                        ) => [
                            key,
                            value === "${{ self.selected }}"
                                ? database
                                : value === "${{ inputs.intent.test_prefix }}"
                                ? intent.test_prefix
                                : value,
                        ]),
                    ),
                );
                const child = new Deno.Command("/usr/bin/python3", {
                    args: [
                        "-B",
                        directory + step.task.inputs!.action + ".py",
                        assets.intent_json,
                        item,
                    ],
                    stdin: "piped",
                    stdout: "piped",
                    stderr: "piped",
                    signal: AbortSignal.timeout(120000),
                }).spawn();
                const writer = child.stdin.getWriter();
                await writer.write(
                    new TextEncoder().encode(JSON.stringify(material)),
                );
                await writer.close();
                const output = await child.output();
                if (output.stderr.length) {
                    throw new Error("Unexpected database creation diagnostic");
                }
                const reply = JSON.parse(
                    new TextDecoder().decode(output.stdout),
                );
                if (!output.success) {
                    result = reply;
                    return;
                }
            }
        }
    });
    return result;
}
