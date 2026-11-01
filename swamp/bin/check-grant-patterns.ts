/** Run the pinned native regex check without creating grants or starting Swamp. */
const SOURCE = {
    revision: "e459d219fe4c9b37c4fdd3f4a7611bf1b6880226",
    sha256: "048d3ef79e925b2e72e5267221d2167b8ffdde5b743817d6094fdb7730880606",
    path: "src/infrastructure/cel/grant_condition_environment.ts",
};
type RegexCheck = (pattern: string) => string | undefined;
type Policy = { vaults: { vault: string; key_pattern: string }[] };

export function requirePinnedSource(version: string): void {
    if (version.split("-sha.")[1] !== SOURCE.revision.slice(0, 8)) {
        throw new Error(
            "Update the grant safety source reference with the Swamp runtime pin",
        );
    }
}

export async function loadNativeRegexCheck(
    source?: string,
): Promise<RegexCheck> {
    if (source === undefined) {
        const url =
            `https://raw.githubusercontent.com/swamp-club/swamp/${SOURCE.revision}/${SOURCE.path}`;
        const response = await fetch(url);
        if (!response.ok) {
            throw new Error(
                `Native grant safety source: HTTP ${response.status}`,
            );
        }
        source = await response.text();
    }
    const digest = Array.from(
        new Uint8Array(
            await crypto.subtle.digest(
                "SHA-256",
                new TextEncoder().encode(source),
            ),
        ),
        (byte) => byte.toString(16).padStart(2, "0"),
    ).join("");
    if (digest !== SOURCE.sha256) {
        throw new Error("Native grant safety source checksum mismatch");
    }
    // Execute the unchanged upstream constant and helper; export visibility is the only edit.
    const start = source.indexOf("const CATASTROPHIC_BACKTRACKING_PATTERN =");
    const end = source.indexOf(
        "\nfunction createGrantConditionEnvironment(",
        start,
    );
    if (start < 0 || end < 0) {
        throw new Error("Native grant safety helper not found");
    }
    const helper = source.slice(start, end).replace(
        "function checkRegexComplexity(",
        "export function checkRegexComplexity(",
    );
    const module = await import(
        `data:application/typescript;base64,${btoa(helper)}`
    );
    if (typeof module.checkRegexComplexity !== "function") {
        throw new Error("Native grant safety export missing");
    }
    return module.checkRegexComplexity as RegexCheck;
}

export function checkGrantPatterns(
    policy: Policy,
    check: RegexCheck,
): string[] {
    return policy.vaults.flatMap((entry, index) => {
        const error = check(entry.key_pattern);
        return error ? [`vaults[${index}] ${entry.vault}: ${error}`] : [];
    });
}

if (import.meta.main) {
    const config = await Deno.readTextFile(
        "extensions/behaviors/node-worker/config.ts",
    );
    const version = /export const Swamp = \{\s*version: "([^"]+)"/.exec(config)
        ?.[1];
    requirePinnedSource(version ?? "");
    const policy = JSON.parse(
        await Deno.readTextFile("config/gateway-workflow-grants.json"),
    );
    const errors = checkGrantPatterns(policy, await loadNativeRegexCheck());
    if (errors.length) {
        console.error(errors.join("\n"));
        Deno.exit(1);
    }
    console.log(
        `GRANT-PATTERNS patterns=${policy.vaults.length} runtime=${version} native_safety=passed`,
    );
}
