import { parse, stringify } from "jsr:@std/yaml@1.0.12";
import { nativeRepo, ROOT } from "../../../../tests/_support/native_harness.ts";

type Caddy = {
    error_code?: string;
    stage?: string;
    content?: string;
    version?: string;
    listen_addresses?: unknown[];
    unserved?: boolean;
};
type Payloads = {
    agent: {
        configuration: string;
        certificate: string;
        unit: string;
        apps_root: null;
        checkouts: unknown[];
        url: string;
    };
    tmpfiles: { rule: string };
    caddy?: Caddy;
    "php-runtime"?: {
        configurations: { version: string; runtime_ini?: string }[];
    };
    "private-dns"?: {
        listener: Record<string, unknown>;
        publication: { records: string; catalog: string };
    };
    "route-residue"?: Record<string, unknown>;
};
type RecipeIntent = {
    node_id: number;
    identity: string;
    snapshot: string;
    agent_sha256: string;
    cli_version: string;
    automatic: boolean;
    daytime: boolean;
    recipe: Record<string, unknown>;
    php: Record<string, unknown> | null;
    incus_hold: unknown[];
    cli: Record<string, unknown>;
    ssh: Record<string, unknown>;
    footprint: {
        schema_version: number;
        node_id: number;
        agent_sha256: string;
        force_artifacts: boolean;
        first_visit: boolean;
        refusal: string | null;
        network: null;
        artifacts: {
            name: string;
            digest: string;
            required: boolean;
            repair: boolean;
        }[];
        payloads: Payloads;
    };
};

