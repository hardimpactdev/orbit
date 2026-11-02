type Fields = Record<string, unknown>;
const expr = (source: string) => "${{ " + source + " }}";
const placement = { labels: { role: "swamp" } };
const after = (step: string) => [{ step, condition: { type: "succeeded" } }];

/** The enrolled account: two read-only commands over official SSH and one decode on 209. */
export function roleAccountSteps(
    name: string,
    user: string,
    globalArgs: Fields,
    dependency?: string,
): Fields[] {
    const passwd = name + "_command", group = name + "_group";
    const read = (step: string, argv: string, after_?: Fields[]) => ({
        name: step,
        ...placement,
        ...(after_ ? { dependsOn: after_ } : {}),
        task: {
            type: "behavior",
            behaviorType: "@swamp/ssh",
            behaviorName: globalArgs.name,
            methodName: "exec",
            globalArgs,
            inputs: { hosts: "name:target", sudo: false, argv: expr(argv), timeoutSec: 30 },
        },
    });
    return [
        read(passwd, '["getent", "passwd", ' + user + "]", dependency ? after(dependency) : undefined),
        read(group, '["id", "-gn", ' + user + "]", after(passwd)),
        {
            name,
            ...placement,
            dependsOn: after(group),
            task: {
                type: "behavior",
                behaviorType: "@nckrtl/node-role-command",
                behaviorName: name + "-result",
                methodName: "account",
                inputs: {
                    passwd: expr('steps["' + passwd + '"].outputs.stdout'),
                    group: expr('steps["' + group + '"].outputs.stdout'),
                },
            },
        },
    ];
}

/** The pinned Caddy package floor through the shared official cfgmgmt child workflow. */
export function roleCaddyPackageStep(
    name: string,
    caddyPackage: string,
    ssh: string,
    dependency: string,
): Fields {
    return {
        name,
        ...placement,
        writes: true,
        dependsOn: after(dependency),
        task: {
            type: "workflow",
            workflowIdOrName: "node-caddy-package",
            inputs: {
                operation_id: expr("inputs.operation_id"),
                node_id: expr("inputs.node_id"),
                kind: expr("inputs.kind"),
                intent: expr('{"package": ' + caddyPackage + ', "ssh": ' + ssh + "}"),
            },
        },
    };
}

/** The admitted base packages, one official SSH command per step: release check, index refresh, install. */
export function rolePackageSteps(
    name: string,
    prerequisites: string,
    globalArgs: Fields,
    dependency: string,
): Fields[] {
    const ssh = (step: string, argv: string, after_: string): Fields => ({
        name: step,
        ...placement,
        writes: step !== name + "_release",
        dependsOn: after(after_),
        task: {
            type: "behavior",
            behaviorType: "@swamp/ssh",
            behaviorName: globalArgs.name,
            methodName: "exec",
            globalArgs,
            inputs: { hosts: "name:target", sudo: true, argv: expr(argv) },
        },
    });
    const apt = '["/usr/bin/env", "LC_ALL=C", "DEBIAN_FRONTEND=noninteractive", "NEEDRESTART_MODE=l", "/usr/bin/apt-get"';
    return [
        ssh(
            name + "_release",
            '["/usr/bin/grep", "-qxE", "VERSION_CODENAME=(" + ' + prerequisites +
                '.releases.join("|") + ")", "/etc/os-release"]',
            dependency,
        ),
        ssh(name + "_lists", apt + ', "-o", "APT::Update::Error-Mode=any", "update"]', name + "_release"),
        ssh(
            name,
            apt + ', "--no-remove", "install", "--yes", "--no-install-recommends", "--"] + ' + prerequisites + ".packages",
            name + "_lists",
        ),
    ];
}

