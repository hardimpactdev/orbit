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

Deno.test({
    name:
        "the runtime folder builds its Vite+ home once, links vp and the home into each version, and drops a home no version links",
    ignore: !hasAnsible,
    async fn() {
        const f = await fixture();
        try {
            const calls = `${f.folder}/vp-calls`;
            // npm installs the locked package under its --prefix; vp records its calls and runs the home's Node.
            const npm = await program(f.folder, "node-1.0.tar.gz", {
                "node/bin/npm": `while [ "$1" != --prefix ]; do shift; done
mkdir -p "$2/node_modules/vite-plus" && cp "$2/package-lock.json" "$2/node_modules/vite-plus/package.json"`,
            });
            const vp = await program(f.folder, "vp-1.4.0.tar.gz", {
                vp: `echo "$VP_HOME $VP_NO_UPDATE_CHECK $*" >> ${calls}
[ "$*" = "env exec node --version" ] && echo v24.22.1; exit 0`,
            });
            const runtime = (version: string, node: string, home: string) => ({
                ...f.instances,
                folders: {
                    ...f.instances.folders,
                    runtime: {
                        ...f.instances.folders.runtime,
                        version,
                        parts: [{
                            name: "node",
                            version: "1.0",
                            url: "https://example.test/download/node-1.0.tar.gz",
                            archive: "tar",
                            sha256: npm,
                            bin: { npm: "node/bin/npm" },
                        }, {
                            name: "vite-plus",
                            version: "1.4.0",
                            url: "https://example.test/download/vp-1.4.0.tar.gz",
                            archive: "tar",
                            sha256: vp,
                            bin: [],
                        },
                        // Every runtime folder holds the trust module's part (docs/reference/development-containers.md#trust).
                        ...f.instances.folders.runtime.parts.filter((part) => "trust_module" in part)],
                        vite_plus: {
                            part: "vite-plus",
                            binary: "vp",
                            home:
                                `${f.folder}/opt/orbit-runtime/vite-plus/${home}`,
                            version: "1.4.0",
                            node,
                            lock: JSON.stringify({
                                name: "orbit-recipe-vite-plus",
                                version: "1.4.0",
                            }),
                        },
                    },
                },
            });
            f.write(runtime("555555555555", "24.22.1", "aaaaaaaaaaaa"));
            let result = await f.run();
            assert(result.ok, result.output);
            const home = `${f.folder}/opt/orbit-runtime/vite-plus/aaaaaaaaaaaa`;
            const version = `${f.folder}/opt/orbit-runtime/555555555555`;
            assert(f.has("opt/orbit-runtime/vite-plus/aaaaaaaaaaaa/.complete"));
            assert(
                f.has(
                    "opt/orbit-runtime/vite-plus/aaaaaaaaaaaa/recipe/runtime/node_modules/vite-plus/package.json",
                ),
            );
            assertEquals(
                Deno.readLinkSync(`${home}/node_modules/vite-plus`),
                `${home}/recipe/runtime/node_modules/vite-plus`,
            );
            assertEquals(Deno.readLinkSync(`${home}/bin/node`), "vp");
            assertEquals(Deno.readLinkSync(`${version}/vite-plus-home`), home);
            assertEquals(
                Deno.readLinkSync(`${version}/bin/vp`),
                "../vite-plus-home/bin/vp",
            );
            assertEquals(Deno.readTextFileSync(calls).trim().split("\n"), [
                `${home} 1 env default 24.22.1`,
                `${home} 1 env install 24.22.1`,
                `${home} 1 env exec node --version`,
            ]);

            // A complete home is not built again for another version of the folder.
            f.write(runtime("666666666666", "24.22.1", "aaaaaaaaaaaa"));
            result = await f.run();
            assert(result.ok, result.output);
            assertEquals(
                Deno.readTextFileSync(calls).trim().split("\n").length,
                3,
            );

            // Another release is another home. The old one stays while a process maps it, even after its version went.
            Deno.writeTextFileSync(
                `${f.folder}/maps`,
                `7f00-7f01 r-xp 0 00:00 1 ${home}/bin/vp\n`,
            );
            f.write(runtime("777777777777", "24.22.1", "bbbbbbbbbbbb"));
            result = await f.run();
            assert(result.ok, result.output);
            assert(f.has("opt/orbit-runtime/vite-plus/bbbbbbbbbbbb/.complete"));
            assert(!f.has("opt/orbit-runtime/666666666666"));
            assert(f.has("opt/orbit-runtime/vite-plus/aaaaaaaaaaaa/.complete"));
            // Once no process maps it and no version links it, it goes.
            Deno.writeTextFileSync(`${f.folder}/maps`, "");
            result = await f.run();
            assert(result.ok, result.output);
            assert(!f.has("opt/orbit-runtime/vite-plus/aaaaaaaaaaaa"));

            // A home whose Node is not the recipe's is refused and never marked complete.
            f.write(runtime("888888888888", "24.23.0", "cccccccccccc"));
            result = await f.run();
            assert(!result.ok);
            assertStringIncludes(
                result.output,
                "the Vite+ home runs Node v24.22.1, not 24.23.0",
            );
            assert(
                !f.has("opt/orbit-runtime/vite-plus/cccccccccccc/.complete"),
            );
            assertEquals(
                Deno.readLinkSync(`${f.folder}/opt/orbit-runtime/current`),
                "777777777777",
            );
        } finally {
            f.cleanup();
        }
    },
});
