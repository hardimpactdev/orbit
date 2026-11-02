/** Reuse the native Node footprint boundaries; no Node program runs inside role callbacks. */
type Fields = Record<string, unknown>;
const ex = (s: string) => "${{ " + s + " }}";
export const nativeFootprintStages = [
    "private-dns",
    "route-residue",
    "php-runtime",
    "tmpfiles",
] as const;
const resources =
    "steps.prepare_footprint.outputs.snapshot.footprint_resources";
const snapshot = "steps.prepare_footprint.outputs.snapshot.footprint";
const nativeName = (stage: string) =>
    ({
        "private-dns": "Install private DNS",
        "route-residue": "Clear Route residue",
        "php-runtime": "Configure PHP-FPM",
        tmpfiles: "Write the tmpfiles rule",
    })[stage]!;
const field = (stage: string) =>
    ({
        "private-dns": "dns",
        "route-residue": "residue",
        "php-runtime": "php_configurations",
        tmpfiles: "tmpfiles",
    })[stage]!;
export function nativeFootprintJob(stage: string, previous: string): Fields {
    const name = nativeName(stage);
    const source = resources + "." + field(stage);
    const inputs = {
        operation_id: ex("inputs.operation_id"),
        node_id: ex("inputs.node_id"),
        kind: ex("inputs.kind"),
    };
    let task: Fields;
    if (stage === "tmpfiles") {
        task = {
            type: "behavior",
            behaviorType: "@swamp/cfgmgmt/file",
            behaviorName: ex('"role-tmpfiles-" + string(inputs.node_id)'),
            methodName: "apply",
            globalArgs: {
                path: "/etc/tmpfiles.d/orbit.conf",
                ensure: "present",
                content: ex(source + ' == null ? "" : ' + source),
                owner: "root",
                group: "root",
                mode: "0644",
                become: true,
                nodeHost: ex(resources + ".ssh.target.host"),
                nodeUser: ex(resources + ".ssh.target.user"),
                nodePort: ex(resources + ".ssh.target.port"),
                nodeIdentityFile: ex(resources + ".ssh.identity_file"),
            },
            inputs: {},
        };
    } else {
        const intent = stage === "php-runtime"
            ? {
                ssh: ex(resources + ".ssh"),
                php: ex("self.php"),
            }
            : ex(
                '{"ssh": ' + resources + '.ssh, "user": ' + resources +
                    '.user, "home": ' + resources +
                    '.home, "agent_sha256": steps.prepare_footprint.outputs.snapshot.agent_sha256, "' +
                    field(stage) + '": ' + source + "}",
            );
        task = {
            type: "workflow",
            workflowIdOrName: stage === "php-runtime"
                ? "node-php-runtime"
                : stage === "private-dns"
                ? "node-private-dns"
                : "node-route-residue",
            inputs: { ...inputs, intent },
        };
    }
    return {
        name: stage.replaceAll("-", "_") + "_job",
        dependsOn: [{ job: previous, condition: { type: "succeeded" } }],
        steps: [{
            name: name +
                (stage === "php-runtime" ? "-${{ self.php.version }}" : ""),
            labels: { role: "swamp" },
            writes: true,
            ...(stage === "route-residue" ? { allowFailure: true } : {}),
            ...(stage === "php-runtime"
                ? {
                    forEach: {
                        item: "php",
                        in: ex(
                            "has(steps.prepare_footprint) && has(steps.prepare_footprint.outputs) ? " +
                                source + " : []",
                        ),
                    },
                }
                : {
                    guard: ex(
                        "!steps.admit.outputs.ready || " + source + " == null",
                    ),
                }),
            task,
        }],
    };
}
/** The existing typed role projection consumes native status, never a reporting behavior. */
export function nativeFootprintOutcome(stage: string): string {
    const name = nativeName(stage);
    const artifact = `${snapshot}.artifacts.filter(a, a.name == "${stage}")`;
    const state = `steps["${name}"]`;
    const has = `"${name}" in steps`;
    const phpState = `steps["${name}-" + php.version]`;
    const success = stage === "php-runtime"
        ? `${resources}.php_configurations.all(php, "${name}-" + php.version in steps && ${phpState}.status == "succeeded")`
        : `${resources}.${
            field(stage)
        } == null || (${has} && ${state}.status == "succeeded")`;
    const seen = stage === "php-runtime"
        ? `${resources}.php_configurations.size() == 0 || ${resources}.php_configurations.exists(php, "${name}-" + php.version in steps && ${phpState}.status in ["succeeded", "failed", "skipped"])`
        : `${resources}.${
            field(stage)
        } == null || (${has} && ${state}.status in ["succeeded", "failed", "skipped"])`;
    const failure = stage === "route-residue"
        ? `{"stage": "${stage}", "ok": true, "entry": {"name": "route-residue", "status": "skipped", "reason": "route_residue_cleanup_failed"}}`
        : `{"stage": "${stage}", "ok": false, "error_code": "node.footprint_failed", "failed_artifact": "${stage}"}`;
    // CEL maps cannot use +; construct the optional entry with a conditional whole result.
    const good =
        `(${artifact}.size() == 0 ? {"stage": "${stage}", "ok": true} : {"stage": "${stage}", "ok": true, "entry": {"name": "${stage}", "status": ${artifact}[0].required ? "applied" : "unchanged"}})`;
    return `(has(steps.prepare_footprint) && has(steps.prepare_footprint.outputs) && (${seen}) ? [(${success}) ? ${good} : ${failure}] : [])`;
}
