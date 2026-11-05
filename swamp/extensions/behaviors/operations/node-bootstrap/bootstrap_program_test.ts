import { assertEquals } from "jsr:@std/assert@1.0.19";
import { BootstrapInstallProgram } from "./bootstrap_program.ts";

async function python(program: string, test: string) {
    const source =
        "import json,sys\nns={'__name__':'bootstrap_test'}\nexec(json.load(sys.stdin),ns)\n" +
        test;
    const child = new Deno.Command("/usr/bin/python3", {
        args: ["-c", source],
        stdin: "piped",
        stdout: "piped",
        stderr: "piped",
    }).spawn();
    const writer = child.stdin.getWriter();
    await writer.write(new TextEncoder().encode(JSON.stringify(program)));
    await writer.close();
    const output = await child.output();
    assertEquals(output.success, true, new TextDecoder().decode(output.stderr));
}
Deno.test("observer installer is syntactically valid without executing a candidate binary", async () => {
    const child = new Deno.Command("/usr/bin/python3", {
        args: [
            "-c",
            "import sys;compile(sys.stdin.read(),'bootstrap-observer','exec')",
        ],
        stdin: "piped",
        stdout: "piped",
        stderr: "piped",
    }).spawn();
    const writer = child.stdin.getWriter();
    await writer.write(new TextEncoder().encode(BootstrapInstallProgram));
    await writer.close();
    assertEquals((await child.output()).success, true);
    assertEquals(BootstrapInstallProgram.includes("subprocess"), false);
});
