import { binary, Swamp } from "../../node-worker/config.ts";

/** Shared official pin; this public descriptor contains no credential material. */
export function swampInstallSpec() {
    return {
        binary,
        artifact: {
            url: `https://github.com/swamp-club/swamp/releases/download/v${Swamp.version}/swamp-linux-x86_64`,
            sha256: Swamp.sha256,
            bytes: Swamp.bytes,
        },
        minimum_memory_bytes: Swamp.bytes + Swamp.headroom,
    };
}
