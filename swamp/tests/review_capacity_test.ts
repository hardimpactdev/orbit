import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  parkForReview,
  reviewReleased,
} from "../extensions/behaviors/_lib/review_capacity.ts";
import {
  reconcileAdmissions,
  reserveMaintenance,
} from "../extensions/behaviors/_lib/project_admission.ts";
import type { Http } from "../extensions/behaviors/_lib/gateway_stage.ts";

import {
  admission,
  capacityFixture,
  evidenceHttp,
  fixture,
  group,
  reconciliationFixture,
  refusal,
} from "./_support/review_parking_fixture.ts";

Deno.test("only confirmed scoped parking releases a slot; replay preserves project ownership and sends no extra stop", async () => {
  const f = fixture();
  assertEquals(
    (await parkForReview("tg-1.publish.c1", f.read(), f.deps)).ready,
    false,
  );
  assertEquals(reviewReleased(f.read(), f.g), false);
  f.tick();
  assertEquals(
    (await parkForReview("tg-1.publish.c1", f.read(), f.deps)).ready,
    true,
  );
  assertEquals(reviewReleased(f.read(), f.g), true);
  assertEquals(f.read().released, false);
  assertEquals(
    (await parkForReview("tg-1.merged.c1", f.read(), f.deps)).ready,
    true,
  );
  assertEquals(f.calls.filter((c) => c.includes("/park ")).length, 1);
});
Deno.test("an ambiguous park start retries the same saved key after restart without freeing capacity", async () => {
  const f = fixture();
  let lost = true;
  const http: Http = async (...args) => {
    const r = await f.deps.http(...args);
    if (args[0] === "POST" && lost) {
      lost = false;
      return { status: 0, body: null };
    }
    return r;
  };
  await parkForReview("tg-1.publish.c1", f.read(), { ...f.deps, http });
  assertEquals(f.read().parking!.operationId, null);
  f.tick();
  await parkForReview("tg-1.publish.c1", structuredClone(f.read()), {
    ...f.deps,
    http,
  });
  assertEquals(f.calls.filter((c) => c.includes("/park ")), [
    "POST /api/v1/task-groups/1/park tg-1.publish.c1.park.0",
    "POST /api/v1/task-groups/1/park tg-1.publish.c1.park.0",
  ]);
  assertEquals(reviewReleased(f.read(), f.g), false);
});
for (
  const invalid of [
    "queued",
    "failed",
    "foreign",
    "running guest",
    "child",
    "assistance",
  ]
) {
  Deno.test(`parking ${invalid} evidence retains the slot`, async () => {
    const f = fixture();
    await parkForReview("tg-1.publish.c1", f.read(), f.deps);
    f.tick();
    const http = evidenceHttp(f, invalid);
    if (["queued", "failed"].includes(invalid)) {
      assertEquals(
        (await parkForReview("tg-1.publish.c1", f.read(), { ...f.deps, http }))
          .ready,
        false,
      );
    } else {await assertRejects(() =>
        parkForReview("tg-1.publish.c1", f.read(), { ...f.deps, http })
      );}
    assertEquals(reviewReleased(f.read(), f.g), false);
  });
}
Deno.test("the frozen Gateway refusal keeps the existing review gate and its slot", async () => {
  const f = fixture();
  const http: Http = (...args) =>
    args[0] === "POST"
      ? Promise.resolve({ status: refusal.status, body: refusal.body })
      : f.deps.http(...args);
  assertEquals(
    (await parkForReview("tg-1.publish.c1", f.read(), { ...f.deps, http }))
      .ready,
    false,
  );
  assertEquals(reviewReleased(f.read(), f.g), false);
});
Deno.test("a parked review frees capacity for another project, blocks all same-project modes and reacquires before wake", async () => {
  const f = fixture();
  await parkForReview("tg-1.publish.c1", f.read(), f.deps);
  f.tick();
  await parkForReview("tg-1.publish.c1", f.read(), f.deps);
  const { admissions, deps } = capacityFixture(
    [
      f.read(),
      ...[2, 3, 4].map((id) => ({
        ...admission(),
        key: `batch-${id}`,
        projectId: id,
        workItem: `tg-${id}`,
      })),
    ],
    [
      f.g,
      ...[2, 3, 4].map((id) => ({
        ...group(),
        id,
        project_id: id,
        taskable_id: id,
      })),
    ],
  );
  for (const mode of ["routine", "detect", "package"] as const) {
    assertEquals(
      (await reserveMaintenance({
        key: `new-${mode}`,
        projectId: 20,
        mode,
        workItem: null,
      }, deps)).admitted,
      false,
    );
  }
  assertEquals(
    (await reserveMaintenance({
      key: "fifth",
      projectId: 5,
      mode: "detect",
      workItem: null,
    }, deps)).admitted,
    true,
  );
  f.g.watched_pr_state = "merged";
  assertEquals((await reserveMaintenance(admission(), deps)).admitted, false);
  admissions.pop();
  assertEquals((await reserveMaintenance(admission(), deps)).admitted, true);
  assertEquals(admissions[0].parking, undefined);
  assertEquals(admissions[0].parkingEpoch, 1);
  assertEquals(
    (await reserveMaintenance({
      key: "other",
      projectId: 6,
      mode: "package",
      workItem: null,
    }, deps)).admitted,
    false,
  );
});

