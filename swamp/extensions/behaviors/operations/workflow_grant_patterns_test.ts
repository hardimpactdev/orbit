import { assertEquals, assertStringIncludes } from "jsr:@std/assert@1.0.19";

Deno.test("release grants: unknown", async () => {
    const directory = await Deno.makeTempDir({ prefix: "orbit-grant-coverage-" });
    try {
        await Deno.mkdir(`${directory}/config`);
        for (const name of ["gateway-workflow-grants.json", "serve-grants.json"]) {
            await Deno.copyFile(new URL(`../../../config/${name}`, import.meta.url), `${directory}/config/${name}`);
        }
        await Deno.writeTextFile(`${directory}/config/extra-grants.json`, '{"grants":[]}');
        const result = await new Deno.Command("python3", {
            args: [
                "-c",
                `
import importlib.util, sys
from pathlib import Path
spec = importlib.util.spec_from_file_location("grants", sys.argv[1])
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)
gate.render_grants(Path(sys.argv[2]), Path(sys.argv[2]) / "rendered")
`,
                new URL("../../../bin/check-grant-patterns.py", import.meta.url).pathname,
                directory,
            ],
            stdout: "piped",
            stderr: "piped",
            signal: AbortSignal.timeout(30_000),
        }).output();
        assertEquals(result.success, false);
        assertStringIncludes(new TextDecoder().decode(result.stderr), "complete release-renderer coverage");
    } finally {
        await Deno.remove(directory, { recursive: true });
    }
});
