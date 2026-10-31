import { parse } from "jsr:@std/yaml@1.0.12";

/** Exercise the actual inline action; this fixture is never a workflow behavior. */
export async function writeCommand(
    intent: { driver: string; owner?: string; path?: string },
    material: {
        sql: string;
        dsn: string;
        username: string | null;
        password: string;
    },
): Promise<Record<string, unknown>> {
    const workflow = parse(
        await Deno.readTextFile(
            new URL(
                "../../workflows/workflow-operation-database-write.yaml",
                import.meta.url,
            ),
        ),
    ) as {
        jobs: Array<{
            steps: Array<{ name: string; task: { inputs: { run: string } } }>;
        }>;
    };
    const step = workflow.jobs[0].steps.find((step) =>
        step.name === (intent.driver === "sqlite" ? "sqlite" : "sql")
    )!;
    const child = new Deno.Command("/bin/sh", {
        stdin: "piped",
        stdout: "piped",
        stderr: "piped",
        env: {
            ORBIT_DATABASE_DRIVER: intent.driver,
            ORBIT_DATABASE_OWNER: intent.owner ?? "",
            ORBIT_DATABASE_PATH: intent.path ?? "",
            ORBIT_DATABASE_MATERIAL: JSON.stringify(material),
        },
        signal: AbortSignal.timeout(45000),
    }).spawn();
    const writer = child.stdin.getWriter();
    await writer.write(new TextEncoder().encode(step.task.inputs.run));
    await writer.close();
    const output = await child.output();
    if (output.stderr.length) {
        throw new Error("Unexpected inline action diagnostic");
    }
    return JSON.parse(new TextDecoder().decode(output.stdout));
}

export async function pdoWrite(material: {
    sql: string;
    dsn: string;
    username: string | null;
    password: string;
}) {
    const result = await writeCommand(
        { driver: material.dsn.split(":")[0] },
        material,
    );
    if ("error_code" in result) return result;
    return { row_count: result.row_count, truncated: result.truncated };
}
