import { allPhases, budgets, phases } from "./plan.ts";
type Fields = Record<string, unknown>;
const ex = (s: string) => "${{ " + s + " }}";
export function swampRoleConvergeSteps(
    name: string,
    intent: string,
    globals: Fields,
    dependency?: string,
    guard?: string,
): Fields[] {
    const key = (value: string) => name ? name + "_" + value : value;
    const placement = { target: "orbit-node-209", queueTimeout: 30 };
    const on = (step: string, type = "succeeded") => ({
        step: key(step),
        condition: { type },
    });
    const stage = ex(
        '"/tmp/orbit-swamp-role-' + (name ? name + "-" : "") + '"+run.id',
    );
    const steps: Fields[] = [{
        name: key("worker_assets"),
        ...placement,
        ...(dependency
            ? {
                dependsOn: [{
                    step: dependency,
                    condition: { type: "succeeded" },
                }],
            }
            : {}),
        task: {
            type: "behavior",
            behaviorType: "@nckrtl/node-worker-assets",
            methodName: "read",
            inputs: { node_id: 209, user: "orbit" },
        },
    }, {
        name: key("assets"),
        ...placement,
        dependsOn: [on("worker_assets")],
        task: {
            type: "behavior",
            behaviorType: "@nckrtl/node-swamp-role-assets",
            methodName: "bundle",
            inputs: {
                intent: ex(intent),
                worker: ex(
                    `steps[${JSON.stringify(key("worker_assets"))}].outputs`,
                ),
            },
        },
    }, {
        name: key("prepare_assets"),
        ...placement,
        dependsOn: [on("assets")],
        task: {
            type: "behavior",
            behaviorType: "@swamp/ssh",
            methodName: "exec",
            globalArgs: globals,
            inputs: {
                hosts: "name:target",
                argv: ["/usr/bin/timeout", "30", "/usr/bin/mkdir", "-p", stage],
                timeoutSec: 40,
            },
        },
    }, {
        name: key("copy_assets"),
        ...placement,
        dependsOn: [on("prepare_assets")],
        task: {
            type: "behavior",
            behaviorType: "@swamp/ssh",
            methodName: "copy",
            globalArgs: globals,
            inputs: {
                hosts: "name:target",
                src: ex(
                    `steps[${
                        JSON.stringify(key("assets"))
                    }].outputs.directory+"/."`,
                ),
                dst: stage,
                direction: "to",
                recursive: true,
            },
        },
    }];
    for (const [index, phase] of phases.entries()) {
        if (phase === "activation") {
            steps.push({
                name: key("firewall"),
                ...placement,
                writes: true,
                allowFailure: true,
                dependsOn: [on("units")],
                guard: ex(`!${intent}.activate`),
                task: {
                    type: "behavior",
                    behaviorType: "@swamp/cfgmgmt/firewall",
                    methodName: "apply",
                    inputs: {},
                    globalArgs: {
                        nodeHost: ex(`${intent}.address`),
                        nodeUser: (globals.transport as Fields)?.user,
                        nodePort: (globals.transport as Fields)?.port,
                        nodeIdentityFile: (globals.transport as Fields)
                            ?.identityFile,
                        become: true,
                        port: 9417,
                        action: "allow",
                        ensure: "present",
                        source: ex(`${intent}.services_proxy_source`),
                    },
                },
            });
        }
        const budget = budgets[phase];
        steps.push({
            name: key(phase),
            ...placement,
            writes: true,
            allowFailure: true,
            dependsOn: [
                on(
                    phase === "activation"
                        ? "firewall"
                        : index
                        ? phases[index - 1]
                        : "copy_assets",
                ),
            ],
            ...(["activation", "verify"].includes(phase)
                ? { guard: ex(`!${intent}.activate`) }
                : {}),
            task: {
                type: "behavior",
                behaviorType: "@swamp/ssh",
                methodName: "exec",
                globalArgs: globals,
                inputs: {
                    hosts: "name:target",
                    sudo: true,
                    timeoutSec: budget + 10,
                    argv: [
                        "/usr/bin/timeout",
                        String(budget),
                        "/usr/bin/python3",
                        "-B",
                        ex(
                            '"/tmp/orbit-swamp-role-' +
                                (name ? name + "-" : "") + '"+run.id+"/' +
                                phase + '.py"',
                        ),
                        stage,
                    ],
                    ...(phase === "install"
                        ? {
                            stdin: ex(
                                `steps[${
                                    JSON.stringify(key("assets"))
                                }].outputs.install_json`,
                            ),
                        }
                        : {}),
                },
            },
        });
    }
    steps.push({
        name: key("role_result"),
        ...placement,
        dependsOn: steps.map((s) => ({
            step: s.name,
            condition: { type: "always" },
        })),
        task: {
            type: "behavior",
            behaviorType: "@nckrtl/node-swamp-role-assets",
            methodName: "complete",
            inputs: {
                intent: ex(intent),
                statuses: Object.fromEntries(
                    allPhases.map((
                        phase,
                    ) => [
                        phase,
                        ex(`${JSON.stringify(key(phase))} in steps ? steps[${
                            JSON.stringify(key(phase))
                        }].status : "skipped"`),
                    ]),
                ),
            },
        },
    });
    steps.push({
        name: key("accepted"),
        ...placement,
        dependsOn: [on("role_result")],
        task: {
            type: "assert",
            expr: `steps[${
                JSON.stringify(key("role_result"))
            }].outputs.prepared && (!${intent}.activate || steps[${
                JSON.stringify(key("role_result"))
            }].outputs.role_proven)`,
            severity: "high",
            message:
                "Swamp role contribution failed; see named phase status and stderr",
        },
    });
    for (const step of steps) {
        const task = step.task as Fields;
        if (task.type === "behavior") {
            task.behaviorName = ex(
                JSON.stringify("swamp-role-" + step.name + "-") + "+run.id",
            );
        }
    }
    if (guard) {
        for (const step of steps) {
            step.guard = ex(
                `(${guard}) || (${
                    typeof step.guard === "string"
                        ? step.guard.slice(3, -2)
                        : "false"
                })`,
            );
        }
    }
    return steps;
}
