import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1.0.19";
import { HEAD, MERGE, mergeFixture } from "./native_fixture_test.ts";
Deno.test("lost reply reconciles on fresh run and emits the native receipt", async () => {
  await using f = await mergeFixture();
  const initial = f.read();
  initial.lost_reply = true;
  Deno.writeTextFileSync(f.state, JSON.stringify(initial));
  const first = await f.run(false);
  assertEquals(first.data.calls.filter((c: string[]) => c[0] === "PUT").length, 1);
  const reconciled = f.read();
  reconciled.calls = [];
  Deno.writeTextFileSync(f.state, JSON.stringify(reconciled));
  const { history, data } = await f.run();
  assertEquals(data.calls.map((c: string[]) => c[0]), ["GET", "DELETE", "DELETE"]);
  const step = history.jobs.flatMap((
    j: { steps: { name: string; outputs: { stdout: string } }[] },
  ) => j.steps).find((s: { name: string }) => s.name === "Read merge result");
  assertEquals(JSON.parse(step.outputs.stdout), {
    merged: true,
    head_sha: HEAD,
    merge_sha: MERGE,
  });
});
Deno.test("major change refuses and revokes", async () => {
  await using f = await mergeFixture();
  f.save("major");
  const { history, data } = await f.run(false);
  assert(!data.calls.some((c: string[]) => c[0] === "PUT"));
  assertStringIncludes(
    JSON.stringify(history),
    "dependency_merge.major_bump",
  );
});
