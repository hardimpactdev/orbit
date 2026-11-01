/** Source sections for retained local certificate callers. */
import source from "./retained_role_policy.py" with { type: "text" };
export function roleSection(
    source: string,
    section: "HOST" | "PAIR",
) {
    return source.split("# BEGIN " + section + "\n")[1].split(
        "# END " + section,
    )[0];
}
export const roleHostProgram = roleSection(source, "HOST");
export const rolePairProgram = roleSection(source, "PAIR");
