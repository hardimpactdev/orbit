import { assertEquals } from "jsr:@std/assert@1";
import { fromFileUrl } from "jsr:@std/path@1";
Deno.test("repository analysis actions retain strict reports and immutable provenance", async () => {
    const reply = await new Deno.Command("python3", {
        args: [
            "-m",
            "unittest",
            "actions_test.AnalysisActions",
            "actions_test.AnalysisReports",
            "-v",
        ],
        cwd: fromFileUrl(new URL("../task-repository/", import.meta.url)),
        stdout: "piped",
        stderr: "piped",
    }).output();
    assertEquals(
        reply.code,
        0,
        new TextDecoder().decode(reply.stdout) +
            new TextDecoder().decode(reply.stderr),
    );
});
