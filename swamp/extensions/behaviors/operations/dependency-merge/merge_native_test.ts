import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1.0.19";
import { HEAD, MERGE, mergeFixture } from "./native_fixture_test.ts";
Deno.test("merge and native receipt", async () => {
  await using f = await mergeFixture();
  const { history, data } = await f.run();
  assertEquals(data.calls.filter((c: string[]) => c[0] === "PUT").length, 1);
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
Deno.test("pending checks refuse before contents", async () => {
  await using f = await mergeFixture();
  f.save("checks");
  const { data } = await f.run(false);
  assert(
    !data.calls.some((c: string[]) => c[1].includes("/contents/") || c[0] === "PUT"),
  );
});
Deno.test("already merged skips reads and put", async () => {
  await using f = await mergeFixture();
  f.save("merged");
  const { data } = await f.run();
  assertEquals(data.calls.map((c: string[]) => c[0]), [
    "GET",
    "DELETE",
    "DELETE",
  ]);
});
Deno.test("lost reply reconciles on fresh run", async () => {
  await using f = await mergeFixture();
  let data = f.read();
  data.lost_reply = true;
  Deno.writeTextFileSync(f.state, JSON.stringify(data));
  await f.run(false);
  data = f.read();
  data.calls = [];
  Deno.writeTextFileSync(f.state, JSON.stringify(data));
  const next = await f.run();
  assert(!next.data.calls.some((c: string[]) => c[0] === "PUT"));
});
