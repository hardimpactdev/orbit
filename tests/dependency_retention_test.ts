import { assertEquals } from "jsr:@std/assert@1.0.19";
import { pruneDependencyResults } from "../extensions/behaviors/_lib/dependency_retention.ts";
import { IndexSchema } from "../extensions/behaviors/_lib/dependency_results_schema.ts";
Deno.test("retention keeps latest index and exact reports of all retained versions, removes seven-day orphans", async () => {
  const fixture = JSON.parse(
    await Deno.readTextFile(
      new URL("./fixtures/dependency-results-v1/success.json", import.meta.url),
    ),
  );
  const index = IndexSchema.parse(
    JSON.parse(fixture.snapshots[0].index.response.payload.data.content),
  );
  const now = Date.parse("2026-11-20T00:00:00Z"),
    old = new Date(now - 8 * 86400000),
    recent = new Date(now - 86400000);
  const pointer = index.sources.find((s) => s.report)!.report!;
  const names = new Map<
    string,
    Map<number, { createdAt: Date; value: unknown }>
  >([
    [
      "dependencies-v1-project-20-fixture",
      new Map([[1, { createdAt: old, value: index }], [2, {
        createdAt: recent,
        value: index,
      }], [3, { createdAt: old, value: index }]]),
    ],
    [
      pointer.dataName,
      new Map([[pointer.version, { createdAt: old, value: {} }]]),
    ],
    [
      "dependencies-v1-report-orphan",
      new Map([[1, { createdAt: old, value: {} }]]),
    ],
    [
      "dependencies-v1-report-recent",
      new Map([[1, { createdAt: recent, value: {} }]]),
    ],
  ]);
  const deleted: string[] = [];
  await pruneDependencyResults({
    modelType: "test",
    modelId: "id",
    dataRepository: {
      findAllForModel: () =>
        Promise.resolve(
          [...names].map(([name, versions]) => ({
            name,
            version: Math.max(...versions.keys()),
            createdAt: [...versions.values()].at(-1)!.createdAt,
          })),
        ),
      listVersions: (_t, _i, n) => Promise.resolve([...names.get(n)!.keys()]),
      findByName: (_t, _i, n, v) =>
        Promise.resolve(names.get(n)?.get(v) ?? null),
      getContent: (_t, _i, n, v) =>
        Promise.resolve(
          new TextEncoder().encode(JSON.stringify(names.get(n)?.get(v)?.value)),
        ),
      delete: (_t, _i, n, v) => {
        deleted.push(`${n}:${v}`);
        names.get(n)!.delete(v!);
        return Promise.resolve();
      },
    },
  }, now);
  assertEquals(deleted, [
    "dependencies-v1-project-20-fixture:1",
    "dependencies-v1-report-orphan:1",
  ]);
});
