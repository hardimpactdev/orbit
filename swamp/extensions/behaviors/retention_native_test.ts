import { assertEquals, assertFalse } from "jsr:@std/assert@1.0.19";
import { expandGlob } from "jsr:@std/fs@1.0.19";
import { nativeRepo, ROOT } from "../../tests/_support/native_harness.ts";

Deno.test("native GC removes superseded versions and retains current completion content", async () => {
  await using repo = await nativeRepo();
  const config = await Deno.readTextFile(`${ROOT}/.swamp.yaml`);
  await Deno.writeTextFile(
    `${repo.dir}/.swamp.yaml`,
    config.replace("autoGc: true", "autoGc: false").replace("automatic: true", "automatic: false"),
  );
  await Deno.mkdir(`${repo.dir}/extensions/behaviors`, { recursive: true });
  await Deno.writeTextFile(
    `${repo.dir}/extensions/behaviors/receipt.ts`,
    `import {z} from "npm:zod@4.4.3";
export const behavior={type:"@nckrtl/retention-proof",version:"2026.10.30.1",
resources:{receipt:{description:"Current completion content",schema:z.object({status:z.literal("succeeded"),operationId:z.number()}),lifetime:"infinite",garbageCollection:1}},
methods:{save:{description:"Publish a completion",arguments:z.object({operationId:z.number()}),execute:async(a,c)=>({dataHandles:[await c.writeResource("receipt","completion",{status:"succeeded",operationId:a.operationId})]})}}};`,
  );
  await repo.cli(["behavior", "create", "@nckrtl/retention-proof", "receipt-proof"]);
  const save = (operationId: number) =>
    repo.cli(["behavior", "run", "receipt-proof", "save", "--input", JSON.stringify({ operationId })]);
  for (const id of [1, 2, 3]) await save(id);
  const files = await Array.fromAsync(expandGlob(`${repo.dir}/.swamp/data/**/completion/3/raw`));
  assertEquals(files.length, 1);
  const current = files[0].path, parent = current.slice(0, -"3/raw".length);
  const before = await Deno.readFile(current);
  await Deno.stat(`${parent}1/raw`);
  await Deno.writeTextFile(`${repo.dir}/.swamp.yaml`, config);
  await repo.cli(["data", "gc", "--dry-run"]);
  await repo.cli(["data", "gc", "--force"]);
  const exists = async (path: string) => await Deno.stat(path).then(() => true, () => false);
  for (const id of [1, 2]) assertFalse(await exists(`${parent}${id}/raw`));
  assertEquals(await Deno.readFile(current), before);
  assertEquals(
    JSON.parse(
      (await repo.cli([
        "data",
        "query",
        'modelName == "receipt-proof" && name == "completion"',
        "--select",
        "attributes",
      ])).stdout,
    ).results,
    [{ status: "succeeded", operationId: 3 }],
  );
  await save(4);
  assertFalse(await exists(current));
  await Deno.stat(`${parent}4/raw`);
});
