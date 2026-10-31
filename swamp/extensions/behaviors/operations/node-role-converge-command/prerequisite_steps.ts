import {
    roleActionSteps,
    rolePackageSteps,
} from "../node-role-command/steps.ts";
import { roleBaselineSteps } from "./steps.ts";

type Fields = Record<string, unknown>;
const expr = (source: string) => "${{ " + source + " }}";
const placement = { target: "orbit-node-209", labels: { node: "209" } };

/** Public prerequisites only. The root retains managers, runtimes and publication. */
export function rolePrerequisiteSteps(
    name: string,
    intent: string,
    fullGlobals: Fields,
    dependency?: string,
): Fields[] {
    const steps: Fields[] = [];
    let previous = dependency;
    function append(group: Fields[], applicable = "true") {
        group[0].dependsOn = previous
            ? [{ step: previous, condition: { type: "always" } }]
            : [];
        for (const step of group) {
            const predecessors = [
                ...(dependency ? [dependency] : []),
                ...steps.map((prior) => String(prior.name)),
            ];
            const failed = predecessors.length
                ? `steps.exists(key, ${
                    JSON.stringify(predecessors)
                }.exists(prior, key == prior || key.startsWith(prior + "-")) && steps[key].status in ["failed", "cancelled"])`
                : "false";
            step.guard = expr(`!(${applicable}) || (${failed})`);
            steps.push(step);
        }
        previous = String(group[group.length - 1].name);
    }
    const account = name + "_account";
    append(
        roleActionSteps(
            account,
            "account",
            '{"user": ' + intent + ".user}",
            fullGlobals,
        ),
    );
    append(
        roleActionSteps(
            name + "_caddy",
            "caddy_package",
            intent + ".caddy_package",
            fullGlobals,
        ),
        intent + ".caddy_package != null",
    );
    const user = expr(`steps[${JSON.stringify(account)}].outputs.user`);
    const group = expr(`steps[${JSON.stringify(account)}].outputs.group`);
    const home = expr(`steps[${JSON.stringify(account)}].outputs.home`);
    append(
        roleBaselineSteps(name + "_storage", "storage", {
            root: expr(
                intent +
                    `.storage_root == null ? steps[${
                        JSON.stringify(account)
                    }].outputs.home + "/apps" : ` + intent + ".storage_root",
            ),
            user,
            group,
        }, fullGlobals),
        intent + '.role == "app-dev"',
    );
    append(
        rolePackageSteps(
            name + "_packages",
            intent + ".prerequisites",
            fullGlobals,
            String(previous),
        ),
        `size(${intent}.prerequisites.packages) > 0`,
    );
    append(
        roleBaselineSteps(name + "_apps_directory", "account_directory", {
            scope: "apps",
            user,
            group,
            home,
        }, fullGlobals),
        intent + ".prerequisites.apps_directory",
    );
    for (
        const scope of [
            "composer_parent",
            "composer_home",
            "composer_vendor",
            "composer_bin",
        ]
    ) {
        append(
            roleBaselineSteps(name + "_" + scope, "account_directory", {
                scope,
                user,
                group,
                home,
            }, fullGlobals),
            intent + ".prerequisites.composer_home",
        );
    }
    append(
        roleBaselineSteps(name + "_composer_manifest", "composer_manifest", {
            user,
            group,
        }, fullGlobals),
        intent + ".prerequisites.composer_home",
    );
    append([{
        name: name + "_composer_verify",
        ...placement,
        task: {
            type: "behavior",
            behaviorType: "@swamp/ssh",
            behaviorName: fullGlobals.name,
            methodName: "exec",
            globalArgs: fullGlobals,
            inputs: {
                hosts: "name:target",
                sudo: true,
                argv: [
                    "/usr/bin/sudo",
                    "-u",
                    user,
                    "-H",
                    "env",
                    "COMPOSER_HOME=/opt/orbit/composer",
                    "/usr/bin/composer",
                    "--version",
                    "--no-ansi",
                ],
            },
        },
    }], intent + ".prerequisites.composer_home");
    for (
        const [suffix, path] of [["markers", "/dev/shm/orbit/hibernation"], [
            "logs",
            "/data/caddy/orbit/hibernation",
        ]]
    ) {
        append(
            roleBaselineSteps(name + "_hibernation_" + suffix, "directory", {
                path,
            }, fullGlobals),
            intent + '.role == "app-dev"',
        );
    }
    for (const resource of ["pool", "project", "acl", "network", "profile"]) {
        append(
            roleBaselineSteps(name + "_ci_" + resource, "ci_resource", {
                resource,
            }, fullGlobals),
            intent + '.role == "ci-runner"',
        );
    }
    return steps;
}
