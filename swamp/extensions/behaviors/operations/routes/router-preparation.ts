import { z } from "npm:zod@4.4.3";
import { runLocalProgram } from "./local-program.ts";

export async function prepareRouter(
    name: "caddy.install-package" | "role.install-prerequisites",
    input: Record<string, unknown>,
    invoke = runLocalProgram,
    signal?: AbortSignal,
    source?: string,
): Promise<Record<string, unknown>> {
    if (!source) {
        throw new Error(
            "The native root must supply its shipped prerequisite script",
        );
    }
    return await invoke(source, { name, input }, signal);
}

const PrerequisiteInput = z.object({
    user: z.string(),
    group: z.string(),
    home: z.string(),
    releases: z.array(z.string()),
    packages: z.array(z.string()),
    apps_directory: z.boolean(),
    composer_home: z.boolean(),
}).strict();

/** Canonical prerequisite lower shared with Node role/footprint DAGs. */
export async function installRolePrerequisites(
    raw: unknown,
    invoke = runLocalProgram,
    signal?: AbortSignal,
    source?: string,
): Promise<{ ok: true } | { ok: false; error_code: string }> {
    const result = await prepareRouter(
        "role.install-prerequisites",
        PrerequisiteInput.parse(raw),
        invoke,
        signal,
        source,
    );
    return result.error_code === undefined ? { ok: true } : {
        ok: false,
        error_code: z.string().regex(/^[a-z0-9_.-]{1,100}$/).parse(
            result.error_code,
        ),
    };
}