export async function recipeFixture() {
    const repo = await nativeRepo();
    repo.timeoutMs = 120_000;
    await repo.copyBehaviors(
        "operations/fleet-recipe",
        "operations/node-role-command",
        "operations/node-role-converge-command",
        "operations/node-bootstrap",
        "operations/fleet",
        "operations/node",
        "operations/_lib",
        "operations/routes",
        "operations/tools",
        "operations/certificates",
        "node-worker",
        "automatic-deploy",
    );
    // copyBehaviors excludes fixture modules; install the behavior explicitly.
    await Deno.copyFile(
        new URL("./native_config_fixture.ts", import.meta.url),
        `${repo.dir}/extensions/behaviors/operations/fleet-recipe/native_config_fixture.ts`,
    );
    const configPath = `${repo.dir}/.swamp.yaml`;
    const config = parse(await Deno.readTextFile(configPath)) as {
        trustedCollectives: string[];
    };
    config.trustedCollectives.push("fixture");
    await Deno.writeTextFile(configPath, stringify(config));
    await repo.workflow("operation-fleet-recipe-direct", (workflow) => {
        // These disposable workflows run locally after the harness removes labels.
        delete workflow.queueTimeout;
        for (const job of workflow.jobs) {
            for (const step of job.steps) {
                delete step.target;
                const task = step.task as {
                    type: string;
                    behaviorType?: string;
                    globalArgs?: Record<string, unknown>;
                    inputs?: Record<string, unknown>;
                    expr?: string;
                };
                if (task.behaviorType === "@swamp/ssh") {
                    task.behaviorType = "@fixture/recipe-ssh";
                    delete task.globalArgs;
                } else if (task.behaviorType?.startsWith("@swamp/cfgmgmt/")) {
                    task.behaviorType = "@fixture/recipe-config";
                    task.globalArgs = Object.fromEntries(
                        Object.entries(task.globalArgs!).filter(([key]) =>
                            ["nodeHost", "path", "service"].includes(key)
                        ),
                    );
                }
                if (JSON.stringify(task).includes("vault.get") && task.inputs) {
                    (task.inputs.identity as Record<string, unknown>).expected =
                        "a".repeat(64);
                }
                if (task.type === "assert") {
                    task.expr = task.expr!.replaceAll(
                        "vault.get(inputs.execution_secrets.observer.vault,inputs.execution_secrets.observer.key)",
                        '"synthetic-observer-reference"',
                    );
                }
            }
        }
    });
    for (
        const [number, name] of [
            "operation-route-caddy",
            "operation-fleet-peer-dns",
            "route-certificate-remove",
            "route-lan-apply",
            "node-caddy-package",
            "node-caddy-sites",
            "node-private-dns",
            "node-php-runtime",
            "node-route-residue",
            "node-cli-install",
        ].entries()
    ) {
        const source = parse(
            await Deno.readTextFile(`${ROOT}/workflows/workflow-${name}.yaml`),
        ) as { inputs: unknown };
        await Deno.writeTextFile(
            `${repo.dir}/workflows/workflow-${name}.yaml`,
            JSON.stringify({
                id: `c9d03e50-2fe8-4ab8-83bf-${
                    String(number).padStart(12, "0")
                }`,
                name,
                inputs: source.inputs,
                jobs: [{
                    name: "fake",
                    steps: [{
                        name: "apply",
                        task: name.startsWith("node-")
                            ? {
                                type: "behavior",
                                behaviorType: "@fixture/recipe-ssh",
                                behaviorName: name,
                                methodName: "exec",
                                inputs: { argv: ["native-child", name] },
                            }
                            : {
                                type: "assert",
                                expr: "true",
                                message: "Fixture child succeeded",
                            },
                    }],
                }],
            }),
        );
    }
    const intent: RecipeIntent = {
        "node_id": 9,
        "identity":
            "sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
        "snapshot": "20261031T000000Z",
        "agent_sha256":
            "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
        "cli_version": "0.12.0",
        "automatic": false,
        "daytime": false,
        "recipe": {
            "user": "orbit",
            "snapshot": "20261031T000000Z",
            "tools": {},
        },
        "php": null,
        "incus_hold": [],
        "footprint": {
            "schema_version": 1,
            "node_id": 9,
            "agent_sha256":
                "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
            "force_artifacts": true,
            "first_visit": false,
            "refusal": null,
            "network": null,
            "artifacts": [{
                "name": "agent",
                "digest":
                    "cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc",
                "required": true,
                "repair": false,
            }, {
                "name": "tmpfiles",
                "digest":
                    "cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc",
                "required": true,
                "repair": false,
            }],
            "payloads": {
                "agent": {
                    "configuration": "public-config",
                    "certificate": "public-ca",
                    "unit": "[Service]\nProtectHome=yes\n",
                    "apps_root": null,
                    "checkouts": [],
                    "url": "https://delivery.test/observer",
                },
                "tmpfiles": { "rule": "e /tmp/orbit-* - - - 1d" },
            },
        },
        "cli": {
            "user": "orbit",
            "release": {
                "version": "0.12.0",
                "url": "https://delivery.test/orbit",
                "sha256":
                    "dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd",
            },
            "profile": "public-profile",
            "skip_reason": null,
            "plan_error": null,
        },
        "ssh": {
            "control_node_id": 209,
            "identity_file": "/synthetic",
            "target": {
                "host": "10.44.0.9",
                "user": "orbit",
                "port": 22,
                "host_keys": ["synthetic-host-key"],
            },
        },
    };
    const save = (extra: Record<string, unknown> = {}) =>
        Deno.writeTextFile(
            `${repo.dir}/fixture.json`,
            JSON.stringify({ calls: [], ...extra }),
        );
    const calls = (): string[] => {
        try {
            return Deno.readTextFileSync(`${repo.dir}/calls.jsonl`).trim()
                .split("\n").filter(Boolean).map((line) => JSON.parse(line));
        } catch (e) {
            if (e instanceof Deno.errors.NotFound) return [];
            throw e;
        }
    };
    const start = async (success = true) => {
        const fp = intent.footprint;
        const payloads = fp.payloads;
        const php = Object.fromEntries(
            (payloads["php-runtime"]?.configurations ?? []).map((
                part: { version: string; runtime_ini?: string },
            ) => [part.version, part.runtime_ini ?? "public ini"]),
        );
        const caddy = payloads.caddy;
        Object.assign(fp, {
            roles: {
                user: "orbit",
                home: "/home/orbit",
                caddy_package: null,
                caddy: caddy?.error_code ? null : caddy ?? null,
                php,
                php_versions: Object.keys(php),
                dns: payloads["private-dns"] ?? null,
                tmpfiles: payloads.tmpfiles?.rule ?? null,
                residue: payloads["route-residue"] ?? null,
            },
            agent: {
                url: payloads.agent.url,
                files: [{
                    path: "/etc/orbit/agent/config.toml",
                    content: "public-config",
                }, { path: "/etc/orbit/agent/ca.pem", content: "public-ca" }],
                unit: payloads.agent.unit,
                checkouts: [],
            },
            verifier_hash: "a".repeat(64),
            footprint_check: "a".repeat(64) + "  /etc/orbit/agent/secret\n",
        });
        await repo.cli([
            "workflow",
            "validate",
            "operation-fleet-recipe-direct",
        ]);
        return await repo.runWorkflow("operation-fleet-recipe-direct", {
            operation_id: 7,
            node_id: intent.node_id,
            kind: "fleet.recipe",
            intent,
            execution_secrets: {},
        }, { success });
    };
    await save();
    return { repo, intent, save, calls, start };
}
