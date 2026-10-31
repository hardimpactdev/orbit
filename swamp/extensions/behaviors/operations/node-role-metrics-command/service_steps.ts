import { roleBaselineSteps } from "../node-role-converge-command/steps.ts";
import { serviceSpecification as spec } from "./service.ts";
type Fields = Record<string, unknown>;
const expr = (s: string) => "${{ " + s + " }}";
const placement = { target: "orbit-node-209", labels: { node: "209" } };
/** One visible FPM-exporter service chain, retaining the parent's local rollback copies. */
export function roleMetricsServiceSteps(
    name: string,
    desired: string,
    globals: Fields,
    dependency?: string,
    active?: string,
    recovery?: { after: string; when: string },
): Fields[] {
    const forward: Fields[] = [], recoverySteps: Fields[] = [];
    const failed = (list: Fields[]) =>
        list.length
            ? `steps.exists(key,${
                JSON.stringify(list.map((s) => s.name))
            }.exists(n,key==n) && steps[key].status in ["failed","cancelled"])`
            : "false";
    function append(group: Fields[], condition = "true", restore = false) {
        const selected = restore ? recoverySteps : forward;
        group[0].dependsOn = [
            ...(dependency ? [{ name: dependency }] : []),
            ...forward,
            ...(restore && recovery ? [{ name: recovery.after }] : []),
            ...(restore ? recoverySteps : []),
        ].map((s) => ({ step: s.name, condition: { type: "always" } }));
        for (const step of group) {
            step.guard = expr(
                `(${failed(selected)}) || ` + (!restore && dependency
                    ? `(${failed([{ name: dependency }])}) || `
                    : "") +
                    (active ? `!(${active}) || ` : "") + `!(${condition})`,
            );
            selected.push(step);
        }
    }
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
    function script(
        suffix: string,
        action: string,
        condition = "true",
        restore = false,
    ) {
        metadata(
            suffix + "_assets",
            "service_asset",
            { action },
            condition,
            restore,
        );
        append(
            [{
                name: name + suffix + "_command",
                ...placement,
                ...(action === "service_observe" ? {} : { writes: true }),
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
            action === "service_observe" ? "service_decode" : "decode_ready",
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
    }
    function observe(suffix: string, condition = "true", restore = false) {
        script(suffix, "service_observe", condition, restore);
        sshStep(
            suffix + "_ufw_status",
            ["/usr/sbin/ufw", "status"],
            condition,
            restore,
        );
        sshStep(
            suffix + "_ufw_added",
            ["/usr/sbin/ufw", "show", "added"],
            condition,
            restore,
        );
    }
    const args = (suffix: string, wanted: string) => ({
        desired: expr(wanted),
        observation: expr(`steps[${JSON.stringify(name + suffix)}].outputs`),
        ufw_status: expr(
            `steps[${
                JSON.stringify(name + suffix + "_ufw_status")
            }].outputs.stdout`,
        ),
        ufw_added: expr(
            `steps[${
                JSON.stringify(name + suffix + "_ufw_added")
            }].outputs.stdout`,
        ),
    });
    const plan = `steps[${JSON.stringify(name + "_plan")}].outputs`;
    const paths = [
        spec.config_path,
        spec.unit_path,
        spec.owner_path,
        spec.artifact.path,
    ];
    observe("_before");
    metadata("_plan", "service_plan", args("_before", desired));
    for (const [index, path] of paths.entries()) {
        append(
            roleBaselineSteps(name + "_snapshot_" + index, "file_snapshot", {
                path,
                run_id: expr("run.id"),
            }, globals),
        );
    }
    script("_directory", "service_directory");
    append(
        roleBaselineSteps(name + "_owner", "file", {
            path: spec.owner_path,
            mode: "0600",
            owner: "root:root",
            marker: spec.marker,
            content: spec.marker,
        }, globals),
    );
    script(
        "_binary",
        "artifact",
        plan + ".after.binary && !" + plan + ".before.binary",
    );
    sshStep(
        "_stop",
        ["/usr/bin/systemctl", "disable", "--now", spec.service],
        `${plan}.after.config==null && ${plan}.before.unit!=null`,
    );
    for (
        const [suffix, path, field, mode] of [[
            "_config",
            spec.config_path,
            "config",
            "0600",
        ], ["_unit", spec.unit_path, "unit", "0644"]]
    ) {
        append(
            roleBaselineSteps(name + suffix, "file", {
                path,
                mode,
                owner: "root:root",
                marker: spec.marker,
                content: expr(plan + ".after." + field),
            }, globals),
            plan + ".after.config!=null",
        );
        append(
            roleBaselineSteps(name + suffix + "_remove", "file_remove", {
                path,
                marker: spec.marker,
            }, globals),
            plan + ".after.config==null",
        );
    }
    sshStep("_reload", ["/usr/bin/systemctl", "daemon-reload"]);
    function rules(
        prefix: string,
        source: string,
        condition = "true",
        restore = false,
    ) {
        for (let index = 0; index < 4; index++) {
            sshStep(
                prefix + "_delete_" + index,
                expr(
                    `["/usr/sbin/ufw","--force","delete"]+${source}.remove_rules[${index}]`,
                ),
                `${condition} && size(${source}.remove_rules)>${index}`,
                restore,
            );
        }
        for (let index = 0; index < 4; index++) {
            sshStep(
                prefix + "_add_" + index,
                expr(
                    `["/usr/sbin/ufw","insert","1"]+${source}.add_rules[${index}]`,
                ),
                `${condition} && size(${source}.add_rules)>${index}`,
                restore,
            );
        }
    }
    rules("_rules", plan);
    sshStep(
        "_enable",
        expr(
            `["/usr/bin/systemctl",${plan}.after.enabled?"enable":"disable",${
                JSON.stringify(spec.service)
            }]`,
        ),
        `${plan}.after.config!=null && ${plan}.before.enabled!=${plan}.after.enabled`,
    );
    sshStep(
        "_restart",
        ["/usr/bin/systemctl", "restart", spec.service],
        `${plan}.after.config!=null && ${plan}.after.active && (${plan}.before.config!=${plan}.after.config || ${plan}.before.unit!=${plan}.after.unit || !${plan}.before.active)`,
    );
    sshStep(
        "_deactivate",
        ["/usr/bin/systemctl", "stop", spec.service],
        `${plan}.after.config!=null && !${plan}.after.active && ${plan}.before.active`,
    );
    metadata("_listener", "service_listener", {
        config: expr(plan + ".after.config"),
    }, plan + ".after.active");
    sshStep(
        "_health",
        expr(
            `["/usr/bin/curl","--fail","--silent","--noproxy","*","--max-time","12","--max-filesize","8388608",steps[${
                JSON.stringify(name + "_listener")
            }].outputs.url]`,
        ),
        plan + ".after.active",
    );
    metadata("_health_ready", "service_health", {
        stdout: expr(
            `steps[${JSON.stringify(name + "_health")}].outputs.stdout`,
        ),
    }, plan + ".after.active");
    append(
        roleBaselineSteps(name + "_remove_binary", "file_remove", {
            path: spec.artifact.path,
            marker: null,
        }, globals),
        `!${plan}.after.binary && ${plan}.before.binary`,
    );
    observe("_actual");
    metadata("_ready", "service_verify", {
        ...args("_actual", plan + ".after"),
        expected: expr(plan + ".after"),
    });
    const restore = `(((${
        failed(forward)
    }) && !("${name}_ready" in steps && steps["${name}_ready"].status=="succeeded")) || (${
        recovery?.when ?? "false"
    })) && "${name}_snapshot_0" in steps && steps["${name}_snapshot_0"].status=="succeeded"`;
    sshStep(
        "_restore_stop",
        ["/usr/bin/systemctl", "disable", "--now", spec.service],
        `${restore} && !${plan}.before.active && "${name}_unit" in steps && steps["${name}_unit"].status=="succeeded"`,
        true,
    );
    for (const [index, path] of paths.entries()) {
        append(
            roleBaselineSteps(name + "_restore_" + index, "file_restore", {
                path,
                run_id: expr("run.id"),
            }, globals),
            `${restore} && "${name}_snapshot_${index}" in steps && steps["${name}_snapshot_${index}"].status=="succeeded"`,
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
        "_restore_enabled",
        expr(
            `["/usr/bin/systemctl",${plan}.before.enabled?"enable":"disable",${
                JSON.stringify(spec.service)
            }]`,
        ),
        `${restore} && ${plan}.before.unit!=null`,
        true,
    );
    sshStep(
        "_restore_restart",
        ["/usr/bin/systemctl", "restart", spec.service],
        `${restore} && ${plan}.before.active`,
        true,
    );
    script(
        "_restore_directory",
        "service_directory_remove",
        `${restore} && !${plan}.directory_present`,
        true,
    );
    observe("_restore_actual", restore, true);
    metadata(
        "_restore_plan",
        "service_plan",
        args("_restore_actual", plan + ".before"),
        restore,
        true,
    );
    rules(
        "_restore_rules",
        `steps[${JSON.stringify(name + "_restore_plan")}].outputs`,
        restore,
        true,
    );
    observe("_restored", restore, true);
    metadata(
        "_restored_ready",
        "service_verify",
        {
            ...args("_restored", plan + ".before"),
            expected: expr(plan + ".before"),
        },
        restore,
        true,
    );
    return [...forward, ...recoverySteps];
}

/** Release the local recovery files only after the parent's completed decision. */
export function roleMetricsServiceCleanupSteps(
    name: string,
    globals: Fields,
    dependency: string,
): Fields[] {
    const steps: Fields[] = [];
    let after = dependency;
    for (
        const [index, path] of [
            spec.config_path,
            spec.unit_path,
            spec.owner_path,
            spec.artifact.path,
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
