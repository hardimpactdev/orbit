import { roleActionSteps } from "../node-role-command/steps.ts";

type Fields = Record<string, unknown>;
const expr = (value: string) => "${{ " + value + " }}";
const placement = { target: "orbit-node-209" };
const after = (names: string[], optional: Set<string>) =>
    names.map((step) => ({
        step,
        condition: {
            ...(optional.has(step)
                ? {
                    type: "or",
                    conditions: [{ type: "succeeded" }, { type: "skipped" }],
                }
                : { type: "succeeded" }),
        },
    }));

/** Fixed Node enrollment software actions. Every version comes from the immutable intent. */
export function roleToolSteps(
    name: string,
    intent: string,
    fullGlobals: Fields,
    dependency?: string,
    guard?: string,
    options: { sudoExpr?: string } = {},
): Fields[] {
    const optional = new Set([
        name + "_caddy_assets",
        name + "_caddy_command",
        name + "_caddy",
        name + "_javascript",
        name + "_composer",
        name + "_browser",
    ]);
    const stage = '"/tmp/orbit-node-tools-" + run.id + "-' + name + '"';
    const assets = name + "_assets";
    const metadata = (methodName: string, inputs: Fields) => ({
        type: "behavior",
        behaviorType: "@nckrtl/node-enrollment-tools",
        behaviorName: name + "-assets",
        methodName,
        inputs,
    });
    const ssh = (
        step: string,
        methodName: string,
        inputs: Fields,
        dependencies: string[],
    ) => ({
        name: step,
        ...placement,
        writes: true,
        dependsOn: after(dependencies, optional),
        task: {
            type: "behavior",
            behaviorType: "@swamp/ssh",
            behaviorName: fullGlobals.name,
            methodName,
            globalArgs: fullGlobals,
            inputs: { hosts: "name:target", ...inputs },
        },
    });
    const steps: Fields[] = [{
        name: assets,
        ...placement,
        ...(dependency ? { dependsOn: after([dependency], optional) } : {}),
        task: metadata("read", { intent: expr(intent) }),
    }];
    steps.push(
        ssh(name + "_stage", "exec", { argv: ["mkdir", "-p", expr(stage)] }, [
            assets,
        ]),
    );
    steps.push(
        ssh(name + "_copy", "copy", {
            src: expr('steps["' + assets + '"].outputs.scripts_directory'),
            dst: expr(stage + ' + "/"'),
            direction: "to",
            recursive: true,
        }, [name + "_stage"]),
    );
    const installer = (
        manager: string,
        script: string,
        dependencies: string[],
    ) => {
        const step = ssh(name + "_" + manager, "exec", {
            argv: [
                "/usr/bin/python3",
                "-B",
                expr(stage + ' + "/tool_scripts/' + script + '"'),
            ],
            stdin: expr('steps["' + assets + '"].outputs.' + manager + "_json"),
            sudo: options.sudoExpr ? expr(options.sudoExpr) : true,
        }, dependencies) as Fields;
        if (manager !== "apt") {
            step.guard = expr(intent + "." + manager + " == null");
        }
        return step;
    };
    steps.push(installer("apt", "install_apt.py", [name + "_copy"]));
    const caddy = roleActionSteps(
        name + "_caddy",
        "caddy_package",
        intent + ".caddy",
        fullGlobals,
        name + "_apt",
    );
    for (const step of caddy) step.guard = expr(intent + ".caddy == null");
    steps.push(...caddy);
    const complete = [name + "_apt", ...caddy.map((step) => String(step.name))];
    steps.push(installer("javascript", "install_javascript.py", complete));
    complete.push(name + "_javascript");
    steps.push(installer("composer", "prepare_composer.py", complete));
    complete.push(name + "_composer");
    steps.push(installer("browser", "install_browser.py", complete));
    complete.push(name + "_browser");
    steps.push(
        ssh(
            name + "_cleanup",
            "exec",
            { argv: ["rm", "-rf", expr(stage)] },
            complete,
        ),
    );
    steps.push({
        name,
        ...placement,
        dependsOn: after([...complete, name + "_cleanup"], optional),
        task: metadata("result", {
            intent: expr(intent),
            apt: expr('steps["' + name + '_apt"].outputs.stdout'),
            javascript: expr(
                intent + '.javascript == null ? null : steps["' + name +
                    '_javascript"].outputs.stdout',
            ),
            composer: expr(
                intent + '.composer == null ? null : steps["' + name +
                    '_composer"].outputs.stdout',
            ),
            browser: expr(
                intent + '.browser == null ? null : steps["' + name +
                    '_browser"].outputs.stdout',
            ),
            caddy: expr(
                intent + '.caddy == null ? null : steps["' + name +
                    '_caddy"].outputs',
            ),
        }),
    });
    if (guard) {
        for (const step of steps) {
            step.guard = expr(
                "(" + guard + ")" +
                    (step.guard
                        ? " || (" + String(step.guard).slice(4, -3) + ")"
                        : ""),
            );
        }
    }
    return steps;
}
