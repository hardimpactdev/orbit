import { assertEquals, assertMatch } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { behavior } from "./assets.ts";

Deno.test("DNS grace resolves its shipped asset before the placed wait", async () => {
    const directory = new URL("./", import.meta.url);
    const manifest = parse(
        Deno.readTextFileSync(new URL("manifest.yaml", directory)),
    ) as {
        version: string;
        additionalFiles: string[];
    };
    let asset = "";
    let output: { grace_script: string } | undefined;
    await behavior.methods.dnsGraceAssets.execute({}, {
        extensionFile(path: string) {
            asset = path;
            return "/worker/cache/" + path;
        },
        writeResource(spec: string, name: string, value: unknown) {
            assertEquals([spec, name], [
                "dns_grace_assets",
                "dns_grace_assets",
            ]);
            output = behavior.resources.dns_grace_assets.schema.parse(value);
            return Promise.resolve({});
        },
    });
    assertEquals(manifest.version, behavior.version);
    assertEquals(manifest.additionalFiles.includes(asset), true);
    // Domain parity: the 30-second DNS TTL needs its original extra second.
    // The general shape rule also allows shorter sleeps, so it cannot own this assertion.
    assertMatch(
        Deno.readTextFileSync(new URL(asset, directory)),
        /^timeout 35 \/usr\/bin\/sleep 31$/m,
    );
    assertEquals(output, {
        grace_script: "/worker/cache/scripts/route_dns_grace.sh",
    });
    const workflow = parse(Deno.readTextFileSync(
        new URL(
            "../../../../../workflows/workflow-route-dns-grace.yaml",
            directory,
        ),
    )) as {
        jobs: {
            steps: {
                name: string;
                target: string;
                task: {
                    methodName: string;
                    inputs: { env?: Record<string, string> };
                };
                dependsOn?: unknown[];
            }[];
        }[];
    };
    const [assets, wait] = workflow.jobs[0].steps;
    assertEquals([assets.target, wait.target], [
        "orbit-node-209",
        "orbit-node-209",
    ]);
    assertEquals(assets.task.methodName, "dnsGraceAssets");
    assertEquals(
        wait.task.inputs.env?.ORBIT_GRACE_SCRIPT,
        "${{ steps.assets.outputs.grace_script }}",
    );
    assertEquals(wait.dependsOn, [{
        step: "assets",
        condition: { type: "succeeded" },
    }]);
});

Deno.test("DNS grace reports its phase and budget only on timeout, preserving exit status", async () => {
    const directory = Deno.makeTempDirSync();
    const script = new URL("scripts/route_dns_grace.sh", import.meta.url).pathname;
    try {
        Deno.writeTextFileSync(`${directory}/timeout`, `#!/bin/bash
if [ "$1" = --verbose ]; then shift; fi
[ "$*" = "35 /usr/bin/sleep 31" ] || exit 97
exit "$ORBIT_TEST_TIMEOUT_STATUS"
`, { mode: 0o700 });
        for (const status of [0, 1, 124]) {
            const result = await new Deno.Command("bash", {
                args: [script],
                env: {
                    PATH: `${directory}:${Deno.env.get("PATH")}`,
                    ORBIT_TEST_TIMEOUT_STATUS: String(status),
                },
                stdout: "piped",
                stderr: "piped",
            }).output();
            assertEquals(result.code, status);
            assertEquals(new TextDecoder().decode(result.stdout), "");
            assertEquals(new TextDecoder().decode(result.stderr), status === 124
                ? "route-dns-grace timed out after 35 seconds\n"
                : "");
        }
    } finally {
        Deno.removeSync(directory, { recursive: true });
    }
});
