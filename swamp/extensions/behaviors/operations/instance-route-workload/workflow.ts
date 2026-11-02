import { behaviorName } from "../_lib/behavior_name.ts";
type Fields = Record<string, unknown>;
const expr = (s: string) => "${{ " + s + " }}";
const placement = { labels: { role: "swamp" } };
const failed = 'steps.exists(key, steps[key].status == "failed")';
/** One captured workload phase, using native singleton branches and official actions. */
export function workloadWorkflow(): Fields {
    const steps: Fields[] = [];
    const globals = {
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
    const links = "inputs.intent.plan.links";
    const acl = "inputs.intent.plan.acl";
    const runtime =
        "inputs.intent.plan.runtime != null ? [inputs.intent.plan.runtime] : []";
    const cfgmgmt = {
        nodeHost: expr("inputs.intent.connection.nodeHost"),
        nodeUser: expr("inputs.intent.connection.nodeUser"),
        nodeIdentityFile: expr("inputs.intent.connection.nodeIdentityFile"),
        nodePort: expr("inputs.intent.connection.nodePort"),
        become: true,
    };
    function add(name: string, list: string, task: Fields, extra: Fields = {}) {
        if (task.behaviorType === "@swamp/ssh") task.globalArgs = globals;
        const identity = behaviorName("irw-" + name, task.globalArgs, true);
        task.behaviorName = identity;
        if (task.behaviorType === "@swamp/ssh") {
            task.globalArgs = { ...globals, name: identity };
        }
        steps.push({
            name,
            ...placement,
            forEach: { item: "part", in: expr(list) },
            ...extra,
            dependsOn: previous(),
            guard: expr(failed),
            task,
        });
    }
    // Each served directory's .env links into the shared env tree of the selected release.
    add("links", links, {
        type: "behavior",
        behaviorType: "@swamp/cfgmgmt/link",
        methodName: "apply",
        globalArgs: {
            ...cfgmgmt,
            path: expr("self.part.path"),
            target: expr("self.part.target"),
            owner: expr("self.part.owner"),
            ensure: "present",
        },
        inputs: {},
    });
    // Caddy access by ACL, in order: deny the checkout, open the path, grant the served roots (one setfacl each).
    add("web-root", acl, {
        type: "behavior",
        behaviorType: "@swamp/ssh",
        methodName: "exec",
        inputs: {
            hosts: "name:target",
            sudo: true,
            argv: expr('["timeout", "120"] + self.part.argv'),
            timeoutSec: 130,
        },
    }, { concurrency: 1, writes: true });
    add("runtime-identity", runtime, {
        type: "behavior",
        behaviorType: "@swamp/ssh",
        methodName: "exec",
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
        methodName: "apply",
        globalArgs: {
            ...cfgmgmt,
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
        methodName: "verifyPool",
        inputs: {
            expected: expr("self.part.pool_sha256"),
            state: expr('steps["runtime-pool-0"].outputs'),
        },
    });
    add("runtime-validate", runtime, {
        type: "behavior",
        behaviorType: "@swamp/ssh",
        methodName: "exec",
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
        methodName: "exec",
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
                    "links": expr(`size(${links})`),
                    "web-root": expr(`size(${acl})`),
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
