import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse, stringify } from "jsr:@std/yaml@1.0.12";
import { nativeRepo, ROOT } from "../../../../tests/_support/native_harness.ts";

export const exists = (path: string) => {
    try {
        Deno.statSync(path);
        return true;
    } catch {
        return false;
    }
};
export const read = (path: string) => JSON.parse(Deno.readTextFileSync(path));
export const calls = (path: string): string[][] =>
    Deno.readTextFileSync(path).trim().split("\n").map((line) =>
        JSON.parse(line)
    );
export type Task = {
    type?: string;
    behaviorType?: string;
    globalArgs?: Record<string, unknown> | string;
    inputs?: Record<string, unknown>;
};
export const ssh = {
    identity_file: "/fixture",
    target: {
        host: "node.invalid",
        port: 22,
        user: "orbit",
        host_keys: [
            "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG",
        ],
    },
};

/** Check production placement before the local harness strips it. */
export function assertPlacement(name: string) {
    const workflow = parse(
        Deno.readTextFileSync(`${ROOT}/workflows/workflow-${name}.yaml`),
    ) as { jobs: { steps: { labels: unknown }[] }[] };
    for (const job of workflow.jobs) {
        for (const step of job.steps) {
            assertEquals(step.labels, { role: "swamp" });
        }
    }
}

