import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { join } from "jsr:@std/path@1.1.4";
import { DatabaseSync } from "node:sqlite";
import { compact } from "../bin/compact-services.ts";

Deno.test("native state compaction preserves latest, exact live versions and receipt-referenced runs", async () => {
  const root=await Deno.makeTempDir({prefix:"orbit-retention-"});
  const command=async (...args:string[])=>{
    const out=await new Deno.Command("swamp",{args:[...args,"--repo-dir",root,"--json"],cwd:root,env:{SWAMP_SERVER_URL:"",SWAMP_SERVE_URL:"",SWAMP_NO_UPDATE_CHECK:"1",SWAMP_NO_TELEMETRY:"1"},stdout:"piped",stderr:"piped"}).output();
    assert(out.success,new TextDecoder().decode(out.stderr));return JSON.parse(new TextDecoder().decode(out.stdout));
  };
  try {
    await Deno.copyFile(".swamp.yaml",join(root,".swamp.yaml"));
    await Deno.mkdir(join(root,"extensions/behaviors"),{recursive:true});
    await Deno.writeTextFile(join(root,"extensions/behaviors/retention.ts"),`
import {z} from "npm:zod@4";
export const behavior={type:"@nckrtl/orbit-group-run",version:"2026.10.28.1",globalArguments:z.object({}),resources:{
 tick:{schema:z.object({v:z.number()}),lifetime:"infinite",garbageCollection:1},
 launch:{schema:z.object({runId:z.string()}),lifetime:"infinite",garbageCollection:1}},
 methods:{seed:{description:"Seed native resource versions",arguments:z.object({}),execute:async(_a:any,c:any)=>{
 for(let v=1;v<=5;v++)await c.writeResource("tick","tick",{v});
 await c.writeResource("launch","launch-tg-7-script",{runId:"11111111-1111-4111-8111-111111111111"});return {dataHandles:[]};}}}};
`);
    await command("behavior","create","@nckrtl/orbit-group-run","orbit-group-tg-7");
    await command("behavior","run","orbit-group-tg-7","seed");
    const dbPath=join(root,".swamp/data/_catalog.db");
    const db=new DatabaseSync(dbPath);
    const row=db.prepare("SELECT * FROM catalog WHERE data_name='tick' AND version=2").get() as Record<string,unknown>;
    db.close();
    const runDir=join(root,".swamp/workflow-runs/fixture");
    await Deno.mkdir(runDir,{recursive:true});
    const run=(id:string,status:string,extra:Record<string,unknown>={})=>Deno.writeTextFile(join(runDir,`workflow-run-${id}.yaml`),JSON.stringify({id,status,startedAt:"2020-01-01T00:00:00Z",completedAt:"2020-01-01T00:01:00Z",...extra}));
    await run("22222222-2222-4222-8222-222222222222","suspended",{dataArtifacts:[{dataId:row.id,name:"tick",version:2}]});
    await run("11111111-1111-4111-8111-111111111111","succeeded");
    await run("33333333-3333-4333-8333-333333333333","succeeded");
    const preview=await compact(root);
    assertEquals(preview.apply,false);
    assertEquals(preview.runs,1);
    assertEquals(preview.versions,3);
    await compact(root,true);
    const tick=join(root,".swamp/data",String(row.type_normalized),String(row.model_id),"tick");
    const exists=async(p:string)=>await Deno.stat(p).then(()=>true,()=>false);
    assert(await exists(join(tick,"2/raw")));
    assert(await exists(join(tick,"5/raw")));
    assert(!await exists(join(tick,"1/raw")));
    assert(await exists(join(runDir,"workflow-run-11111111-1111-4111-8111-111111111111.yaml")));
    assert(!await exists(join(runDir,"workflow-run-33333333-3333-4333-8333-333333333333.yaml")));
    // Native query rebuilds its derived catalog and reads the surviving latest.
    const current=await command("data","query",'modelName == "orbit-group-tg-7" && name == "tick"',"--select","attributes");
    assertEquals(current.results,[{v:5}]);
    const second=await compact(root);
    assertEquals(second.versions,0);
    assertEquals(second.runs,0);
    // The scheduled model must use actual native ModelType objects and keep
    // the shared catalog alive. Re-seed more history after the offline pass.
    await command("behavior","run","orbit-group-tg-7","seed");
    await Deno.mkdir(join(root,"bin"),{recursive:true});
    await Deno.copyFile("bin/compact-services.ts",join(root,"bin/compact-services.ts"));
    await Deno.writeTextFile(join(root,"extensions/behaviors/runtime_retention.ts"),
      (await Deno.readTextFile("extensions/behaviors/runtime_retention.ts")).replace("Date.now()","Date.now()+2*3600000"));
    await command("behavior","create","@nckrtl/runtime-retention","runtime-retention");
    const writer = join(root,".swamp/outputs/active-writer.yaml");
    await Deno.mkdir(join(root,".swamp/outputs"),{recursive:true});
    await Deno.writeTextFile(writer,JSON.stringify({id:crypto.randomUUID(),definitionId:row.model_id,status:"running",startedAt:"2020-01-01T00:00:00Z"}));
    await command("behavior","run","runtime-retention","prune");
    assert(await exists(join(tick,"5/raw")),"native prune must exclude an active model writer");
    await Deno.remove(writer);
    await command("behavior","run","runtime-retention","prune");
    assert(await exists(dbPath),"online native pruning must retain the shared catalog");
    assert(await exists(join(tick,"2/raw")),"live exact version must survive native pruning");
    assert(!await exists(join(tick,"5/raw")),"superseded unreferenced version must be pruned natively");
    assert(await exists(join(tick,"10/raw")),"latest version must survive native pruning");
    // Fail closed on a redirected datastore; never follow it to another tree.
    await Deno.writeTextFile(join(root,".swamp.yaml"),'datastore: remote\n');
    await assertRejects(()=>compact(root,true),Error,"custom_datastore_refused");
  } finally {await Deno.remove(root,{recursive:true});}
});

Deno.test("offline compaction refuses serve started from elsewhere with --repo-dir", async () => {
  const root=await Deno.makeTempDir();
  const child=new Deno.Command("python3",{args:["-c","import time; print('ready',flush=True); time.sleep(20)","swamp","serve","--repo-dir",root],cwd:"/tmp",stdout:"piped",stderr:"null"}).spawn();
  try {
    await child.stdout.getReader().read();
    await assertRejects(()=>compact(root,true),Error,"swamp_process_active");
  } finally {child.kill("SIGTERM");await child.status;await Deno.remove(root,{recursive:true});}
});
