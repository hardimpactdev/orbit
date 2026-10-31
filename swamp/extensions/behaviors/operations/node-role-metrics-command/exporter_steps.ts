import { roleBaselineSteps } from "../node-role-converge-command/steps.ts";
import {
    type ExporterKind,
    exporterSpecification,
} from "../integrations/metrics_exporter_spec.ts";

type Fields = Record<string, unknown>;
const expr = (s: string) => "${{ " + s + " }}";
const placement = { target: "orbit-node-209", labels: { node: "209" } };
/** One exporter's native actions. The root retains snapshot outputs for restoration. */
export function roleExporterSteps(
    name: string,
    kind: ExporterKind,
    member: string,
    metricsAddress: string,
    globals: Fields,
    dependency?: string,
    active?: string,
    recovery?: { after: string; when: string },
): Fields[] {
    const spec = exporterSpecification(kind, "127.0.0.1");
    const forward: Fields[] = [], recoverySteps: Fields[] = [];
    const failed = (steps: Fields[]) =>
        steps.length
            ? `steps.exists(key, ${
                JSON.stringify(steps.map((s) => s.name))
            }.exists(n,key==n) && steps[key].status in ["failed","cancelled"])`
            : "false";
    const append = (group: Fields[], condition = "true", restore = false) => {
        const chosen = restore ? recoverySteps : forward;
        group[0].dependsOn = [
            ...(dependency ? [{ name: dependency }] : []),
            ...forward,
            ...(restore && recovery ? [{ name: recovery.after }] : []),
            ...(restore ? recoverySteps : []),
        ].map((s) => ({ step: s.name, condition: { type: "always" } }));
        for (const step of group) {
            step.guard = expr(
                `(${failed(chosen)}) || ` + (!restore && dependency
                    ? `(${failed([{ name: dependency }])}) || `
                    : "") +
                    (active ? `!(${active}) || ` : "") +
                    `!(${condition})`,
            );
            chosen.push(step);
        }
    };
    const metadata = (
        suffix: string,
        methodName: string,
        inputs: Fields,
        condition = "true",
        restore = false,
    ) => append(
        [{
            name: name + suffix,
            ...placement,
            task: {
                type: "behavior",
                behaviorType: "@nckrtl/node-role-metrics-assets",
                behaviorName: name + suffix,
                methodName,
                inputs,
            },
        }],
        condition,
        restore,
    );
    const sshStep = (
        suffix: string,
        argv: unknown,
        condition = "true",
        restore = false,
    ) => append(
        [{
            name: name + suffix,
            ...placement,
            writes: true,
            task: {
                type: "behavior",
                behaviorType: "@swamp/ssh",
                behaviorName: globals.name,
                globalArgs: globals,
                methodName: "exec",
                inputs: { hosts: "name:target", sudo: true, argv },
            },
        }],
        condition,
        restore,
    );
    const script = (
        suffix: string,
        action: "exporter_observe" | "artifact",
        condition = "true",
        restore = false,
    ) => {
        metadata(
            suffix + "_assets",
            "exporter_asset",
            { action, kind, address: expr(member + ".address") },
            condition,
            restore,
        );
        append(
            [{
                name: name + suffix + "_command",
                ...placement,
                ...(action === "artifact" ? { writes: true } : {}),
                task: {
                    type: "behavior",
                    behaviorType: "@swamp/ssh",
                    behaviorName: globals.name,
                    globalArgs: globals,
                    methodName: "script",
                    inputs: {
                        hosts: "name:target",
                        sudo: true,
                        interpreter: "python3",
                        script: expr(
                            `steps[${
                                JSON.stringify(name + suffix + "_assets")
                            }].outputs.script_body`,
                        ),
                    },
                },
            }],
            condition,
            restore,
        );
        metadata(
            suffix,
            action === "artifact" ? "decode_ready" : "exporter_decode",
            {
                stdout: expr(
                    `steps[${
                        JSON.stringify(name + suffix + "_command")
                    }].outputs.stdout`,
                ),
            },
            condition,
            restore,
        );
    };
    const plan = `steps[${JSON.stringify(name + "_plan")}].outputs`;
    const observation = (
        suffix: string,
        condition = "true",
        restore = false,
    ) => {
        script(suffix, "exporter_observe", condition, restore);
        sshStep(
            suffix + "_ufw",
            ["/usr/sbin/ufw", "status", "numbered"],
            condition,
            restore,
        );
    };
    const args = (suffix: string) => ({
        kind,
        address: expr(member + ".address"),
        metrics_address: expr(metricsAddress),
        selected: expr(member + ".selected"),
        observation: expr(`steps[${JSON.stringify(name + suffix)}].outputs`),
        ufw: expr(
            `steps[${JSON.stringify(name + suffix + "_ufw")}].outputs.stdout`,
        ),
    });
    observation("_before");
    metadata("_plan", "exporter_plan", args("_before"));
    append(
        roleBaselineSteps(name + "_snapshot", "file_snapshot", {
            path: spec.path,
            run_id: expr("run.id"),
        }, globals),
    );
    if (spec.artifact) {
        append(
            roleBaselineSteps(name + "_binary_snapshot", "file_snapshot", {
                path: spec.artifact.path,
                run_id: expr("run.id"),
            }, globals),
        );
    }
    if (kind === "node_exporter") {
        sshStep("_install", [
            "/usr/bin/apt-get",
            "-o",
            "DPkg::Lock::Timeout=60",
            "install",
            "--yes",
            "--no-install-recommends",
            "--",
            spec.service,
        ], member + ".selected");
    } else script("_install", "artifact", member + ".selected");
    sshStep(
        "_stop",
        ["/usr/bin/systemctl", "disable", "--now", spec.service],
        `!${member}.selected && ${plan}.before.configuration!=null`,
    );
    append(
        roleBaselineSteps(name + "_publish", "file", {
            path: spec.path,
            mode: "0644",
            owner: "root:root",
            marker: spec.marker,
            content: expr(plan + ".configuration"),
        }, globals),
        member + ".selected",
    );
    append(
        roleBaselineSteps(name + "_remove", "file_remove", {
            path: spec.path,
            marker: spec.marker,
        }, globals),
        `!${member}.selected && ${plan}.before.configuration!=null`,
    );
    sshStep("_reload", ["/usr/bin/systemctl", "daemon-reload"]);
    sshStep(
        "_enable",
        ["/usr/bin/systemctl", "enable", "--now", spec.service],
        member + ".selected",
    );
    sshStep(
        "_restart",
        ["/usr/bin/systemctl", "restart", spec.service],
        member + ".selected",
    );
    sshStep(
        "_delete_rule",
        expr(`["/usr/sbin/ufw","--force","delete"]+${plan}.remove_rule`),
        `size(${plan}.remove_rule)>0`,
    );
    sshStep(
        "_add_rule",
        expr(`["/usr/sbin/ufw"]+${plan}.add_rule`),
        `size(${plan}.add_rule)>0`,
    );
    observation("_actual");
    metadata("_ready", "exporter_verify", {
        ...args("_actual"),
        expected: expr(plan + ".after"),
    });
    if (spec.artifact) {
        append(
            roleBaselineSteps(name + "_remove_binary", "file_remove", {
                path: spec.artifact.path,
                marker: null,
            }, globals),
            `!${member}.selected`,
        );
    }
    const restore = `(((${
        failed(forward)
    }) && !("${name}_ready" in steps && steps["${name}_ready"].status=="succeeded")) || (${
        recovery?.when ?? "false"
    })) && "${name}_snapshot" in steps && steps["${name}_snapshot"].status=="succeeded"`;
    sshStep(
        "_restore_stop",
        ["/usr/bin/systemctl", "disable", "--now", spec.service],
        `${restore} && !${plan}.before.service_active && "${name}_publish" in steps && steps["${name}_publish"].status=="succeeded"`,
        true,
    );
    append(
        roleBaselineSteps(name + "_restore_file", "file_restore", {
            path: spec.path,
            run_id: expr("run.id"),
        }, globals),
        restore,
        true,
    );
    if (spec.artifact) {
        append(
            roleBaselineSteps(name + "_restore_binary", "file_restore", {
                path: spec.artifact.path,
                run_id: expr("run.id"),
            }, globals),
            `${restore} && "${name}_binary_snapshot" in steps && steps["${name}_binary_snapshot"].status=="succeeded"`,
            true,
        );
    }
    sshStep(
        "_restore_reload",
        ["/usr/bin/systemctl", "daemon-reload"],
        restore,
        true,
    );
    sshStep(
        "_restore_active",
        expr(
            `["/usr/bin/systemctl",${plan}.before.service_active?"enable":"disable","--now",${
                JSON.stringify(spec.service)
            }]`,
        ),
        `${restore} && ${plan}.before.configuration!=null`,
        true,
    );
    sshStep(
        "_restore_restart",
        ["/usr/bin/systemctl", "restart", spec.service],
        `${restore} && ${plan}.before.service_active`,
        true,
    );
    const ran = (suffix: string) =>
        `"${name}${suffix}" in steps && steps["${name}${suffix}"].status=="succeeded"`;
    sshStep(
        "_restore_delete_rule",
        expr(`["/usr/sbin/ufw","--force","delete"]+${plan}.add_rule`),
        `${restore} && ${ran("_add_rule")}`,
        true,
    );
    sshStep(
        "_restore_add_rule",
        expr(`["/usr/sbin/ufw"]+${plan}.remove_rule`),
        `${restore} && ${ran("_delete_rule")}`,
        true,
    );
    observation("_restored", restore, true);
    metadata(
        "_restored_ready",
        "exporter_verify",
        { ...args("_restored"), expected: expr(plan + ".before") },
        restore,
        true,
    );
    return [...forward, ...recoverySteps];
}

/** The owning parent calls cleanup only after whole-fleet completion or verified restoration. */
export function roleExporterCleanupSteps(
    name: string,
    kind: ExporterKind,
    globals: Fields,
    dependency: string,
): Fields[] {
    const spec = exporterSpecification(kind, "127.0.0.1"), steps: Fields[] = [];
    let after = dependency;
    for (
        const [index, path] of [
            spec.path,
            ...(spec.artifact ? [spec.artifact.path] : []),
        ].entries()
    ) {
        const next = name + "_cleanup_" + index;
        steps.push(
            ...roleBaselineSteps(
                next,
                "file_snapshot_cleanup",
                { path, run_id: expr("run.id") },
                globals,
                after,
            ),
        );
        after = next;
    }
    return steps;
}
