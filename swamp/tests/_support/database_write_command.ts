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
      steps: Array<{ name: string; task: { inputs: { argv: string[] } } }>;
    }>;
  };
  const step = workflow.jobs[0].steps.find((step) =>
    step.name === (intent.driver === "sqlite" ? "sqlite" : "sql")
  )!;
  const substitutions: Record<string, string> = {
    "${{ inputs.intent.driver }}": intent.driver,
    '${{ has(inputs.intent.owner) ? inputs.intent.owner : "" }}':
      intent.owner ?? "",
    '${{ has(inputs.intent.path) ? inputs.intent.path : "" }}': intent.path ??
      "",
  };
  const argv = step.task.inputs.argv.map((value) =>
    substitutions[value] ?? value
  );
  const child = new Deno.Command(argv[0], {
    args: argv.slice(1),
    stdin: "piped",
    stdout: "piped",
    stderr: "piped",
    signal: AbortSignal.timeout(45000),
  }).spawn();
  const writer = child.stdin.getWriter();
  await writer.write(new TextEncoder().encode(JSON.stringify(material)));
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

/** Fixture adapter: existing effect assertions now exercise the actual native action. */
export async function writeDatabase(
  intent: { driver: string; owner?: string; path?: string },
  use: (
    consume: (
      material: {
        sql: string;
        dsn: string;
        username: string | null;
        password: string;
      },
    ) => Promise<void>,
  ) => Promise<void>,
): Promise<Record<string, unknown>> {
  let result: Record<string, unknown> = {};
  await use(async (material) => {
    result = await writeCommand(intent, material);
  });
  return result;
}
