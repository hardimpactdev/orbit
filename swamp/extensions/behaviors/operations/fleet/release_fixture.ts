import { parse, stringify } from "jsr:@std/yaml@1.0.12";
import { nativeRepo } from "../../../../tests/_support/native_harness.ts";

export async function fleetReleaseFixture() {
    const repo = await nativeRepo();
    await repo.copyBehaviors("automatic-deploy", "operations", "node-worker");
    await Deno.copyFile(
        new URL("./release_native_fixture.ts", import.meta.url),
        `${repo.dir}/extensions/behaviors/operations/fleet/release_native_fixture.ts`,
    );
    const configPath = `${repo.dir}/.swamp.yaml`;
    const config = parse(await Deno.readTextFile(configPath)) as {
        trustedCollectives: string[];
    };
    config.trustedCollectives.push("fixture");
    await Deno.writeTextFile(configPath, stringify(config));
    const module =
        `${repo.dir}/extensions/behaviors/operations/fleet/release.ts`;
    await Deno.writeTextFile(
        module,
        'import { http } from "./release_native_fixture.ts";\n' +
            (await Deno.readTextFile(module)).replace(
                "export const behavior = fleetRelease();",
                'export const behavior = fleetRelease(() => http, () => new Date("2026-10-31T02:30:00Z"));',
            ),
    );
    await Deno.writeTextFile(
        `${repo.dir}/extensions/behaviors/fleet-host-fixture.ts`,
        'import {z} from "npm:zod@4.4.3";\nconst node=(host:string)=>Number(host.split(".").pop());\nconst load=()=>JSON.parse(Deno.readTextFileSync("fleet-native.json"));const save=(s:any)=>Deno.writeTextFileSync("fleet-native.json",JSON.stringify(s));\nexport const behavior={type:"@fixture/fleet-host",version:"2026.11.02.1",globalArguments:z.object({nodeHost:z.string()}).passthrough(),resources:{output:{schema:z.object({stdout:z.string()}),lifetime:"1d",garbageCollection:10},state:{schema:z.record(z.string(),z.unknown()),lifetime:"1d",garbageCollection:10}},methods:{\napply:{description:"Fake observer fetch",arguments:z.object({}),execute:async(_:any,ctx:any)=>{const s=load();const n=node(ctx.globalArgs.nodeHost);s.effects.push(`observer:${n}`);save(s);\nif(s.observer_failure===n)throw new Error("Checksum mismatch");\nreturn {dataHandles:[await ctx.writeResource("state",ctx.globalArgs.nodeHost,(s.unchanged?{status:"compliant",changes:[]}:{status:"applied",changes:["downloaded"]}))]};}},\nexec:{description:"Fake SSH",arguments:z.object({argv:z.array(z.string())}).passthrough(),execute:async(a:any,ctx:any)=>{const s=load();const n=node(ctx.globalArgs.nodeHost);\nif(a.argv.includes("restart")){s.restarts=(s.restarts||[]).concat([n]);save(s);return {stdout:""};}\nconst out={stdout:`${s.running?.[n]??"b".repeat(64)}  /proc/41/exe\\nactive\\n`};await ctx.writeResource("output","output",out);return out;}}}};',
    );
    await Deno.writeTextFile(
        `${repo.dir}/extensions/behaviors/fleet-cli-fixture.ts`,
        'import {z} from "npm:zod@4.4.3";\nexport const behavior={type:"@fixture/fleet-cli",version:"2026.11.02.1",resources:{result:{schema:z.record(z.string(),z.unknown()),lifetime:"1d",garbageCollection:10}},methods:{\ninstall:{description:"Fake native CLI child",arguments:z.object({node_id:z.number(),version:z.string()}),execute:async(a:{node_id:number;version:string},ctx:any)=>{\nconst state=JSON.parse(await Deno.readTextFile("fleet-native.json"));state.effects.push(`cli:${a.node_id}`);await Deno.writeTextFile("fleet-native.json",JSON.stringify(state));\nif(state.failure===a.node_id&&!state.uncertain)throw new Error("checksum mismatch");\nconst result={installed:a.version,configured:true,version:a.version};await ctx.writeResource("result","result",result);return result;}}}};',
    );

    await Deno.writeTextFile(
        `${repo.dir}/workflows/workflow-node-cli-install.yaml`,
        JSON.stringify({
            id: "440d10ad-68c2-4f59-a3ae-0ce705a84e47",
            name: "node-cli-install",
            inputs: {
                type: "object",
                properties: {
                    node_id: { type: "integer" },
                    intent: { type: "object" },
                },
                required: ["node_id", "intent"],
            },
            jobs: [{
                name: "cli",
                steps: [{
                    name: "result",
                    task: {
                        type: "behavior",
                        behaviorType: "@fixture/fleet-cli",
                        behaviorName: '${{ "cli-" + string(inputs.node_id) }}',
                        methodName: "install",
                        inputs: {
                            node_id: "${{ inputs.node_id }}",
                            version: "${{ inputs.intent.cli.release.version }}",
                        },
                    },
                }],
            }],
        }),
    );
    for (
        const name of [
            "operation-fleet-rollout",
            "operation-fleet-cli-converge",
            "fleet-release-node",
        ]
    ) {
        await repo.workflow(name, (workflow) => {
            // Local execution has no placement after the harness removes labels.
            delete workflow.queueTimeout;
            for (const job of workflow.jobs) {
                for (const step of job.steps) {
                    delete step.target;
                    const task = step.task as {
                        behaviorType?: string;
                        globalArgs?: unknown;
                        inputs: { argv?: unknown };
                    };
                    if (
                        ["@swamp/ssh", "@swamp/cfgmgmt/fetch"].includes(
                            task.behaviorType ?? "",
                        )
                    ) {
                        task.behaviorType = "@fixture/fleet-host";
                        task.globalArgs = {
                            nodeHost:
                                "${{ steps.gate.outputs.ssh.target.host }}",
                        };
                        task.inputs = task.inputs.argv
                            ? { argv: task.inputs.argv }
                            : {};
                    }
                }
            }
        });
    }
    const node = (i: number) => ({
        node_id: i,
        row_id: i,
        user: "node",
        release: {
            version: "0.100.0",
            url: "https://example.com/cli",
            sha256: "a".repeat(64),
        },
        profile: "{}",
        skip_reason: null,
        plan_error: null,
        daytime: true,
        agent: {
            url: "https://example.com/agent",
            sha256: "b".repeat(64),
            version: "0.5.0",
        },
        ssh: {
            control_node_id: 209,
            identity_file: "/etc/orbit/swamp-role-worker/ssh-identity",
            target: {
                host: `10.44.0.${i}`,
                user: "node",
                port: 22,
                host_keys: ["ssh-ed25519 AAAA"],
            },
        },
    });
    const state = {
        bound: true,
        calls: [],
        effects: [],
        failure: null as number | null,
        uncertain: false,
        observer_failure: undefined as number | undefined,
        unchanged: undefined as boolean | undefined,
        running: undefined as Record<string, string> | undefined,
        operation: {
            id: 7,
            kind: "fleet.rollout",
            node_id: 1,
            status: "queued",
            cancel_requested: false,
            result: null,
            input: { nodes: [2, 3, 4].map(node) },
        },
    };
    const run = async (kind = "rollout", success = true) => {
        state.operation.kind = "fleet." + kind.replaceAll("-", "_");
        await Deno.writeTextFile(
            `${repo.dir}/fleet-native.json`,
            JSON.stringify(state),
        );
        const workflow = "operation-fleet-" + kind;
        await repo.cli(["workflow", "validate", workflow]);
        const result = await repo.cli([
            "workflow",
            "run",
            workflow,
            "--input",
            JSON.stringify({
                operation_id: 7,
                node_id: 1,
                kind: state.operation.kind,
            }),
        ], { success });
        return {
            state: JSON.parse(
                await Deno.readTextFile(`${repo.dir}/fleet-native.json`),
            ),
            result,
        };
    };
    return { repo, state, module, run };
}
