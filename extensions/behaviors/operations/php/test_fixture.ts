import { assertEquals } from "jsr:@std/assert@1.0.19";

/** Execute the actual fixed program in a private filesystem; fake only external commands. */
export async function pythonFixture(
  program: string,
  fixture: string,
): Promise<void> {
  const child = new Deno.Command("/usr/bin/python3", {
    args: [
      "-I",
      "-c",
      "import json,sys\np=json.load(sys.stdin);n={'__name__':'fixture'};exec(p['program'],n);exec(p['fixture'],n)",
    ],
    stdin: "piped",
    stdout: "piped",
    stderr: "piped",
  }).spawn();
  const writer = child.stdin.getWriter();
  await writer.write(
    new TextEncoder().encode(JSON.stringify({ program, fixture })),
  );
  await writer.close();
  const output = await child.output();
  assertEquals(output.success, true, new TextDecoder().decode(output.stderr));
  assertEquals(new TextDecoder().decode(output.stdout), "ok\n");
}
