import { z } from "npm:zod@4.4.3";

export const RequiredMountPaths = z.array(
    z.string().startsWith("/").refine((path) =>
        new TextEncoder().encode(path).length <= 4096 &&
        ![...path].some((char) =>
            char.charCodeAt(0) < 32 || char.charCodeAt(0) === 127
        ) && !path.includes("//") &&
        (path === "/" || !path.endsWith("/")) &&
        !path.split("/").some((part) => part === "." || part === "..")
    ),
).max(64).refine((paths) => new Set(paths).size === paths.length);

/** Match the renderer's literal systemd path encoding, including specifiers. */
export function mountPathDirective(paths: readonly string[]): string | null {
    if (paths.length === 0) return null;
    const escape = (path: string) =>
        [...path].map((character) => {
            const code = character.charCodeAt(0);
            if (character === "%") return "%%";
            return code <= 32 || code === 127 ||
                    ['"', "'", "$", "\\"].includes(character)
                ? "\\x" + code.toString(16).padStart(2, "0")
                : character;
        }).join("");
    return `RequiresMountsFor=${paths.map(escape).join(" ")}`;
}

/** Refuse a missing, extra or differently scoped directive before publication. */
export function matchesMountPaths(
    content: string,
    paths: readonly string[],
): boolean {
    let section = "";
    const directives: string[] = [];
    for (const line of content.split(/\r?\n/)) {
        if (line.startsWith("[")) section = line;
        if (/^\s*RequiresMountsFor\s*=/.test(line)) {
            if (section !== "[Unit]") return false;
            directives.push(line);
        }
    }
    const expected = mountPathDirective(paths);
    return expected === null
        ? directives.length === 0
        : directives.length === 1 && directives[0] === expected;
}
