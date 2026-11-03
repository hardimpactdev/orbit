import { dirname, join } from "node:path";
import { assertEquals } from "jsr:@std/assert@1.0.19";
export { ROOT } from "./native_harness.ts";

export function command(args: string[], cwd?: string, input?: string, env?: Record<string, string>) {
  const child = new Deno.Command(args[0], {
    args: args.slice(1),
    cwd,
    env,
    stdin: input === undefined ? "null" : "piped",
    stdout: "piped",
    stderr: "piped",
  }).spawn();
  return (async () => {
    if (input !== undefined) {
      const writer = child.stdin.getWriter();
      await writer.write(new TextEncoder().encode(input));
      await writer.close();
    }
    const result = await child.output();
    return {
      code: result.code,
      stdout: new TextDecoder().decode(result.stdout),
      stderr: new TextDecoder().decode(result.stderr),
    };
  })();
}
export function ok(result: { code: number; stdout: string; stderr: string }) {
  assertEquals(result.code, 0, result.stdout + result.stderr);
  return result.stdout.trim();
}
export async function scratch(prefix = "shape-") {
  const dir = await Deno.makeTempDir({ prefix });
  return {
    dir,
    async write(path: string, source: string) {
      const target = join(dir, path);
      await Deno.mkdir(dirname(target), { recursive: true });
      await Deno.writeTextFile(target, source);
    },
    async git(...args: string[]) {
      return ok(await command(["git", ...args], dir));
    },
    async commit(message: string) {
      await this.git("add", ".");
      await this.git("commit", "--quiet", "-m", message);
      return this.git("rev-parse", "HEAD");
    },
    async [Symbol.asyncDispose]() {
      await Deno.remove(dir, { recursive: true });
    },
  };
}
export async function gitFixture() {
  const repo = await scratch();
  await repo.git("init", "--quiet");
  await repo.git("config", "user.email", "shape@example.invalid");
  await repo.git("config", "user.name", "Shape fixture");
  return repo;
}

// Invoke the Python tool's public functions without keeping a second Python test runner.
export function pythonTool(name: string, body: string, args: string[] = [], env?: Record<string, string>) {
  const path = new URL(`../../bin/${name}.py`, import.meta.url).pathname;
  return command(
    [
      "python3",
      "-c",
      `import importlib.util,json,os,sys\nfrom pathlib import Path\nspec=importlib.util.spec_from_file_location('tool',sys.argv[1])\ntool=importlib.util.module_from_spec(spec)\nspec.loader.exec_module(tool)\n${body}`,
      path,
      ...args,
    ],
    undefined,
    undefined,
    env,
  );
}