Deno.test("unreadable review evidence raises assistance, while observed divergence voids the proof", async () => {
  for (const unavailable of [false, true]) {
    const f = fixture();
    await parkForReview("tg-1.publish.c1", f.read(), f.deps);
    f.tick();
    await parkForReview("tg-1.publish.c1", f.read(), f.deps);
    f.g.vm_power = "running";
    const { issues, deps } = reconciliationFixture(f, unavailable);
    await reconcileAdmissions(deps);
    assertEquals(issues.length, 1);
    assertEquals(
      unavailable ? issues[0]?.includes("slot retained") : issues[0],
      unavailable ? true : null,
    );
    assertEquals(f.read().released, false);
    assertEquals(reviewReleased(f.read(), f.g), false);
    const reservation = await reserveMaintenance(f.read(), {
      admissions: [f.read()],
      existing: [],
      save: f.deps.save,
      http: () => Promise.resolve({ status: 200, body: { data: [f.g] } }),
    });
    assertEquals(reservation.admitted, true);
    assertEquals(f.read().parking, undefined);
  }
});

for (const status of ["failed", "cancelled"]) {
  Deno.test(`terminal park ${status} retries the same stage with a fresh epoch`, async () => {
    const f = fixture();
    await parkForReview("tg-1.publish.c1", f.read(), f.deps);
    f.tick();
    const http = evidenceHttp(f, status);
    const pending = await parkForReview("tg-1.publish.c1", f.read(), {
      ...f.deps,
      http,
    });
    assertEquals(pending.ready, false);
    assertEquals(f.read().parking!.operationId, null);
    assertEquals(f.read().parking!.failures, 1);
    const calls = f.calls.length;
    await parkForReview("tg-1.publish.c1", f.read(), f.deps);
    assertEquals(f.calls.length, calls + 1);
    assertEquals(f.read().parkingEpoch, 1);
    f.tick();
    await parkForReview("tg-1.publish.c1", f.read(), f.deps);
    assertEquals(f.calls.filter((c) => c.includes("/park ")), [
      "POST /api/v1/task-groups/1/park tg-1.publish.c1.park.0",
      "POST /api/v1/task-groups/1/park tg-1.publish.c1.park.1",
    ]);
  });
}
for (const power of ["running", null]) {
  Deno.test(`a merged group's diverged ${power} proof cannot deadlock cleanup`, async () => {
    const f = fixture();
    await parkForReview("tg-1.publish.c1", f.read(), f.deps);
    f.tick();
    await parkForReview("tg-1.publish.c1", f.read(), f.deps);
    Object.assign(f.g, { watched_pr_state: "merged", vm_power: power });
    const before = Object.freeze(structuredClone(f.read()));
    const result = await reserveMaintenance(before, {
      admissions: [before],
      existing: [],
      save: f.deps.save,
      http: () => Promise.resolve({ status: 200, body: { data: [f.g] } }),
    });
    assertEquals(result.admitted, true);
    assertEquals(result.admission!.parking, undefined);
    assertEquals(before.parking?.confirmed?.power, "stopped");
  });
}
Deno.test("completed cleanup reservation retires an immutable prior receipt without changing its caller", async () => {
  const a = Object.freeze(admission());
  const f = capacityFixture([a], [{
    ...group(),
    status: "completed",
    taskable_id: null,
    vm_power: null,
    watched_pr_state: "merged",
  }]);
  const result = await reserveMaintenance(a, f.deps);
  assertEquals(result.admitted, false);
  assertEquals(a.released, false);
  assertEquals(f.admissions[0].released, true);
});

Deno.test("three failed parks retain backoff and ask once, and an operator resolution permits retry", async () => {
  const f = fixture();
  const comments: Record<string, unknown>[] = [];
  let posts = 0;
  const http: Http = (method, path, body, key) => {
    if (path.endsWith("/comments")) {
      if (method === "POST") {
        const comment: Record<string, unknown> = {
          ...(body as Record<string, unknown>),
          id: comments.length + 1,
          task_group_id: 1,
          task_id: 1,
        };
        comments.push(comment);
        posts++;
        Object.assign(f.g, {
          assistance_requested: true,
          assistance_kind: "direction",
          assistance_question: comment.body,
          assistance_reason: comment.body,
        });
        return Promise.resolve({ status: 201, body: { data: comment } });
      }
      return Promise.resolve({ status: 200, body: { data: comments } });
    }
    if (path.startsWith("/api/v1/operations/")) {
      return evidenceHttp(f, "failed")(method, path, body, key);
    }
    return f.deps.http(method, path, body, key);
  };
  const deps = { ...f.deps, http };
  for (let failure = 1; failure <= 3; failure++) {
    await parkForReview("tg-1.publish.c1", f.read(), deps);
    f.tick();
    await parkForReview("tg-1.publish.c1", f.read(), deps);
    assertEquals(f.read().parking!.failures, failure);
    const due = f.read().parking!.nextReadAt!;
    assertEquals(due - deps.now(), [30_000, 60_000, 120_000][failure - 1]);
    if (failure < 3) f.tick();
  }
  await parkForReview("tg-1.publish.c1", f.read(), deps);
  await parkForReview("tg-1.publish.c1", structuredClone(f.read()), deps);
  assertEquals(posts, 1);
  assertEquals(reviewReleased(f.read(), f.g), false);
  comments.push({
    id: comments.length + 1,
    task_group_id: 1,
    task_id: 1,
    type: "resolution",
    body: "Provider recovered.",
    author: "operator",
  });
  Object.assign(f.g, {
    assistance_requested: false,
    assistance_kind: null,
    assistance_question: null,
    assistance_reason: null,
  });
  await parkForReview("tg-1.publish.c1", f.read(), deps);
  assertEquals(f.read().parking!.failures, 0);
  assertEquals(f.read().parking!.assistance, undefined);
  assertEquals(f.read().parking!.operationId !== null, true);
});
