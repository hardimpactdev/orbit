import { z } from "npm:zod@4.4.3";
import { matchesMountPaths } from "../processes-schedules/required-mount-paths.ts";
import { Container } from "../processes-schedules/container-input.ts";
import { ProcessInstallation } from "../processes-schedules/installation-input.ts";
import { Input } from "../processes-schedules/process-input.ts";

const Digest = z.string().regex(/^[a-f0-9]{64}$/);
const Installation = z.object({
    process_id: z.number().int().positive(),
    runtime: z.enum(["systemd", "docker"]),
    name: z.string(),
    desired: z.enum(["running", "stopped"]),
    on_demand: z.boolean(),
    directory: z.string().optional(),
    content_hash: Digest.optional(),
    activation_pending: z.boolean().optional(),
    account_user: z.string().optional(),
    required_mount_paths: z.array(z.string()).optional(),
    spec: Digest.optional(),
    unit: z.string().optional(),
    definition: Container.omit({ environment: true }).optional(),
    environment_path: z.string().optional(),
    has_environment: z.boolean(),
    retired: z.object({
        runtime: z.enum(["systemd", "docker"]),
        name: z.string(),
    }).strict().nullable(),
}).strict();
export const LifecycleArgs = z.object({
    control: Input.omit({ agent_sha256: true }),
    installation: Installation.optional(),
    account: z.string().default(""),
    marker: z.string().default(""),
}).strict();
export const LifecycleAssets = z.object({
    unit: z.string(),
    control_json: z.string(),
    intent_json: z.string(),
    marker_directory: z.string(),

    scripts_directory: z.string(),
});

/** Validate public rendered intent; account observation is public input, never an effect. */
export async function lifecycleAssets(
    args: z.infer<typeof LifecycleArgs>,
    directory: string,
) {
    const control = args.control;
    const expected = new RegExp(
        `^orbit-process-${control.process_id}-[a-z0-9](?:[a-z0-9-]*[a-z0-9])?` +
            (control.runtime === "systemd" ? "\\.service$" : "$"),
    );
    if (!expected.test(control.name)) {
        throw new Error("Invalid Process artifact");
    }
    const install = args.installation;
    if (install) {
        ProcessInstallation.parse(
            Object.fromEntries(
                Object.entries(install).filter(([key]) =>
                    ![
                        "unit",
                        "definition",
                        "environment_path",
                        "has_environment",
                    ].includes(key)
                ),
            ),
        );
    }
    let unit = "";
    if (install) {
        if (
            install.process_id !== control.process_id ||
            install.name !== control.name || install.runtime !== control.runtime
        ) throw new Error("Process installation differs");
        if (install.runtime === "systemd") {
            unit = z.string().min(1).parse(install.unit);
            const digest = Array.from(
                new Uint8Array(
                    await crypto.subtle.digest(
                        "SHA-256",
                        new TextEncoder().encode(unit),
                    ),
                ),
                (b) => b.toString(16).padStart(2, "0"),
            ).join("");
            if (
                digest !== install.content_hash ||
                !matchesMountPaths(unit, install.required_mount_paths ?? []) ||
                !unit.split("\n").includes(
                    `X-Orbit-Process-ID=${control.process_id}`,
                )
            ) throw new Error("Process unit differs");
            if (install.account_user) {
                if (
                    !/^[a-z_][a-z0-9_-]{0,31}$/.test(install.account_user) ||
                    install.account_user === "root" ||
                    !unit.includes(`\nUser=${install.account_user}\n`)
                ) throw new Error("Invalid Process account");
                const fields = args.account.trimEnd().split(":");
                const home = fields[5];
                if (
                    fields.length !== 7 || fields[0] !== install.account_user ||
                    !/^[1-9][0-9]*$/.test(fields[2]) ||
                    !/^[0-9]+$/.test(fields[3]) ||
                    !home?.startsWith("/") || home.length > 4096 ||
                    /[\p{Cc}]/u.test(home) ||
                    home.split("/").slice(1).some((part) =>
                        ["", ".", ".."].includes(part)
                    )
                ) throw new Error("Process account unavailable");
                unit = unit.split("\n").map((line) =>
                    line.replaceAll(
                        "/__orbit_process_account_home__",
                        () =>
                            line.startsWith("ExecStart=")
                                ? home.replace(/[\\"$%]/g, (c) =>
                                    c === "$"
                                        ? "$$"
                                        : c === "%"
                                        ? "%%"
                                        : `\\${c}`)
                                : home.replace(/[ "'$%\\]/g, (c) =>
                                    c === "%"
                                        ? "%%"
                                        : `\\x${
                                            c.charCodeAt(0).toString(16)
                                                .padStart(2, "0")
                                        }`),
                    )
                ).join("\n");
            }
            if (unit.includes("/__orbit_process_account_home__")) {
                throw new Error("Unresolved Process account");
            }
            if (
                install.environment_path !==
                    `/etc/orbit/processes/${control.process_id}/${
                        install.environment_path?.split("/").at(-1)
                    }` ||
                !/^[a-f0-9]{64}\.env$/.test(
                    install.environment_path?.split("/").at(-1) ?? "",
                )
            ) throw new Error("Process environment path differs");
        } else if (
            !install.definition || "environment" in install.definition ||
            !install.spec
        ) throw new Error("Invalid public container definition");
    }
    return {
        unit,
        control_json: JSON.stringify(control),
        intent_json: JSON.stringify({ installation: install }),
        marker_directory: args.marker.slice(0, args.marker.lastIndexOf("/")),

        scripts_directory: directory,
    };
}
