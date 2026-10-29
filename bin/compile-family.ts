import { parse, stringify } from "jsr:@std/yaml@1.0.12";
import { canonical, compileFamily } from "../family/compiler.ts";

export const SWAMP_VERSION = "20261028.205235.0-sha.1602a617";

export function nativeEnvironment(): Record<string, string> {
  const env: Record<string, string> = {
    SWAMP_NO_UPDATE_CHECK: "1",
    SWAMP_NO_TELEMETRY: "1",
  };
  // Account authentication is required even for schema reads. Never inherit a
  // remote serve URL/token or the rest of a control-plane process environment.
  for (
    const key of [
      "HOME",
      "SWAMP_CONFIG_DIR",
      "SWAMP_HOME",
      "SWAMP_API_KEY",
      "SWAMP_API_KEY_FILE",
      "SWAMP_SIGNIN_TOKEN",
    ]
  ) {
    const value = Deno.env.get(key);
    if (value) env[key] = value;
  }
  return env;
}

export async function nativeSchema(binary: string): Promise<object> {
  const command = async (args: string[]) => {
    const output = await new Deno.Command(binary, {
      args,
      clearEnv: true,
      env: nativeEnvironment(),
      stdout: "piped",
      stderr: "piped",
    }).output();
    if (!output.success) throw new Error("Pinned Swamp CLI failed");
    return new TextDecoder().decode(output.stdout);
  };
  if (!(await command(["--version"])).trim().endsWith(SWAMP_VERSION)) {
    throw new Error(`Expected Swamp ${SWAMP_VERSION}`);
  }
  const schema = JSON.parse(
    await command(["workflow", "schema", "get", "--json"]),
  );
  return schema.workflow;
}

if (import.meta.main) {
  const [specPath, outputDirectory, binary] = Deno.args;
  if (!specPath || !outputDirectory || !binary || Deno.args.length !== 3) {
    throw new Error(
      "Usage: compile-family.ts SPEC OUTPUT_DIRECTORY SWAMP_BINARY",
    );
  }
  const schema = await nativeSchema(binary);
  const compiled = await compileFamily(
    parse(await Deno.readTextFile(specPath)),
    schema,
    await Deno.readTextFile(
      new URL("../family/catalogue.json", import.meta.url),
    ),
  );
  // Never overwrite an existing build or an active workflow. A release must
  // explicitly select and install the complete pair after review.
  await Deno.mkdir(outputDirectory);
  await Deno.writeTextFile(
    `${outputDirectory}/workflow.yaml`,
    stringify(compiled.workflow),
  );
  await Deno.writeTextFile(
    `${outputDirectory}/contract.json`,
    canonical(compiled.contract) + "\n",
  );
}
