/** Source sections keep retained callers on the same Python policy as the native child. */
import source from "./scripts/role_policy.py" with { type: "text" };
export function roleSection(
    source: string,
    section: "HOST" | "PAIR" | "ACTIONS",
) {
    return source.split("# BEGIN " + section + "\n")[1].split(
        "# END " + section,
    )[0];
}
export const roleHostProgram = roleSection(source, "HOST");
export const rolePairProgram = roleSection(source, "PAIR");
export type ExtensionFile = (path: string) => string | Promise<string>;
