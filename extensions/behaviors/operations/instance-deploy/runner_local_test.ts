import { assertEquals } from "jsr:@std/assert@1.0.19";
import { runnerProbeProgram } from "./runner_local.ts";

const runner =
  "orbit-deploy-step:12:ed7cfb7a-64f3-496f-8722-e3fd1c06947d:before_activation.build";
async function probe(base: string) {
  const program = runnerProbeProgram +
    "\nimport json,sys\np=json.load(sys.stdin)\ntry: print(json.dumps(inspect_runner(p['proc'],p['runner'])))\nexcept BaseException: sys.exit(1)\n";
  const child = new Deno.Command("/usr/bin/python3", {
    args: ["-I", "-c", program],
    stdin: "piped",
    stdout: "piped",
    stderr: "piped",
  }).spawn();
  const writer = child.stdin.getWriter();
  await writer.write(
    new TextEncoder().encode(JSON.stringify({ proc: base, runner })),
  );
  await writer.close();
  const result = await child.output();
  return result.success
    ? JSON.parse(new TextDecoder().decode(result.stdout)).gone
    : "unconfirmed";
}
Deno.test("runner probe matches exact argv and inherited markers without treating unreadable state as absence", async () => {
  const base = await Deno.makeTempDir();
  try {
    await Deno.writeTextFile(
      base + "/mounts",
      "proc /proc proc rw,hidepid=0 0 0\n",
    );
    assertEquals(await probe(base), true);
    await Deno.mkdir(base + "/123");
    for (
      const [argv, env, expected] of [
        [`python3\0${runner}\0`, "", false],
        ["bash\0", `ORBIT_DEPLOY_STEP_RUNNER=${runner}\0`, false],
        [`prefix${runner}\0`, `OTHER=${runner}\0`, true],
        [runner, "", "unconfirmed"],
      ] as const
    ) {
      await Deno.writeTextFile(base + "/123/cmdline", argv);
      await Deno.writeTextFile(base + "/123/environ", env);
      assertEquals(await probe(base), expected);
    }
    await Deno.remove(base + "/123/environ");
    await Deno.writeTextFile(base + "/123/cmdline", "other\0");
    assertEquals(await probe(base), "unconfirmed");
    await Deno.writeTextFile(
      base + "/mounts",
      "proc /proc proc rw,hidepid=2 0 0\n",
    );
    assertEquals(await probe(base), "unconfirmed");
  } finally {
    await Deno.remove(base, { recursive: true });
  }
});
