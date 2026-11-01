import {
    expr,
    failed,
    type Fields,
    placement,
    proof,
    RoleComposition,
    sshStep,
    succeeded,
} from "./composition.ts";
import { roleBaselineSteps } from "./steps.ts";
const unit = "/etc/systemd/system/orbit-websocket.service";
function runtimeAction(
    name: string,
    action: string,
    input: Fields,
    globals: Fields,
    observe = false,
): Fields[] {
    const asset = name + "_assets", command = name + "_command";
    const task = {
        type: "behavior",
        behaviorType: "@nckrtl/node-role-runtime-assets",
        behaviorName: asset,
        methodName: "read",
        inputs: { action, input },
    };
    return [{ name: asset, ...placement, task }, {
        name: command,
        ...placement,
        writes: true,
        dependsOn: [{ step: asset, condition: { type: "succeeded" } }],
        task: {
            type: "behavior",
            behaviorType: "@swamp/ssh",
            behaviorName: globals.name,
            methodName: "script",
            globalArgs: globals,
            inputs: {
                hosts: "name:target",
                interpreter: "python3",
                sudo: true,
                script: expr(
                    `steps[${JSON.stringify(asset)}].outputs.script_body`,
                ),
            },
        },
    }, {
        name,
        ...placement,
        dependsOn: [{ step: command, condition: { type: "succeeded" } }],
        task: {
            type: "behavior",
            behaviorType: observe
                ? "@nckrtl/node-role-runtime-assets"
                : "@nckrtl/node-role-converge-command",
            behaviorName: name,
            methodName: observe ? "observe" : "decode",
            inputs: {
                stdout: expr(
                    `steps[${JSON.stringify(command)}].outputs.stdout`,
                ),
            },
        },
    }];
}
export function roleWebSocketRestoreSteps(
    name: string,
    forwardName: string,
    runtime: string,
    globals: Fields,
    dependency?: string,
    active = "true",
): Fields[] {
    const c = new RoleComposition(dependency, undefined, true),
        before = `steps[${JSON.stringify(forwardName + "_before")}].outputs`;
    const applicable = `(${active}) && (${succeeded(forwardName + "_before")})`;
    c.append([
        sshStep(name + "_stop", globals, [
            "/usr/bin/systemctl",
            "stop",
            "orbit-websocket",
        ]),
    ], applicable);
    c.append(
        runtimeAction(name + "_link", "websocket_link", {
            install_path: expr(runtime + ".install_path"),
            release: expr(before + ".release"),
            previous: expr(before + ".previous"),
            restore: true,
        }, globals),
        applicable,
    );
    c.append(
        roleBaselineSteps(name + "_unit", "file_restore", {
            path: unit,
            run_id: expr("run.id"),
        }, globals),
        applicable + " && (" + succeeded(forwardName + "_unit_snapshot") + ")",
    );
    c.append([
        sshStep(name + "_reload", globals, [
            "/usr/bin/systemctl",
            "daemon-reload",
        ]),
    ], applicable);
    c.append([
        sshStep(name + "_restart", globals, [
            "/usr/bin/systemctl",
            "start",
            "orbit-websocket",
        ]),
    ], applicable + " && " + before + ".active");
    c.append([
        sshStep(name + "_enable", globals, [
            "/usr/bin/systemctl",
            "enable",
            "orbit-websocket",
        ]),
    ], applicable + " && " + before + ".enabled");
    c.append([
        sshStep(name + "_disable", globals, [
            "/usr/bin/systemctl",
            "disable",
            "orbit-websocket",
        ]),
    ], applicable + " && !" + before + ".enabled");
    c.append([
        sshStep(name + "_active", globals, [
            "/usr/bin/systemctl",
            "is-active",
            "--quiet",
            "orbit-websocket",
        ]),
    ], applicable + " && " + before + ".active");
    c.append(
        runtimeAction(name + "_listener", "websocket_verify", {
            port: expr(runtime + ".port"),
        }, globals),
        applicable + " && " + before + ".active",
    );
    c.append([
        proof(
            name,
            `!(${failed(c.steps.map((s) => String(s.name)))})`,
            "Prior WebSocket runtime must be restored",
        ),
    ], applicable);
    return c.steps;
}
export function roleWebSocketSteps(
    name: string,
    runtime: string,
    globals: Fields,
    material: string,
    account: string,
    dependency?: string,
): Fields[] {
    const c = new RoleComposition(dependency),
        before = `steps[${JSON.stringify(name + "_before")}].outputs`,
        who = `steps[${JSON.stringify(account)}].outputs`;
    c.append(
        runtimeAction(
            name + "_before",
            "websocket_observe",
            {
                install_path: expr(runtime + ".install_path"),
                run_id: expr("run.id"),
            },
            globals,
            true,
        ),
    );
    c.append(
        roleBaselineSteps(name + "_unit_snapshot", "file_snapshot", {
            path: unit,
            run_id: expr("run.id"),
        }, globals),
    );
    c.append(
        runtimeAction(name + "_directory", "websocket_directory", {
            release: expr(before + ".release"),
            user: expr(who + ".user"),
            group: expr(who + ".group"),
        }, globals),
    );
    const asUser = [
        "/usr/bin/sudo",
        "-u",
        expr(who + ".user"),
        "-H",
        "/usr/bin/env",
        expr('"HOME=" + ' + who + ".home"),
    ];
    c.append([
        sshStep(name + "_git_init", globals, [
            ...asUser,
            "/usr/bin/git",
            "-C",
            expr(before + ".release"),
            "init",
        ]),
    ]);
    c.append([
        sshStep(name + "_git_fetch", globals, [
            ...asUser,
            "/usr/bin/git",
            "-C",
            expr(before + ".release"),
            "fetch",
            "--depth",
            "1",
            expr(runtime + ".repository"),
            expr(runtime + ".ref"),
        ]),
    ]);
    c.append([
        sshStep(name + "_git_checkout", globals, [
            ...asUser,
            "/usr/bin/git",
            "-C",
            expr(before + ".release"),
            "checkout",
            "--detach",
            "FETCH_HEAD",
        ]),
    ]);
    c.append([
        sshStep(name + "_composer", globals, [
            ...asUser,
            "/usr/bin/composer",
            "--working-dir",
            expr(before + ".release"),
            "install",
            "--no-dev",
            "--no-interaction",
            "--prefer-dist",
            "--optimize-autoloader",
        ]),
    ]);
    c.append([
        sshStep(name + "_artisan_file", globals, [
            "/usr/bin/test",
            "-f",
            expr(before + '.release+"/artisan"'),
        ]),
    ]);
    c.append([
        sshStep(name + "_vendor_directory", globals, [
            "/usr/bin/test",
            "-d",
            expr(before + '.release+"/vendor"'),
        ]),
    ]);
    c.append(
        roleBaselineSteps(
            name + "_environment",
            "file",
            {
                path: expr(before + '.release+"/.env"'),
                owner: expr(who + '.user+":"+' + who + ".group"),
                mode: "0600",
                content: null,
                material_field: "websocket_environment",
                marker: null,
            },
            globals,
            undefined,
            material,
        ),
    );
    c.append([
        sshStep(name + "_artisan_verify", globals, [
            ...asUser,
            "/usr/bin/php",
            expr(before + '.release+"/artisan"'),
            "orbit:reverb:start",
            "--help",
        ]),
    ]);
    c.append([{
        name: name + "_unit_render",
        ...placement,
        task: {
            type: "behavior",
            behaviorType: "@nckrtl/node-role-runtime-assets",
            behaviorName: name + "_unit_render",
            methodName: "unit",
            inputs: {
                path: expr(runtime + ".install_path"),
                user: expr(who + ".user"),
                group: expr(who + ".group"),
                port: expr(runtime + ".port"),
            },
        },
    }]);
    c.append(roleBaselineSteps(name + "_unit_candidate", "file", {
        path: expr(before + '.release+"/orbit-websocket.service"'),
        owner: "root:root",
        mode: "0600",
        content: expr('steps["' + name + '_unit_render"].outputs.content'),
        marker: null,
    }, globals));
    c.append([
        sshStep(name + "_unit_verify", globals, [
            "/usr/bin/systemd-analyze",
            "verify",
            expr(before + '.release+"/orbit-websocket.service"'),
        ]),
    ]);
    c.append([
        sshStep(name + "_stop", globals, [
            "/usr/bin/systemctl",
            "stop",
            "orbit-websocket",
        ]),
    ], before + ".active");
    c.append(
        roleBaselineSteps(name + "_unit", "file", {
            path: unit,
            owner: "root:root",
            mode: "0644",
            content: expr(
                `steps[${
                    JSON.stringify(name + "_unit_render")
                }].outputs.content`,
            ),
            marker: "# Managed by Orbit.",
        }, globals),
    );
    c.append(
        runtimeAction(name + "_link", "websocket_link", {
            install_path: expr(runtime + ".install_path"),
            release: expr(before + ".release"),
            previous: expr(before + ".previous"),
            restore: false,
        }, globals),
    );
    c.append([
        sshStep(name + "_reload", globals, [
            "/usr/bin/systemctl",
            "daemon-reload",
        ]),
    ]);
    c.append([
        sshStep(name + "_enable", globals, [
            "/usr/bin/systemctl",
            "enable",
            "orbit-websocket",
        ]),
    ]);
    c.append([
        sshStep(name + "_restart", globals, [
            "/usr/bin/systemctl",
            "restart",
            "orbit-websocket",
        ]),
    ]);
    c.append([
        sshStep(name + "_active", globals, [
            "/usr/bin/systemctl",
            "is-active",
            "--quiet",
            "orbit-websocket",
        ]),
    ]);
    c.append(
        runtimeAction(name + "_listener", "websocket_verify", {
            port: expr(runtime + ".port"),
            install_path: expr(runtime + ".install_path"),
            release: expr(before + ".release"),
            unit: expr('steps["' + name + '_unit_render"].outputs.content'),
        }, globals),
    );
    c.append([
        proof(
            name,
            `!(${failed(c.steps.map((s) => String(s.name)))}) && (${
                succeeded(name + "_listener")
            })`,
            "WebSocket runtime readiness must be observed",
        ),
    ]);
    const failure = failed(c.steps.map((s) => String(s.name)));
    const restore = roleWebSocketRestoreSteps(
        name + "_recover",
        name,
        runtime,
        globals,
        String(c.steps.at(-1)!.name),
        `(${failure}) && !(${succeeded(name)})`,
    );
    return [...c.steps, ...restore];
}
