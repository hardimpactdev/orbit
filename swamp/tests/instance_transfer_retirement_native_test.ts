/** Full production transfer DAG with local effects and a late real-Git dependency. */
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import {workflow, retirement, archive, text, quote, run, git, fixture} from "./_support/transfer_retirement.ts";
import { stringify } from "jsr:@std/yaml@1.0.12";
import { Swamp } from "../extensions/behaviors/node-worker/config.ts";
const binary = Deno.env.get("ORBIT_SWAMP_PROOF_BINARY");
Deno.test({
    name: "pinned native transfer keeps cutover and both Git trees when a moved worktree blocks retirement",
    ignore: !binary,
    async fn() {
        const f = fixture();
        const call = async (...args: string[]) => {
            const done = await new Deno.Command(binary!, {args: [...args, "--json"], cwd: f.folder,
                env: {SWAMP_NO_INBOX_NOTICE: "1"}}).output();
            return {...done, stdout: text(done.stdout), stderr: text(done.stderr)};
        };
        try {
            assert(text((await new Deno.Command(binary!, {args: ["--version"]}).output()).stdout).includes(Swamp.version));
            const initialized = await call("repo", "init", f.folder, "--tool", "none", "--no-vault");
            assert(initialized.success, initialized.stderr + initialized.stdout);
            Deno.mkdirSync(f.folder + "/extensions/models", {recursive: true});
            Deno.writeTextFileSync(f.folder + "/extensions/models/route-proof.ts", `import { z } from "npm:zod@4.4.3";
export const behavior = {type:"@fixture/transfer-route",version:"2026.11.12.1",resources:{result:{schema:z.object({ready:z.boolean()}),lifetime:"1d",garbageCollection:10}},methods:{ready:{description:"Fixture publication",arguments:z.object({}),async execute(_args:unknown,ctx:any){const value={ready:true};await ctx.writeResource("result","result",value);return value;}}}};`);
            Deno.writeTextFileSync(f.folder + "/extensions/models/prepare-proof.ts", `import {z} from "npm:zod@4.4.3";
export const behavior={type:"@fixture/transfer-prepare",version:"2026.11.12.1",resources:{result:{schema:z.object({assets:z.string()}),lifetime:"1d",garbageCollection:10}},methods:{write:{description:"Localize production preparation inputs",arguments:z.object({intent:z.any(),path:z.string()}),async execute(args:any,ctx:any){Deno.writeTextFileSync(${JSON.stringify(f.state + "/intent.json")},JSON.stringify(args.intent));const value={assets:${JSON.stringify(f.assets)}};await ctx.writeResource("result","result",value);return value;}}}};`);
            const route = JSON.parse((await call("workflow", "create", "transfer-route-child")).stdout);
            Deno.writeTextFileSync(route.path, stringify({id:route.id,name:route.name,
                jobs:[{name:"route",steps:[{name:"result",task:{type:"behavior",behaviorType:"@fixture/transfer-route",
                    behaviorName:"transfer-route-proof",methodName:"ready",inputs:{}}}]}]}));
            const created = JSON.parse((await call("workflow", "create", "transfer-retire-proof")).stdout);
            const graph = structuredClone(workflow);
            graph.id = created.id;
            graph.name = created.name;
            delete graph.vaults;
            delete graph.jobs[0].labels;
            for (const step of graph.jobs[0].steps) {
                delete step.labels;
                const name = step.name;
                if (name === "Publish the Route" || name === "Restore the Route") {
                    step.task = {type:"workflow",workflowIdOrName:route.name,inputs:{}};
                    continue;
                }
                if (name === "Prepare source retirement") {
                    step.task = {...step.task,behaviorType:"@fixture/transfer-prepare",behaviorName:"transfer-prepare-proof"};
                    continue;
                }
                let command = "true";
                let env = {};
                if (name === "Archive the checkout") command = f.archiveArgs.map(quote).join(" ");
                if (name === "Check the destination") command = ["git", "-C", f.source, "worktree", "add", "-q", "--detach", f.linked].map(quote).join(" ") + " && mv " + quote(f.linked) + " " + quote(f.moved);
                if (name === "Retire the source") { command = f.command; env = {...f.env, ORBIT_CHECKOUT_INVENTORY:step.task.inputs.env.ORBIT_CHECKOUT_INVENTORY}; }
                if (name === "Clean retirement files") { command = step.task.inputs.run; env = {ORBIT_RUN: f.state}; }
                step.task = {type:"behavior",behaviorType:"command/shell",behaviorName:"transfer-proof-" + name.toLowerCase().replaceAll(/[^a-z0-9]+/g,"-").replace(/-$/, ""),methodName:"execute",inputs:{run:command,env}};
            }
            Deno.writeTextFileSync(created.path, stringify(graph));
            const valid = await call("workflow", "validate", created.name);
            assert(valid.success, valid.stderr + valid.stdout);
            const child = new Deno.Command(binary!, {args:["workflow","run",created.name,"--stdin","--json"],cwd:f.folder,
                env:{SWAMP_NO_INBOX_NOTICE:"1"},stdin:"piped",stdout:"piped",stderr:"piped"}).spawn();
            const writer = child.stdin.getWriter();
            await writer.write(new TextEncoder().encode(JSON.stringify({operation_id:1,node_id:1,kind:"instance.transfer",execution_secrets:{},
                intent:{checkout_instances:f.inventory,source:{source:f.source,ssh_execution:{}},destination:{domain:"fixture.example"},processes:{source:[],destination:[]},setup_steps:[],workloads:[]}})));
            await writer.close();
            await child.output();
            const history = JSON.parse((await call("workflow","history","get",created.name)).stdout);
            assertEquals(history.status, "failed");
            const statuses = Object.fromEntries(history.jobs.flatMap((job:any) => job.steps.map((step:any)=>[step.name,step.status])));
            assertEquals(statuses["Archive the checkout"], "succeeded", JSON.stringify(history));
            assertEquals(statuses["Publish the Route"], "succeeded");
            assertEquals(statuses["Check the destination"], "succeeded", JSON.stringify(history));
            assertEquals(statuses["Retire the source"], "failed");
            assertEquals(statuses["Restore the Route"], "skipped");
            assertEquals(statuses["Discard the destination"], "skipped");
            assertEquals(statuses["Clean retirement files"], "succeeded");
            const retire = history.jobs.flatMap((job:any)=>job.steps).find((step:any)=>step.name === "Clean retirement files");
            assertEquals(JSON.parse(retire.outputs.stdout), {outcome:"refused",data:{error_code:"instance.worktree_dependents",dependents:[{path:f.moved,instance_ids:[23]}]}});
            assertEquals(git(f.source, "rev-parse", "HEAD"), git(f.moved, "rev-parse", "HEAD"));
            assert(!Array.from(Deno.readDirSync(f.folder)).some(entry=>entry.name==="run"));
            assertEquals(Deno.readTextFileSync(f.source + "/work"), "preserve owner work");
        } finally { Deno.removeSync(f.folder, {recursive:true}); }
    },
});
