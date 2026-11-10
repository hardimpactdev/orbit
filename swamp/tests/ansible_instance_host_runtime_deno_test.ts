/** Exercise the shipped Instance host tasks against disposable local boundaries. */
import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import {
    fixture,
    hasAnsible,
    probe,
    program,
} from "./_support/instance_host.ts";

Deno.test({
    name:
        "the runtime installs the recipe's Deno ZIP and exposes its executable through current/bin",
    ignore: !hasAnsible,
    async fn() {
        const recipe = JSON.parse(Deno.readTextFileSync(
            new URL(
                "../../apps/gateway/resources/machine-software/recipe.json",
                import.meta.url,
            ),
        ));
        const part = recipe.instances.runtime.deno;
        const pin = recipe.tools[part.tool];
        const f = await fixture();
        try {
            // Match the upstream ZIP layout without downloading a release in this fixture.
            const source = `${f.folder}/deno`;
            Deno.writeTextFileSync(
                source,
                `#!/bin/sh\necho deno ${pin.version}\n`,
            );
            Deno.chmodSync(source, 0o755);
            const archive = `${f.folder}/artifacts/deno.zip`;
            const zipped = new Deno.Command("zip", {
                args: ["-j", archive, source],
            }).outputSync();
            assert(zipped.success, new TextDecoder().decode(zipped.stderr));
            const digest = new Uint8Array(
                await crypto.subtle.digest(
                    "SHA-256",
                    Deno.readFileSync(archive),
                ),
            );
            const sha256 = Array.from(
                digest,
                (b) => b.toString(16).padStart(2, "0"),
            ).join("");
            f.write({
                ...f.instances,
                folders: {
                    ...f.instances.folders,
                    runtime: {
                        ...f.instances.folders.runtime,
                        parts: [...f.instances.folders.runtime.parts, {
                            name: "deno",
                            version: pin.version,
                            url: "https://example.test/deno.zip",
                            archive: "zip",
                            sha256,
                            bin: part.bin,
                        }],
                    },
                },
            });

            const result = await f.run();
            assert(result.ok, result.output);
            const executable = `${f.folder}/opt/orbit-runtime/current/bin/deno`;
            assertEquals(Deno.readLinkSync(executable), "../deno/deno");
            const version = new Deno.Command(executable, {
                args: ["--version"],
            }).outputSync();
            assert(version.success);
            assertEquals(
                new TextDecoder().decode(version.stdout).trim(),
                `deno ${pin.version}`,
            );
            // Everyone can traverse the runtime and execute Deno, including agent and orbit.
            assertEquals(Deno.statSync(executable).mode! & 0o555, 0o555);
        } finally {
            f.cleanup();
        }
    },
});

