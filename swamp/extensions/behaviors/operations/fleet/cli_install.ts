import { z } from "npm:zod@4.4.3";

export const CliInstallInput = z.object({
    user: z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/).refine((v) =>
        v !== "root"
    ),
    release: z.object({
        version: z.string().regex(/^0\.[1-9][0-9]{0,9}\.0$/),
        url: z.url().refine((v) => {
            const url = new URL(v);
            return url.protocol === "https:" && !url.username && !url.password;
        }),
        sha256: z.string().regex(/^[a-f0-9]{64}$/),
    }).strict().nullable(),
    profile: z.string().max(1024 * 1024),
}).strict();
export const CliNativeResult = z.object({
    installed: z.string().nullable(),
    configured: z.boolean(),
    version: z.string(),
}).strict();
export const CliPlan = z.object({
    install: z.boolean(),
    version: z.string(),
    path: z.string(),
    prune_argv: z.array(z.string()),
}).strict();
export const CliPlanInput = z.object({
    release: CliInstallInput.shape.release,
    observed: z.string().nullable(),
    self_update: z.boolean(),
    previous_path: z.string().nullable(),
}).strict();
export function cliPlan(raw: z.infer<typeof CliPlanInput>) {
    const { release, observed, self_update } = CliPlanInput.parse(raw);
    const line = observed?.trim().split("\n")[0] ?? null;
    const current =
        line?.match(/^Orbit (0\.[1-9][0-9]{0,9}\.0|[a-f0-9]{40})$/)?.[1] ??
            null;
    // An unrecognized version is not a valid Orbit release, so the admitted verified release replaces it.
    const install = !current || !self_update ||
        Boolean(
            release && current.startsWith("0.") &&
                Number(current.split(".")[1]) <
                    Number(release.version.split(".")[1]),
        );
    if (install && !release) throw new Error("cli.release_needed");
    const version = install ? release!.version : current!;
    const path = "/usr/local/bin/orbit-" + version;
    return CliPlan.parse({
        install,
        version,
        path,
        // -H follows the reference link for -samefile, while -type f leaves
        // version-shaped symlinks and directories alone. The update lock keeps
        // self-update from switching the live target during this cleanup.
        prune_argv: [
            "flock", "--wait", "120", "/run/lock/orbit-self-update.lock",
            "find", "-H", "/usr/local/bin", "-maxdepth", "1",
            "-regextype", "posix-extended", "-type", "f",
            "-regex", "/usr/local/bin/orbit-0\\.[1-9][0-9]{0,9}\\.0",
            "!", "-samefile", "/usr/local/bin/orbit",
            "!", "-path", path,
            "!", "-path", raw.previous_path?.trim() || path,
            "-print", "-delete",
        ],
    });
}
