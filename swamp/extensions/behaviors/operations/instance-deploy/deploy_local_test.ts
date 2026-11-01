import { assertEquals } from "jsr:@std/assert@1.0.19";
import { fromFileUrl } from "jsr:@std/path@1.1.2";

Deno.test("native SSH development actions preserve checkout refusals, locks and private one-command execution", async () => {
    const result = await new Deno.Command("python3", {
        args: ["-m", "unittest", "scripts_test", "-q"],
        cwd: fromFileUrl(new URL("../development-deploy/", import.meta.url)),
        stdout: "piped",
        stderr: "piped",
    }).output();
    assertEquals(
        result.code,
        0,
        new TextDecoder().decode(result.stdout) +
            new TextDecoder().decode(result.stderr),
    );
});

Deno.test("F9 deployment phases preserve source, warning, private-command and deadline parity", async () => {
    const result = await new Deno.Command("python3", {
        args: [
            "-m",
            "unittest",
            "discover",
            "-s",
            "phases",
            "-p",
            "*_test.py",
            "-q",
        ],
        cwd: fromFileUrl(new URL("../development-deploy/", import.meta.url)),
        stdout: "piped",
        stderr: "piped",
    }).output();
    assertEquals(
        result.code,
        0,
        new TextDecoder().decode(result.stdout) +
            new TextDecoder().decode(result.stderr),
    );
});
