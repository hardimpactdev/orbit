import { assertEquals, assertMatch, assertRejects } from "jsr:@std/assert@1";
import type { DestroyReadCall } from "./destroy_system.ts";
import { inspectDestroySources } from "./destroy_inspection.ts";
import { DestroyRefusal } from "./destroy_local.ts";

const checkout = "/srv/apps/proof/main";
const input = () => ({
  instance_id: 17,
  source_pending: true,
  force: false,
  repository: "https://github.com/example/proof.git",
  protected_source_roots: ["/etc", "/usr", "/opt/orbit"],
  direct_destroy: { node_user: "orbit" },
  members: [{
    instance_id: 17,
    checkout_path: checkout,
    source_root: "/srv/apps",
    source_layout: "checkout",
    repository_identity: "github.com/example/proof",
    branch: "main",
    starting_commit: "a".repeat(40),
    user: "orbit",
    source_prepare_id: null,
    registration_request_id: null,
    stored_status: "active",
    failed_creation: false,
  }],
});
function fixture(change: Record<string, string> = {}) {
  const values = {
    top: checkout,
    common: checkout + "/.git",
    origin: "git@github.com:example/proof.git",
    branch: "main",
    commit: "b".repeat(40),
    dirty: "0",
    source_identity: "42:73",
    worktrees: `worktree ${checkout}\0HEAD ${
      "b".repeat(40)
    }\0branch refs/heads/main\0\0`,
    ...change,
  };
  const calls: DestroyReadCall[] = [];
  const invoke = (call: DestroyReadCall) => {
    calls.push(call);
    return Promise.resolve(
      call.name === "account.lookup"
        ? { user: "orbit", group: "apps", home: "/home/orbit" }
        : Object.fromEntries(
          Object.entries(values).map(([key, value]) => [key, btoa(value)]),
        ),
    );
  };
  return { calls, invoke };
}
Deno.test("destroy captures local account and exact source identity before any effect", async () => {
  const f = fixture();
  const result = await inspectDestroySources(input(), f.invoke);
  assertEquals(
    f.calls.map((call) => [call.name, call.sudo, call.mutates]),
    [
      ["account.lookup", false, false],
      ["instance.inspect-source", false, false],
    ],
  );
  assertEquals(f.calls[1].input, {
    checkout,
    root: "/srv/apps",
    user: "orbit",
    group: "apps",
    layout: "checkout",
    branch: "main",
    inspect_content: true,
    unresolved: false,
    allow_absent: false,
    origin: "https://github.com/example/proof.git",
    prepare_id: "",
  });
  assertEquals(result.members[0].source_commit, "b".repeat(40));
  assertEquals(result.members[0].linked_worktree_paths, [checkout]);
  assertEquals(result.members[0].common_repository_path, checkout);
  assertMatch(result.members[0].source_digest, /^[a-f0-9]{64}$/);
  assertMatch(result.inventory_digest, /^[a-f0-9]{64}$/);
});
for (
  const [description, change, code] of [
    ["dirty content", { dirty: "1" }, "instance.unsafe_content"],
    [
      "another inode contract",
      { source_identity: "unknown" },
      "instance.source_identity_invalid",
    ],
    [
      "foreign repository",
      { origin: "https://github.com/foreign/proof.git" },
      "instance.source_identity_invalid",
    ],
    [
      "different checkout",
      { top: "/srv/apps/proof/other" },
      "instance.source_identity_invalid",
    ],
    ["moved branch", { branch: "other" }, "instance.source_branch_mismatch"],
  ] as const
) {
  Deno.test(`destroy refuses ${description} during native inspection`, async () => {
    const f = fixture(change);
    await assertRejects(
      () => inspectDestroySources(input(), f.invoke),
      DestroyRefusal,
      code,
    );
    assertEquals(f.calls.length, 2);
  });
}
Deno.test("force never deletes an unadmitted linked worktree", async () => {
  const f = fixture({
    worktrees: `worktree ${checkout}\0\0worktree /srv/apps/proof/feature\0\0`,
  });
  await assertRejects(
    () => inspectDestroySources({ ...input(), force: true }, f.invoke),
    DestroyRefusal,
    "instance.remove_refused",
  );
});
Deno.test("independent worktree capture preserves sibling inventory without admitting sibling deletion", async () => {
  const raw = input();
  raw.members[0].source_layout = "worktree";
  const f = fixture({
    common: "/srv/apps/proof/master/.git",
    worktrees: `worktree /srv/apps/proof/master\0\0worktree ${checkout}\0\0`,
  });
  const result = await inspectDestroySources(raw, f.invoke);
  assertEquals(result.members.length, 1);
  assertEquals(result.members[0].linked_worktree_paths, [
    checkout,
    "/srv/apps/proof/master",
  ]);
  assertEquals(
    result.members[0].common_repository_path,
    "/srv/apps/proof/master",
  );
});
Deno.test("forced missing checkout is captured as absent without inventing a fresh inode", async () => {
  const f = fixture({
    source_identity: "absent",
    dirty: "",
    commit: "0".repeat(40),
  });
  const result = await inspectDestroySources(
    { ...input(), force: true },
    f.invoke,
  );
  assertEquals(result.members[0].source_identity, "absent");
  assertEquals(f.calls[1].input.allow_absent, true);
});
Deno.test("reserved failed create cannot adopt and remove unrelated present source", async () => {
  const raw = input();
  raw.members[0].failed_creation = true;
  raw.members[0].stored_status = "reserved";
  await assertRejects(
    () => inspectDestroySources(raw, fixture().invoke),
    DestroyRefusal,
    "instance.remove_refused",
  );
});
Deno.test("protected source root refuses before source inspection", async () => {
  const raw = input();
  raw.protected_source_roots.push("/srv/apps/proof/control");
  const f = fixture();
  await assertRejects(
    () => inspectDestroySources(raw, f.invoke),
    DestroyRefusal,
    "instance.checkout_path_unsafe",
  );
  assertEquals(f.calls.map((call) => call.name), ["account.lookup"]);
});
Deno.test("already captured retry is never re-inspected at the moved checkout path", async () => {
  const f = fixture();
  await assertRejects(() =>
    inspectDestroySources({ ...input(), source_pending: false }, f.invoke)
  );
  assertEquals(f.calls, []);
});
Deno.test("source observation refusal remains bounded and prevents subsequent source calls", async () => {
  const calls: string[] = [];
  await assertRejects(
    () =>
      inspectDestroySources(input(), (call) => {
        calls.push(call.name);
        return Promise.resolve({ error_code: "account.not_found" });
      }),
    DestroyRefusal,
    "account.not_found",
  );
  assertEquals(calls, ["account.lookup"]);
});

Deno.test("source capture admits a checkout and linked worktree only as one complete removal set", async () => {
  const raw = input();
  raw.members.push({
    ...raw.members[0],
    instance_id: 18,
    checkout_path: "/srv/apps/proof/work",
    source_layout: "worktree",
    branch: "feature",
  });
  const worktrees = `worktree ${checkout}\0HEAD ${
    "b".repeat(40)
  }\0branch refs/heads/main\0\0worktree /srv/apps/proof/work\0HEAD ${
    "b".repeat(40)
  }\0branch refs/heads/feature\0\0`;
  const captured = await inspectDestroySources(raw, (call) => {
    if (call.name === "account.lookup") return fixture().invoke(call);
    return fixture({
      top: String(call.input.checkout),
      branch: String(call.input.branch),
      worktrees,
    }).invoke(call);
  });
  assertEquals(captured.members.map((m) => m.instance_id), [17, 18]);
  assertEquals(captured.members[0].linked_worktree_paths, [
    "/srv/apps/proof/main",
    "/srv/apps/proof/work",
  ]);
});
