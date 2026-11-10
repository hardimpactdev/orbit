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
                        }],
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
