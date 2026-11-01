import { dirname } from "jsr:@std/path@1.1.2";

/** Resolve the shipped action group once; script contents remain unchanged files. */
export function createAssets(extensionFile: (path: string) => string) {
    return { directory: dirname(dirname(extensionFile("create/prepare.py"))) };
}
