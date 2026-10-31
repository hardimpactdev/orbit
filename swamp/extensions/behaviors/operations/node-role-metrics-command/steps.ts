import { metricsPaths } from "../integrations/metrics_runtime_spec.ts";
import { roleBaselineSteps } from "../node-role-converge-command/steps.ts";

type Fields = Record<string, unknown>;
const placement = { target: "orbit-node-209", labels: { node: "209" } };
const docker = ["/usr/bin/docker", "--host", "unix:///var/run/docker.sock"];
/** One same-root Metrics action chain; runtime completion remains visible after later failures. */
export function roleMetricsRuntimeSteps(
    runtime: string,
    fullGlobals: Fields,
    privateMaterial: string,
    dependency?: string,
    active?: string,
): Fields[] {
    const ssh = fullGlobals;
    const material = privateMaterial;
    const expr = (source: string) =>
        "${{ " +
        source.replaceAll("inputs.runtime", runtime).replaceAll(
            "inputs.parent_run_id",
            "run.id",
        ) + " }}";
    const forward: Fields[] = [], recovery: Fields[] = [];
    const failed = (steps: Fields[], parent = false) => {
        const names = [
            ...(parent && dependency ? [dependency] : []),
            ...steps.map((step) => step.name),
        ];
        return names.length
            ? `steps.exists(key, ${
                JSON.stringify(names)
            }.exists(name, key == name) && steps[key].status in ["failed", "cancelled"])`
            : "false";
    };
    const failure = () => failed(forward);
    function append(group: Fields[], condition = "true", restore = false) {
        const selected = restore ? recovery : forward;
        const predecessors = restore ? [...forward, ...recovery] : forward;
        group[0].dependsOn = [
            ...(dependency ? [{ name: dependency }] : []),
            ...predecessors,
        ].map((step) => ({
            step: step.name,
            condition: { type: "always" },
        }));
        for (const step of group) {
            step.guard = expr(
                `(${failed(selected, !restore)}) || ` +
                    (active ? `!(${active}) || ` : "") + `!(${condition})`,
            );
            selected.push(step);
        }
    }
    function metadata(
        name: string,
        method: string,
        inputs: Fields,
        condition = "true",
        restore = false,
    ) {
        append(
            [{
                name,
                ...placement,
                task: {
                    type: "behavior",
                    behaviorType: "@nckrtl/node-role-metrics-assets",
                    behaviorName: name,
                    methodName: method,
                    inputs,
                },
            }],
            condition,
            restore,
        );
    }
    function command(
        name: string,
        argv: unknown,
        condition = "true",
        restore = false,
        stdin?: string,
        okExitCodes?: number[],
    ) {
        append(
            [{
                name,
                ...placement,
                writes: true,
                task: {
                    type: "behavior",
                    behaviorType: "@swamp/ssh",
                    behaviorName: ssh.name,
                    methodName: "exec",
                    globalArgs: ssh,
                    inputs: {
                        hosts: "name:target",
                        sudo: true,
                        argv,
                        ...(stdin ? { stdin: expr(stdin) } : {}),
                        ...(okExitCodes ? { okExitCodes } : {}),
                    },
                },
            }],
            condition,
            restore,
        );
    }
    function inspect(
        name: string,
        service: string,
        kind: "container" | "volume",
        backup = false,
        restore = false,
    ) {
        const specification = `steps.specifications.outputs.${service}`;
        const condition = restore
            ? `(${failure()}) && "specifications" in steps && steps.specifications.status == "succeeded" && "before_${service}" in steps && steps.before_${service}.status == "succeeded" && !("ready" in steps && steps.ready.status == "succeeded")`
            : "true";
        metadata(
            name + "_assets",
            "read",
            {
                kind,
                name: expr(
                    specification +
                        (kind === "volume"
                            ? ".volume"
                            : backup
                            ? ".backup"
                            : ".name"),
                ),
                labels: expr(
                    specification +
                        (kind === "volume" ? ".volume_labels" : ".labels"),
                ),
                hash: expr(
                    kind === "volume" ? "null" : specification + ".hash",
                ),
            },
            condition,
            restore,
        );
        append(
            [{
                name: name + "_command",
                ...placement,
                task: {
                    type: "behavior",
                    behaviorType: "@swamp/ssh",
                    behaviorName: ssh.name,
                    methodName: "script",
                    globalArgs: ssh,
                    inputs: {
                        hosts: "name:target",
                        sudo: true,
                        interpreter: "python3",
                        script: expr(
                            `steps[${
                                JSON.stringify(name + "_assets")
                            }].outputs.script_body`,
                        ),
                    },
                },
            }],
            condition,
            restore,
        );
        metadata(
            name,
            "decode",
            {
                stdout: expr(
                    `steps[${
                        JSON.stringify(name + "_command")
                    }].outputs.stdout`,
                ),
            },
            condition,
            restore,
        );
    }
    function proof(
        name: string,
        source: string,
        message: string,
        condition = "true",
        restore = false,
    ) {
        append(
            [{
                name,
                ...placement,
                task: {
                    type: "assert",
                    expr: source,
                    severity: "high",
                    message,
                },
            }],
            condition,
            restore,
        );
    }
    metadata("specifications", "specifications", {
        assignment_id: expr("inputs.runtime.assignment_id"),
        address: expr("inputs.runtime.address"),
        configuration: expr("inputs.runtime.public_configuration"),
    });
    metadata("owner_assets", "owner", {});
    append([{
        name: "inspect_owner",
        ...placement,
        task: {
            type: "behavior",
            behaviorType: "@swamp/ssh",
            behaviorName: ssh.name,
            methodName: "script",
            globalArgs: ssh,
            inputs: {
                hosts: "name:target",
                sudo: true,
                interpreter: "python3",
                script: expr("steps.owner_assets.outputs.script_body"),
            },
        },
    }]);
    metadata("ownership", "decode_ready", {
        stdout: expr("steps.inspect_owner.outputs.stdout"),
    });
    for (const service of ["prometheus", "grafana"]) {
        inspect("before_" + service, service, "container");
        inspect("backup_" + service, service, "container", true);
        inspect("volume_" + service, service, "volume");
        proof(
            "available_" + service,
            `!steps.backup_${service}.outputs.present`,
            "metrics.runtime_pending",
        );
        command(
            "image_" + service,
            expr(
                `${
                    JSON.stringify(docker)
                } + ["image", "inspect", "--format={{.Id}}", "--", steps.specifications.outputs.${service}.image]`,
            ),
            "true",
            false,
            undefined,
            [0, 1],
        );
        command(
            "pull_" + service,
            expr(
                `${
                    JSON.stringify(docker)
                } + ["image", "pull", "--", steps.specifications.outputs.${service}.image]`,
            ),
            `steps.image_${service}.outputs.exitCode != 0`,
        );
    }
    command(
        "validate_configuration",
        expr(
            `${
                JSON.stringify(docker)
            } + ["container", "run", "--rm", "--interactive", "--entrypoint", "/bin/promtool", "--", steps.specifications.outputs.prometheus.image, "check", "config", "/dev/stdin"]`,
        ),
        "true",
        false,
        `inputs.runtime.public_configuration[${
            JSON.stringify(metricsPaths[0])
        }]`,
    );
    for (
        const [index, path] of [
            ...metricsPaths,
            "/etc/orbit/metrics/.orbit-owner",
        ].entries()
    ) {
        append(
            roleBaselineSteps("snapshot_file_" + index, "file_snapshot", {
                path,
                run_id: expr("inputs.parent_run_id"),
            }, ssh),
        );
    }
    for (const [index, path] of metricsPaths.entries()) {
        append(roleBaselineSteps(
            "publish_file_" + index,
            "file",
            {
                path,
                mode: index === metricsPaths.length - 1 ? "0400" : "0644",
                owner: index === metricsPaths.length - 1
                    ? "472:472"
                    : "root:root",
                content: null,
                material_field: "configuration",
            },
            ssh,
            undefined,
            material,
        ));
    }
    // The canonical ownership marker is public and remains a separate file action.
    append(roleBaselineSteps("owner_marker", "file", {
        path: "/etc/orbit/metrics/.orbit-owner",
        mode: "0640",
        owner: "root:root",
        content: "metrics\n",
    }, ssh));
    for (const service of ["prometheus", "grafana"]) {
        const specification = `steps.specifications.outputs.${service}`;
        command(
            "create_volume_" + service,
            expr(`${JSON.stringify(docker)} + ${specification}.volume_args`),
            `!steps.volume_${service}.outputs.present`,
        );
        command(
            "stop_" + service,
            expr(
                `${
                    JSON.stringify(docker)
                } + ["container", "stop", "--time", "30", "--", ${specification}.name]`,
            ),
            `steps.before_${service}.outputs.present && !steps.before_${service}.outputs.current`,
        );
        command(
            "retain_" + service,
            expr(
                `${
                    JSON.stringify(docker)
                } + ["container", "rename", ${specification}.name, ${specification}.backup]`,
            ),
            `steps.before_${service}.outputs.present && !steps.before_${service}.outputs.current`,
        );
        command(
            "create_" + service,
            expr(`${JSON.stringify(docker)} + ${specification}.args`),
            `!steps.before_${service}.outputs.current`,
        );
        inspect("verify_" + service, service, "container");
        proof(
            "healthy_" + service,
            `steps.verify_${service}.outputs.current`,
            "metrics.runtime_unready",
        );
    }
    metadata("credential_assets", "credential", {
        address: expr("inputs.runtime.address"),
    });
    command(
        "verify_credentials",
        [
            "/usr/bin/python3",
            "-c",
            expr("steps.credential_assets.outputs.script_body"),
        ],
        "true",
        false,
        material,
    );
    metadata("credentials", "decode_ready", {
        stdout: expr("steps.verify_credentials.outputs.stdout"),
    });
    metadata("ready", "prove", {
        prometheus: expr("steps.verify_prometheus.outputs"),
        grafana: expr("steps.verify_grafana.outputs"),
        credentials: expr("steps.credentials.outputs"),
    });
    // Cleanup starts only after both services and the existing credentials are proven.
    for (const service of ["prometheus", "grafana"]) {
        command(
            "cleanup_" + service,
            expr(
                `${
                    JSON.stringify(docker)
                } + ["container", "rm", "--force", "--", steps.specifications.outputs.${service}.backup]`,
            ),
            `steps.retain_${service}.status == "succeeded"`,
        );
    }

    for (
        const [index, path] of [
            ...metricsPaths,
            "/etc/orbit/metrics/.orbit-owner",
        ].entries()
    ) {
        append(
            roleBaselineSteps(
                "cleanup_snapshot_" + index,
                "file_snapshot_cleanup",
                {
                    path,
                    run_id: expr("inputs.parent_run_id"),
                },
                ssh,
            ),
        );
    }

    // Recovery uses immutable action lists and runtime guards, never a future-status fan-out.
    for (
        const [index, path] of [
            ...metricsPaths,
            "/etc/orbit/metrics/.orbit-owner",
        ].entries()
    ) {
        const publication = index === metricsPaths.length
            ? "owner_marker_command"
            : `publish_file_${index}_command`;
        append(
            roleBaselineSteps("restore_file_" + index, "file_restore", {
                path,
                run_id: expr("inputs.parent_run_id"),
            }, ssh),
            `(${failure()}) && "snapshot_file_${index}" in steps && steps.snapshot_file_${index}.status == "succeeded" && ${
                JSON.stringify(publication)
            } in steps && (steps[${
                JSON.stringify(publication)
            }].status in ["succeeded", "failed"]) && !("ready" in steps && steps.ready.status == "succeeded")`,
            true,
        );
    }
    for (const service of ["prometheus", "grafana"]) {
        const specification = `steps.specifications.outputs.${service}`;
        const active =
            `(${failure()}) && !("ready" in steps && steps.ready.status == "succeeded") && "before_${service}" in steps && steps.before_${service}.status == "succeeded"`;
        inspect(
            "recover_current_" + service,
            service,
            "container",
            false,
            true,
        );
        command(
            "remove_candidate_" + service,
            expr(
                `${
                    JSON.stringify(docker)
                } + ["container", "rm", "--force", "--", ${specification}.name]`,
            ),
            `${active} && !steps.before_${service}.outputs.current && steps.recover_current_${service}.outputs.present && steps.recover_current_${service}.outputs.hash == ${specification}.hash`,
            true,
        );
        command(
            "restore_name_" + service,
            expr(
                `${
                    JSON.stringify(docker)
                } + ["container", "rename", ${specification}.backup, ${specification}.name]`,
            ),
            `${active} && "retain_${service}" in steps && steps.retain_${service}.status == "succeeded"`,
            true,
        );
        command(
            "restart_previous_" + service,
            expr(
                `${
                    JSON.stringify(docker)
                } + ["container", "start", "--", ${specification}.name]`,
            ),
            `${active} && "stop_${service}" in steps && steps.stop_${service}.status == "succeeded" && steps.before_${service}.outputs.state == "running"`,
            true,
        );
    }
    for (const service of ["prometheus", "grafana"]) {
        command(
            "restore_volume_" + service,
            expr(
                `${
                    JSON.stringify(docker)
                } + ["volume", "rm", "--", steps.specifications.outputs.${service}.volume]`,
            ),
            `(${failure()}) && !("ready" in steps && steps.ready.status == "succeeded") && "create_volume_${service}" in steps && steps.create_volume_${service}.status == "succeeded" && !steps.volume_${service}.outputs.present`,
            true,
        );
    }
    return [...forward, ...recovery];
}
