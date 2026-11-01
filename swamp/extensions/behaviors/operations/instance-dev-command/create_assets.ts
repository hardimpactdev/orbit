import { dirname } from "jsr:@std/path@1.1.2";

/** Resolve shipped files unchanged; operational scripts never originate in TypeScript. */
export async function createAssets(extensionFile: (path: string) => string) {
    const prepare = extensionFile("create/prepare.py");
    return {
        directory: dirname(dirname(prepare)),
        prepare: await Deno.readTextFile(prepare),
        source: await Deno.readTextFile(extensionFile("create/source.py")),
    };
}
