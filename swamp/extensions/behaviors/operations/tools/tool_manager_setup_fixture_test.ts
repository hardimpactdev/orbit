import { assertEquals } from "jsr:@std/assert@1.0.19";
import { nativeRepo } from "../../../../tests/_support/native_harness.ts";
import { assertPlacement, type Task } from "./tool_fixture_test.ts";

export async function managerSetupFixture() {
    const repo = await nativeRepo();
    repo.timeoutMs = 120_000;
    await repo.copyBehaviors("operations/tools", "operations/node-bootstrap");
    await repo.pull("@swamp/ssh");
    await repo.pull("@swamp/cfgmgmt");
    const userResult = await new Deno.Command("id", {
        args: ["-un"],
        stdout: "piped",
    }).output();
    const user = new TextDecoder().decode(userResult.stdout).trim();
    const host = `${repo.dir}/host`,
        scope = `${host}/composer`,
        state = `${repo.dir}/installed`,
        audit = `${repo.dir}/calls`,
        tools = `${repo.dir}/tools`;
    await Deno.mkdir(host);
    await Deno.mkdir(tools);
    repo.env = {
        PATH: `${tools}:${Deno.env.get("PATH")}`,
        STATE: state,
        CALLS: audit,
    };
    const scripts: Record<string, string> = {
        ssh: `if(Deno.args.includes("-M")||Deno.args.includes("-O"))Deno.exit(0);
const command=Deno.args.at(-1)!.replaceAll("/opt/orbit/composer",${
            JSON.stringify(scope)
        }).replaceAll("/var/cache/orbit/tool-setup",${
            JSON.stringify(`${host}/cache`)
        }).replaceAll("/usr/bin/composer",${
            JSON.stringify(`${tools}/composer`)
        });
const r=await new Deno.Command("/bin/sh",{args:["-c",command],stdin:"inherit",stdout:"inherit",stderr:"inherit"}).output();Deno.exit(r.code);`,
        scp: `Deno.copyFileSync(Deno.args.at(-2)!,Deno.args.at(-1)!.split(":").slice(1).join(":"));`,
        sudo:
            `const args=Deno.args.slice(Deno.args.indexOf("--")+1);const r=await new Deno.Command(args[0],{args:args.slice(1),stdin:"inherit",stdout:"inherit",stderr:"inherit"}).output();Deno.exit(r.code);`,
        chown: "",
        chgrp: "",
        composer:
            `try{Deno.statSync(Deno.env.get("STATE")!);}catch{Deno.exit(127);}console.log("Composer version 2.9.5");`,
        "apt-get":
            `Deno.writeTextFileSync(Deno.env.get("CALLS")!,JSON.stringify(Deno.args)+"\\n",{append:true});if(Deno.env.get("APT_FAIL")==="1")Deno.exit(1);if(Deno.args.includes("install"))Deno.writeTextFileSync(Deno.env.get("STATE")!,"2.9.5-1");`,
        "dpkg-query":
            `let version;try{version=Deno.readTextFileSync(Deno.env.get("STATE")!);}catch{Deno.exit(1);}console.log("install ok installed "+version);`,
    };
    for (const [name, source] of Object.entries(scripts)) {
        await repo.fakeBinary(`tools/${name}`, source);
    }
    assertPlacement("operation-tool-manager-setup");
    await repo.workflow("operation-tool-manager-setup", (w) => {
        for (const job of w.jobs) {
            for (const step of job.steps) {
                const task = step.task as Task;
                if (task.behaviorType === "@swamp/ssh") {
                    (task.globalArgs as Record<string, unknown>).sshBinary =
                        `${tools}/ssh`;
                }
            }
        }
    });
    const input = {
        operation_id: 9901,
        node_id: 701,
        ssh: {
            target: {
                host: "fixture",
                user,
                port: 22,
                host_keys: [
                    "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEB",
                ],
            },
            identity_file: "/fixture/key",
        },
        converge: false,
        intent: {
            manager: "composer",
            user,
            platform: "linux",
            architecture: "x86_64",
            setup: {
                manager: "composer",
                input: { user, version: "2.9.5-1" },
                apt: {
                    snapshot: "20260101T000000Z",
                    packages: [{
                        name: "composer",
                        version: "2.9.5-1",
                        artifact: null as null | Record<string, unknown>,
                    }],
                },
                packages: [],
            },
        },
    };
    const run = async (success = true) => {
        await repo.cli([
            "workflow",
            "validate",
            "operation-tool-manager-setup",
        ]);
        const value = await repo.runWorkflow(
            "operation-tool-manager-setup",
            input,
            { success },
        );
        assertEquals(value.run.status, success ? "succeeded" : "failed");
        return value;
    };
    return {
        repo,
        scope,
        state,
        audit,
        input,
        run,
        async [Symbol.asyncDispose]() {
            await repo[Symbol.asyncDispose]();
        },
    };
}
