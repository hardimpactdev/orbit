type Fields = Record<string, unknown>;
const expr = (s: string) => "${{ " + s + " }}";
const placement = { target: "orbit-node-209", labels: { node: "209" } };
const failed = 'steps.exists(key, steps[key].status == "failed")';
/** One captured workload phase, using native singleton branches and official actions. */
export function workloadWorkflow(): Fields {
    const steps: Fields[] = [];
    const globals = {
        name: expr('"instance-route-workload-" + run.id'),
        transport: {
            kind: "ssh",
            user: expr("inputs.intent.connection.nodeUser"),
            identityFile: expr("inputs.intent.connection.nodeIdentityFile"),
            port: expr("inputs.intent.connection.nodePort"),
        },
        hosts: [{
            name: "target",
            address: expr("inputs.intent.connection.nodeHost"),
            transport: { hostKeys: expr("inputs.intent.host_keys") },
        }],
    };
    const previous = () =>
        steps.map((s) => ({ step: s.name, condition: { type: "always" } }));
    const served =
        "inputs.intent.plan.served_roots != null ? [inputs.intent.plan.served_roots] : []";
    const dev =
        "inputs.intent.plan.source_access != null ? [inputs.intent.plan.source_access] : []";
    const prod = "inputs.intent.plan.served_roots != null ? [{}] : []";
    const runtime =
        "inputs.intent.plan.runtime != null ? [inputs.intent.plan.runtime] : []";
    function add(name: string, list: string, task: Fields) {
        steps.push({
            name,
            ...placement,
            forEach: { item: "part", in: expr(list) },
            dependsOn: previous(),
            guard: expr(failed),
            task,
        });
    }
    function action(name: string, action: string, list: string, input: string) {
        add(name + "-assets", list, {
            type: "behavior",
            behaviorType: "@nckrtl/instance-route-workload",
            behaviorName: name + "-assets",
            methodName: "read",
            inputs: { action, input: expr(input) },
        });
        add(name + "-command", list, {
            type: "behavior",
            behaviorType: "@swamp/ssh",
            behaviorName: globals.name,
            methodName: "script",
            globalArgs: globals,
            inputs: {
                hosts: "name:target",
                interpreter: "python3",
                sudo: true,
                script: expr(`steps["${name}-assets-0"].outputs.script_body`),
            },
        });
        add(name, list, {
            type: "behavior",
            behaviorType: "@nckrtl/instance-route-workload",
            behaviorName: name + "-decode",
            methodName: "decode",
            inputs: {
                stdout: expr(`steps["${name}-command-0"].outputs.stdout`),
            },
        });
    }
    action("served", "served_roots", served, "self.part");
    action("dev-source", "source_access", dev, "self.part");
    action(
        "prod-source",
        "source_access",
        prod,
        '{"user":steps["served-0"].outputs.user,"sites":steps["served-0"].outputs.sites}',
    );
    add("runtime-identity", runtime, {
        type: "behavior",
        behaviorType: "@swamp/ssh",
        behaviorName: globals.name,
        methodName: "exec",
        globalArgs: globals,
        inputs: {
            hosts: "name:target",
            sudo: true,
            argv: [
                "/usr/bin/cmp",
                expr(
                    '"/etc/orbit/php-fpm/" + self.part.user + "/orbit.identity"',
                ),
                "-",
            ],
            stdin: expr("self.part.marker"),
        },
    });
    add("runtime-pool", runtime, {
        type: "behavior",
        behaviorType: "@swamp/cfgmgmt/file",
        behaviorName: expr(
            '"instance-route-pool-" + string(inputs.intent.instance_id)',
        ),
        methodName: "apply",
        globalArgs: {
            nodeHost: expr("inputs.intent.connection.nodeHost"),
            nodeUser: expr("inputs.intent.connection.nodeUser"),
            nodeIdentityFile: expr("inputs.intent.connection.nodeIdentityFile"),
            nodePort: expr("inputs.intent.connection.nodePort"),
            become: true,
            path: expr(
                '"/etc/orbit/php-fpm/" + self.part.user + "/generated/pool.conf"',
            ),
            ensure: "present",
            owner: "root",
            group: "root",
            mode: "0644",
            content: expr("self.part.pool"),
        },
        inputs: {},
    });
    add("runtime-pool-proof", runtime, {
        type: "behavior",
        behaviorType: "@nckrtl/instance-route-workload",
        behaviorName: "runtime-pool-proof",
        methodName: "verifyPool",
        inputs: {
            expected: expr("self.part.pool_sha256"),
            state: expr('steps["runtime-pool-0"].outputs'),
        },
    });
    add("runtime-validate", runtime, {
        type: "behavior",
        behaviorType: "@swamp/ssh",
        behaviorName: globals.name,
        methodName: "exec",
        globalArgs: globals,
        inputs: {
            hosts: "name:target",
            sudo: true,
            argv: [
                "env",
                expr(
                    '"PHP_INI_SCAN_DIR=/etc/php/" + self.part.version + "/fpm/conf.d:/etc/orbit/php-fpm/" + self.part.user + "/generated"',
                ),
                expr('"/usr/sbin/php-fpm" + self.part.version'),
                "-t",
                "--fpm-config",
                expr(
                    '"/etc/orbit/php-fpm/" + self.part.user + "/generated/php-fpm.conf"',
                ),
            ],
        },
    });
    add("runtime-reload", runtime, {
        type: "behavior",
        behaviorType: "@swamp/ssh",
        behaviorName: globals.name,
        methodName: "exec",
        globalArgs: globals,
        inputs: {
            hosts: "name:target",
            sudo: true,
            argv: [
                "/usr/bin/systemctl",
                "reload",
                expr(
                    '"orbit-" + self.part.user + "-php" + self.part.version + "-fpm.service"',
                ),
            ],
        },
    });
    steps.push({
        name: "result",
        ...placement,
        dependsOn: previous(),
        task: {
            type: "behavior",
            behaviorType: "@nckrtl/instance-route-workload",
            behaviorName: "workload-prepared",
            methodName: "prepared",
            inputs: {
                outcomes: expr("steps"),
                required: {
                    "dev-source": expr(`size(${dev})`),
                    "prod-source": expr(`size(${prod})`),
                    "runtime-reload": expr(`size(${runtime})`),
                },
            },
        },
    });
    return {
        id: "e0784b8d-cf13-4d2d-a5b5-bc0d0046b9d3",
        name: "instance-route-workload",
        description:
            "Prepare one captured workload through official SSH, file actions and validate-before-reload.",
        vaults: [],
        inputs: {
            type: "object",
            properties: {
                intent: { type: "object" },
                operation_id: { type: "integer" },
                node_id: { type: "integer" },
                kind: { type: "string" },
                execution_secrets: {
                    type: "object",
                    additionalProperties: false,
                },
            },
            required: [
                "intent",
                "operation_id",
                "node_id",
                "kind",
                "execution_secrets",
            ],
            additionalProperties: false,
        },
        jobs: [{ name: "workload", steps }],
    };
}
