/** Source sections for retained local certificate callers. */
import source from "./retained_role_policy.py" with { type: "text" };
export function roleSection(
    source: string,
    section: "HOST" | "PAIR" | "ACTIONS",
) {
    return source.split("# BEGIN " + section + "\n")[1].split(
        "# END " + section,
    )[0];
}
export const roleHostProgram = roleSection(source, "HOST").replace(
    "from role_paths import KEY_ROOT, role_paths\n",
    "",
);
export const rolePairProgram = roleSection(source, "PAIR");
export type ExtensionFile = (path: string) => string | Promise<string>;

export const retainedRoleActionsProgram = roleSection(source, "ACTIONS");
