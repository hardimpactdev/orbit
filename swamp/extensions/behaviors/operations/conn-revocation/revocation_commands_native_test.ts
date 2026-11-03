import { assert, assertEquals, assertFalse } from "jsr:@std/assert@1.0.19";
import { nativeRepo } from "../../../../tests/_support/native_harness.ts";
import { exists, stepsOf } from "../../../../tests/_support/native_history.ts";
async function fixture() {
  const repo = await nativeRepo();
  repo.timeoutMs = 90_000;
  await repo.copyBehaviors("operations/conn-revocation");
  for (const name of ["operation-conn-pairing-revoke", "operation-conn-direct"]) await repo.workflow(name);
  const secret = "private-admin-" + crypto.randomUUID(),
    privateResponse = "unrelated-private-response-" + crypto.randomUUID();
  repo.secrets = [secret, privateResponse];
  const state = {
    sessions: [{ sessionId: "owned", client: { label: "phone" } }, {
      sessionId: "self",
      current: true,
      client: { label: "phone" },
    }, { sessionId: "foreign", client: { label: "other" } }],
    calls: [] as string[][],
    revoked: [] as string[],
    revokedLinks: [] as string[],
    listCount: 0,
    failList: false,
    failRevoke: false,
    failLink: false,
    failLinkId: "",
    invalidRevoke: false,
    alreadyAbsent: false,
    changeOwner: false,
  };
  const server = Deno.serve({ hostname: "127.0.0.1", port: 0, onListen() {} }, async (request) => {
    if (request.headers.get("Authorization") !== "Bearer " + secret) {
      return Response.json({ private: secret }, { status: 401 });
    }
    const path = new URL(request.url).pathname;
    state.calls.push([request.method, path]);
    if (request.method === "GET") {
      state.listCount++;
      if (state.failList) return new Response("invalid-json");
      const rows = structuredClone(state.sessions);
      if (state.listCount > 1 && state.changeOwner) rows[0].client.label = "other";
      return Response.json(rows.map((row) => ({ ...row, private: privateResponse })));
    }
    const body = await request.json();
    if (path.endsWith("clients/revoke")) {
      if (state.failRevoke) return Response.json({ private: secret }, { status: 500 });
      state.revoked.push(body.sessionId);
      state.sessions = state.sessions.filter((row) => row.sessionId !== body.sessionId);
    } else {
      if (state.failLink || body.id === state.failLinkId) return Response.json({ private: secret }, { status: 500 });
      state.revokedLinks.push(body.id);
    }
    return state.invalidRevoke
      ? new Response("invalid-json")
      : Response.json({ revoked: !state.alreadyAbsent, private: privateResponse });
  });
  await repo.cli(["vault", "create", "local_encryption", "orbit-control-inputs"]);
  const key = "conn-pairing-revoke-11111111-1111-4111-8111-111111111111";
  await repo.cli(["vault", "put", "orbit-control-inputs", key], { input: secret });
  const inputs = {
    operation_id: 7,
    node_id: 14,
    kind: "conn.pairing_revoke",
    intent: {
      url: `http://127.0.0.1:${server.addr.port}`,
      labels: ["phone"],
      links: ["owned-link"],
    },
    execution_secrets: { admin_session: { vault: "orbit-control-inputs", key } },
  };
  async function run(success = true, name = "operation-conn-pairing-revoke") {
    const result = await repo.runWorkflow(name, inputs, { success });
    assertEquals(result.run.status, success ? "succeeded" : "failed");
    const steps = stepsOf(result.history);
    if (name.endsWith("pairing-revoke")) {
      assertEquals(steps["Remove private replies"].status, "succeeded");
      assertFalse(exists(steps["Create private replies"].outputs.stdout.trim()));
    }
    const receipt = JSON.parse(steps["Read session revocations"]?.outputs?.stdout ?? "{}");
    return {
      ...result,
      steps,
      result: success
        ? { backend: "revoked", revoked_sessions: receipt.revoked ?? 0 }
        : { error_code: "conn.revocation_failed" },
    };
  }
  return {
    repo,
    state,
    inputs,
    run,
    async [Symbol.asyncDispose]() {
      await server.shutdown();
      await repo[Symbol.asyncDispose]();
    },
  };
}
Deno.test("Gateway cleanup uses child receipt and preserves current admin", async () => {
  await using f = await fixture();
  f.inputs.kind = "conn.gateway_sessions_revoke";
  Object.assign(f.inputs.intent, { revision: 1, labels: ["Orbit Gateway"], links: [] });
  f.state.sessions[0].client.label = f.state.sessions[1].client.label = "Orbit Gateway";
  await f.repo.cli(["workflow", "validate", "operation-conn-direct"]);
  const { history } = await f.run(true, "operation-conn-direct");
  const step = Object.values(stepsOf(history))[0];
  assertEquals(step.status, "succeeded");
  const receipt = JSON.parse(step.outputs["Read session revocations"].stdout);
  assertEquals(receipt.revoked, 1);
  assert(receipt.valid);
  assertEquals([f.state.revoked, f.state.revokedLinks, f.state.listCount], [["owned"], [], 1]);
  assertEquals(f.state.sessions.map((row) => row.sessionId), ["self", "foreign"]);
});
for (
  const mode of [
    "ordinary",
    "multiple-sessions",
    "multiple-links",
    "bad-list",
    "owner",
    "absent",
    "invalid",
    "partial-link",
    "refusal",
    "empty",
    "rerun",
  ]
) {
  Deno.test(`revocation native effects and cleanup: ${mode}`, async () => {
    await using f = await fixture();
    const s = f.state, intent = f.inputs.intent;
    if (mode === "ordinary") await f.repo.cli(["workflow", "validate", "operation-conn-pairing-revoke"]);
    if (mode === "multiple-sessions") s.sessions.push({ sessionId: "owned-two", client: { label: "phone" } });
    if (mode === "multiple-links") intent.links = ["first-link", "second-link", "third-link"];
    if (mode === "bad-list") s.failList = true;
    if (mode === "owner") s.changeOwner = true;
    if (mode === "absent") s.alreadyAbsent = true;
    if (mode === "invalid") s.invalidRevoke = true;
    if (mode === "partial-link") {
      intent.links = ["first", "second"];
      s.failLinkId = "second";
    }
    if (mode === "refusal") s.failRevoke = true;
    if (mode === "empty") {
      intent.labels = [];
      intent.links = [];
    }
    if (mode === "rerun") {
      s.failLink = true;
      await f.run(false);
      assertEquals(s.revoked, ["owned"]);
      s.failLink = false;
    }
    const failed = ["bad-list", "invalid", "partial-link", "refusal"].includes(mode);
    const { steps, result } = await f.run(!failed);
    assertEquals(
      result,
      failed ? { error_code: "conn.revocation_failed" } : {
        backend: "revoked",
        revoked_sessions: mode === "multiple-sessions" ? 2 : ["absent", "empty", "rerun"].includes(mode) ? 0 : 1,
      },
    );
    if (mode === "bad-list") assertEquals(s.revoked, []);
    if (["bad-list", "invalid", "refusal", "empty"].includes(mode)) assertEquals(s.revokedLinks, []);
    if (["ordinary", "owner", "rerun"].includes(mode)) assertEquals(s.revoked, ["owned"]);
    if (["ordinary", "rerun", "multiple-sessions"].includes(mode)) assertEquals(s.revokedLinks, ["owned-link"]);
    if (["ordinary", "owner"].includes(mode)) assertEquals(s.listCount, 1);
    if (mode === "ordinary") assertEquals(s.sessions.map((row) => row.sessionId), ["self", "foreign"]);
    if (mode === "multiple-sessions") assertEquals(s.revoked.sort(), ["owned", "owned-two"]);
    if (mode === "empty") {
      assertEquals(s.revoked, []);
      assertEquals(s.calls, []);
    }
    if (mode === "partial-link") {
      assertEquals(JSON.parse(steps["Read link revocations"].outputs.stdout), {
        valid: false,
        completed: [0],
        revoked: 1,
      });
    }
    if (mode === "multiple-links") {
      assertEquals(s.revokedLinks.sort(), intent.links.toSorted());
      const links = Object.values(steps).filter((step) => step.name.startsWith("Revoke unused links-"));
      assertEquals(links.length, 3);
      const ids = links.map((step) =>
        step.dataArtifacts.filter((row: { tags?: { type?: string } }) => row.tags?.type === "resource")
          .map((row: { dataId: string }) => row.dataId)
      );
      assert(ids.every((row) => row.length));
      assertEquals(new Set(ids.flat()).size, ids.flat().length);
    }
  });
}
