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
        "a static PHP build is installed as php/<version> with no link in bin, and a build whose extensions differ is refused",
    ignore: !hasAnsible,
    async fn() {
        const f = await fixture();
        try {
            const install = async (frankenphp: string[]) => {
                const name = `static-php-${frankenphp.length}.tar.gz`;
                const sha = await program(f.folder, name, {
                    "bin/php": probe("8.5.12", ["Core", "pdo_pgsql"]),
                    "bin/frankenphp": probe("8.5.12", frankenphp),
                });
                f.write({
                    ...f.instances,
                    folders: {
                        ...f.instances.folders,
                        runtime: {
                            ...f.instances.folders.runtime,
                            version: `44444444444${frankenphp.length}`,
                            parts: [...f.instances.folders.runtime.parts, {
                                name: "php/8.5",
                                version: "8.5.12",
                                url: `https://example.test/download/${name}`,
                                archive: "tar",
                                sha256: sha,
                                // The Gateway encodes an empty program list as a JSON list.
                                bin: [],
                                static_php: {
                                    php_version: "8.5.12",
                                    extensions: ["core", "pdo_pgsql"],
                                },
                            }],
                        },
                    },
                });
                return f.run();
            };
            let result = await install(["Core", "pdo_pgsql", "frankenphp-cli"]);
            assert(result.ok, result.output);
            const version = `${f.folder}/opt/orbit-runtime/444444444443`;
            assert(f.has("opt/orbit-runtime/444444444443/php/8.5/bin/php"));
            assert(
                f.has("opt/orbit-runtime/444444444443/php/8.5/bin/frankenphp"),
            );
            assert(!f.has("opt/orbit-runtime/444444444443/bin/php"));
            assertEquals(
                Deno.readLinkSync(`${version}/bin/tool`),
                "../tool/tool-1.0/tool",
            );

            result = await install([
                "Core",
                "pdo_pgsql",
                "frankenphp-cli",
                "apcu",
            ]);
            assert(!result.ok);
            assertStringIncludes(result.output, "frankenphp also loads apcu.");
            // The refused version never becomes whole, and the folder stays on the checked one.
            assert(!f.has("opt/orbit-runtime/444444444444"));
            assertEquals(
                Deno.readLinkSync(`${f.folder}/opt/orbit-runtime/current`),
                "444444444443",
            );
        } finally {
            f.cleanup();
        }
    },
});
