import { type NativeRepo, ROOT } from "./native_harness.ts";

/** Keep native group logic; replace its systemd worker boundary with native nested calls. */
export async function localGroupWorker(repo: NativeRepo) {
  // Group 8 waits once; group 10 stays active so discovery can detect its factory conflict.
  const stage = await Deno.readTextFile(`${ROOT}/tests/fixtures/group-runs/stage.ts`);
  await Deno.writeTextFile(`${repo.dir}/extensions/behaviors/fixture_group_stage.ts`, stage
    .replace('if (!await ctx.readResource(`job-${args.key}`)) {', 'const saved = await ctx.readResource(`job-${args.key}`); if (!saved) {')
    .replace('state: "succeeded",', 'state: ["tg-8", "tg-10"].includes(args.workItem) ? "running" : "succeeded",')
    .replace('return { dataHandles: [] };', 'if (saved && args.workItem === "tg-8") await ctx.writeResource("job", `job-${args.key}`, { ...saved, state: "succeeded" }); return { dataHandles: [] };'));
  const host = `${repo.dir}/extensions/behaviors/native_host_method.ts`;
  const source = await Deno.readTextFile(host);
  await Deno.writeTextFile(host, source.replace('"/home/orbit/apps/orbit-monorepo-staging/checkout/swamp"', JSON.stringify(repo.dir)));
  await Deno.writeTextFile(`${repo.dir}/extensions/behaviors/orbit_group_run.ts`, `
// The disposable serve has no systemd worker. Use the existing invokeModel fixture seam.
for (const method of Object.values(behavior.methods)) {
  const execute = method.execute;
  method.execute = (args, ctx) => execute(args, { ...ctx, invokeModel: (request) => ctx.runModel(request) });
}
`, { append: true });
}
