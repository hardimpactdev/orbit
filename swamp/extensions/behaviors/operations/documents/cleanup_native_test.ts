/** Pinned native steps execute the cleanup helper against a disposable filesystem. */
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { stringify } from "jsr:@std/yaml@1.0.12";
import { nativeRepo } from "../../../../tests/_support/native_harness.ts";
import { exists } from "./cleanup_fixture_test.ts";
Deno.test("native parent composes cleanup without gateway or agent", async () => {
  await using repo = await nativeRepo();
  await repo.copyBehaviors("operations/documents");
  const node = `${repo.dir}/extensions/behaviors/operations/node`;
  await Deno.mkdir(node);
  await Deno.writeTextFile(
    `${node}/system_command.ts`,
    `export type SystemTransport=(input:any,signal?:AbortSignal)=>Promise<{success:boolean;stdout:string}>;
export const localSystemCommand:SystemTransport=async(command)=>{
 const program=command.args[5].replace("ROOT=pathlib.Path('/run/orbit')","ROOT=pathlib.Path("+JSON.stringify(Deno.cwd()+"/scratch")+")").replace("ROOT_UID=0","ROOT_UID=os.getuid()").replace("ROOT_GID=0","ROOT_GID=os.getgid()").replace("pwd.getpwnam('orbit')","pwd.getpwuid(os.geteuid())");
 const result=await new Deno.Command('python3',{args:['-I','-c',program],stdin:'null',stdout:'piped',stderr:'null'}).output();
 return {success:result.success,stdout:new TextDecoder().decode(result.stdout)};
};`,
  );
  await repo.cli(["workflow", "schema", "get"]);
  await repo.cli(["workflow", "create", "cleanup-parent"]);
  const path = `${repo.dir}/workflows/workflow-cleanup-parent.yaml`;
  const identity = (await Deno.readTextFile(path)).split("id: ")[1].split("\n")[0];
  await Deno.writeTextFile(
    path,
    stringify({
      id: identity,
      name: "cleanup-parent",
      jobs: [{
        name: "startup",
        steps: ["pause-before-fpm", "pause-before-scheduler"].map(
          (name) => ({
            name,
            writes: true,
            task: {
              type: "behavior",
              behaviorType: "@nckrtl/documents-cleanup",
              behaviorName: "documents-cleanup-gateway",
              methodName: "prepare",
              inputs: {},
            },
          }),
        ),
      }],
    }),
  );
  await repo.cli(["workflow", "validate", "cleanup-parent"]);
  await repo.cli(["workflow", "run", "cleanup-parent"]);
  const directory = `${repo.dir}/scratch/project-documents`;
  const state = JSON.parse(
    await Deno.readTextFile(`${directory}/generation.json`),
  );
  assertEquals(
    state.lock_inode,
    Deno.statSync(`${directory}/execution.lock`).ino,
  );
  assertEquals(state.report_id, null);
  assert(!exists(`${directory}/permit.json`));
});
