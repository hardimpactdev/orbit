import { assertEquals, assertMatch } from "jsr:@std/assert@1.0.19";
import { cleanupProgram } from "./cleanup_program.ts";

export function exists(path: string) {
  try {
    Deno.lstatSync(path);
    return true;
  } catch (e) {
    if (e instanceof Deno.errors.NotFound) return false;
    throw e;
  }
}
export async function cleanupFixture() {
  const base = await Deno.makeTempDir({ prefix: "documents-gate-" });
  const root = `${base}/run`, directory = `${root}/project-documents`;
  const lock = `${directory}/execution.lock`,
    generation = `${directory}/generation.json`,
    permit = `${directory}/permit.json`;
  const source = cleanupProgram.replace(
    "ROOT=pathlib.Path('/run/orbit')",
    `ROOT=pathlib.Path(${JSON.stringify(root)})`,
  )
    .replace("ROOT_UID=0", "ROOT_UID=os.getuid()").replace(
      "ROOT_GID=0",
      "ROOT_GID=os.getgid()",
    )
    .replace("pwd.getpwnam('orbit')", "pwd.getpwuid(os.geteuid())");
  const spawn = (program = source) =>
    new Deno.Command("python3", {
      args: ["-I", "-c", program],
      stdin: "piped",
      stdout: "piped",
      stderr: "piped",
    }).spawn();
  const invoke = async (program = source) => {
    const child = spawn(program);
    const writer = child.stdin.getWriter();
    await writer.write(new TextEncoder().encode("{}"));
    await writer.close();
    const timer = setTimeout(() => child.kill("SIGKILL"), 5000);
    try {
      const result = await child.output();
      assertEquals(
        result.code,
        0,
        new TextDecoder().decode(result.stderr),
      );
      assertEquals(new TextDecoder().decode(result.stderr), "");
      return JSON.parse(new TextDecoder().decode(result.stdout));
    } finally {
      clearTimeout(timer);
    }
  };
  const start = async () => {
    const result = await invoke();
    assertMatch(result.generation, /^[a-f0-9]{64}$/);
    return result;
  };
  const state = () => JSON.parse(Deno.readTextFileSync(generation));
  const arm = () => {
    const value = {
      ...state(),
      report_id: "a".repeat(64),
      report_sha256: "b".repeat(64),
    };
    Deno.writeTextFileSync(generation, JSON.stringify(value));
    Deno.writeTextFileSync(permit, JSON.stringify(value));
    Deno.chmodSync(permit, 0o600);
  };
  const refused = async () =>
    assertEquals(await invoke(), {
      error_code: "project_documents.cleanup_state_unavailable",
    });
  return {
    base,
    root,
    directory,
    lock,
    generation,
    permit,
    source,
    spawn,
    invoke,
    start,
    state,
    arm,
    refused,
    async [Symbol.asyncDispose]() {
      await Deno.remove(base, { recursive: true });
    },
  };
}