export async function toolFixture() {
    const repo = await nativeRepo();
    repo.timeoutMs = 120_000;
    await repo.copyBehaviors("operations/tools", "operations/node-bootstrap");
    await repo.pull("@swamp/ssh");
    const state = `${repo.dir}/state.json`, audit = `${repo.dir}/audit.jsonl`;
    // Ask a shell to decode the official SSH quoting, without executing its command.
    const transport = await repo.fakeBinary(
        "fixture-ssh",
        `
const path = ${JSON.stringify(state)}, audit = ${JSON.stringify(audit)};
const s = JSON.parse(Deno.readTextFileSync(path));
const command = Deno.args.at(-1)!;
const decoded = await new Deno.Command("/bin/sh", {args:["-c", "set -- " + command + '; printf "%s\\\\0" "$@"'], stdout:"piped"}).output();
const argv = new TextDecoder().decode(decoded.stdout).split("\\0").slice(0,-1);
Deno.writeTextFileSync(audit, JSON.stringify(argv)+"\\n", {append:true});
let code=0, changed=false;
if (argv.includes("getent")) console.log("orbit:x:1000:1000::/home/orbit:/bin/sh");
else if (argv.includes("--version") && s.manager_missing) code=127;
else if (argv.some(p=>p.endsWith("/bin/brew"))) {
 if(argv.includes("info")) console.log(JSON.stringify({formulae:[{name:"hello",full_name:"hello",tap:"homebrew/core",disabled:false,installed:s.installed?[{version:s.installed,installed_on_request:true}]:[],versions:{stable:"2.0",bottle:true},bottle:{stable:{files:{x86_64_linux:{sha256:"a".repeat(64),url:"https://ghcr.io/v2/homebrew/core/hello/sha256:"+"a".repeat(64)}}}}}]}));
 else if(argv.includes("uninstall")){s.installed=null;changed=true;}
 else if(argv.includes("install")||argv.includes("upgrade")){s.installed="2.0";changed=true;}
 else if(argv.includes("--version")) console.log("Homebrew 4.0");
} else if(argv.includes("/usr/bin/dpkg-query")) {
 if(s.installed) console.log("hello\\tinstall ok installed\\t"+s.installed);
 else {console.error("dpkg-query: no packages found matching hello");code=1;}
} else if(argv.includes("/usr/bin/apt-cache")) {
 if(!s.requires_refresh||s.refreshed) console.log("hello:\\n  Candidate: "+s.candidate);
} else if(argv.includes("/usr/bin/apt-get")&&argv.includes("update")){s.refreshed=true;changed=true;}
else if(argv.includes("--simulate")) console.log("Remv hello [1.0]\\n"+(s.unsafe?"Remv other [1.0]\\n":""));
else if(argv.includes("install")){changed=true;if(s.no_write){}else if(s.fail_write)code=1;else s.installed=argv.at(-1)!.split("=")[1];}
else if(argv.includes("--remove")){s.installed=null;changed=true;}
else if(argv.includes("--version"))console.log("apt 3.0");
if(changed){const pending=path+"."+Deno.pid;Deno.writeTextFileSync(pending,JSON.stringify(s));Deno.renameSync(pending,path);}
Deno.exit(code);
`,
    );
    await Deno.mkdir(`${repo.dir}/worker-bin`);
    for (const name of ["curl", "gpg"]) {
        await repo.fakeBinary(
            `worker-bin/${name}`,
            `
const s=JSON.parse(Deno.readTextFileSync(${
                JSON.stringify(state)
            })), key=Deno.env.get("ORBIT_KEY_FILE")!;
if(${
                JSON.stringify(name)
            }==="curl"){Deno.mkdirSync(key.slice(0,key.lastIndexOf("/")),{recursive:true});Deno.writeTextFileSync(key+".asc","fixture key");}
else if(Deno.args.includes("--show-keys"))console.log("pub:::::::::\\nfpr:::::::::"+(s.bad_key?"B":"A").repeat(40)+":");
else Deno.writeFileSync(key+".gpg",new Uint8Array([153,...new TextEncoder().encode("fixture binary key")]));
`,
        );
    }
    repo.env.PATH = `${repo.dir}/worker-bin:${Deno.env.get("PATH")}`;
    const config = parse(
        await Deno.readTextFile(`${repo.dir}/.swamp.yaml`),
    ) as { trustedCollectives: string[] };
    config.trustedCollectives.push("fixture");
    await Deno.writeTextFile(`${repo.dir}/.swamp.yaml`, stringify(config));
    // Explicitly install this fixture behavior; copyBehaviors skips *_fixture_test.ts.
    await Deno.writeTextFile(
        `${repo.dir}/extensions/behaviors/config.ts`,
        `import {z} from "npm:zod@4.4.3";
export const behavior={type:"@fixture/config",version:"2026.11.03.1",globalArguments:z.record(z.string(),z.unknown()),resources:{state:{schema:z.record(z.string(),z.unknown()),lifetime:"1d",garbageCollection:10}},methods:Object.fromEntries(["check","apply","refresh"].map(method=>[method,{description:"Transport fixture",arguments:z.object({}),execute:async(_,ctx)=>{
const g=ctx.globalArgs,s=JSON.parse(Deno.readTextFileSync(${
            JSON.stringify(state)
        }));
Deno.writeTextFileSync(${
            JSON.stringify(audit)
        },JSON.stringify(["cfgmgmt",g.path??"package",...(g.revision?[g.revision]:[])])+"\\n",{append:true});
if(g.repo&&method==="apply"){if(s.fail_setup)throw new Error("Pinned clone failed");s.manager_missing=false;Deno.writeTextFileSync(${
            JSON.stringify(state)
        },JSON.stringify(s));}
const installed=Boolean(s.installed),compliant=g.ensure==="absent"?!installed:installed;
return {dataHandles:[await ctx.writeResource("state",g.nodeHost,{status:compliant?"compliant":"non_compliant",packages:g.packages?[{name:g.packages[0],installed,version:s.installed}]:[]})]};}}]))};`,
    );
    const edit = (task: Task) => {
        if (task.behaviorType?.startsWith("@swamp/cfgmgmt/")) {
            task.behaviorType = "@fixture/config";
        }
        if (typeof task.globalArgs === "string") {
            task.globalArgs = task.globalArgs.replaceAll(
                '"transport":',
                `"sshBinary":${JSON.stringify(transport)},"transport":`,
            );
        } else if (task.globalArgs && "transport" in task.globalArgs) {
            task.globalArgs.sshBinary = transport;
        }
    };
    for (const suffix of ["write-209", "remove-209", "manager-setup"]) {
        assertPlacement(`operation-tool-${suffix}`);
        await repo.workflow(`operation-tool-${suffix}`, (w) => {
            for (const job of w.jobs) {
                for (const step of job.steps) {
                    const task = step.task as Task;
                    if (step.name === "Set up the Tool Manager") {
                        assertEquals(task.inputs!.report, false);
                    }
                    edit(task);
                }
            }
            // Worker-local key paths must be private to this disposable repository.
            Object.assign(
                w,
                JSON.parse(
                    JSON.stringify(w).replaceAll(
                        "/tmp/orbit-tool-keys/",
                        `${repo.dir}/worker-keys/`,
                    ),
                ),
            );
        });
    }
    const save = (value: Record<string, unknown>) =>
        Deno.writeTextFileSync(state, JSON.stringify(value));
    const runKind = async (
        kind = "tool.install",
        success = true,
        initial = {},
        intent = {},
    ) => {
        save({ installed: null, candidate: "2.0-1", ...initial });
        const name = `operation-tool-${
            kind === "tool.remove" ? "remove" : "write"
        }-209`;
        await repo.cli(["workflow", "validate", name]);
        const run = await repo.runWorkflow(name, {
            operation_id: 1,
            node_id: 9,
            kind,
            execution_secrets: {},
            ssh,
            intent: {
                kind,
                node_id: 9,
                manager: "apt",
                package: "hello",
                user: "orbit",
                platform: "linux",
                architecture: "x86_64",
                tool_id: null,
                setup: null,
                repository: null,
                repository_given: false,
                remove_repository: false,
                manager_ready: true,
                ...intent,
            },
        }, { success });
        const steps = run.history.jobs.flatMap((j) => j.steps);
        assert(!steps.some((s) => s.name === "result"));
        const result: Record<string, unknown> = {
            ...steps.find((s) => s.name === "Choose package action")?.outputs,
            ...steps.find((s) => s.name === "Decode package state")?.outputs,
        };
        if (kind === "tool.remove" && run.run.status === "succeeded") {
            result.outcome = "applied";
        }
        if (run.steps["Assert the key fingerprint"] === "failed") {
            result.error_code = "tool.repository_key_mismatch";
        }
        return { ...run, result };
    };
    return {
        repo,
        state,
        audit,
        save,
        runKind,
        async [Symbol.asyncDispose]() {
            await repo[Symbol.asyncDispose]();
        },
    };
}
