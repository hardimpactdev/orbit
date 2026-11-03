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
    failRevoke: false,
  };
  const server = Deno.serve({ hostname: "127.0.0.1", port: 0, onListen() {} }, async (request) => {
    if (request.headers.get("Authorization") !== "Bearer " + secret) {
      return Response.json({ private: secret }, { status: 401 });
    }
    const path = new URL(request.url).pathname;
    state.calls.push([request.method, path]);
    if (request.method === "GET") {
      state.listCount++;
      const rows = structuredClone(state.sessions);
      return Response.json(rows.map((row) => ({ ...row, private: privateResponse })));
    }
    const body = await request.json();
    if (path.endsWith("clients/revoke")) {
      if (state.failRevoke) return Response.json({ private: secret }, { status: 500 });
      state.revoked.push(body.sessionId);
      state.sessions = state.sessions.filter((row) => row.sessionId !== body.sessionId);
    } else {
      state.revokedLinks.push(body.id);
    }
    return Response.json({ revoked: true, private: privateResponse });
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
Deno.test("native revocation fans out distinct slots and preserves current and foreign sessions", async () => {
  await using f = await fixture();
  f.state.sessions.push({ sessionId: "owned-two", client: { label: "phone" } });
  f.inputs.intent.links = ["first-link", "second-link", "third-link"];
  await f.repo.cli(["workflow", "validate", "operation-conn-pairing-revoke"]);
  const { steps, result } = await f.run();
  assertEquals(result, { backend: "revoked", revoked_sessions: 2 });
  assertEquals(f.state.revoked.sort(), ["owned", "owned-two"]);
  assertEquals(f.state.revokedLinks.sort(), f.inputs.intent.links.toSorted());
  assertEquals(f.state.sessions.map((row) => row.sessionId), ["self", "foreign"]);
  assertEquals(f.state.listCount, 1);
  const links = Object.values(steps).filter((step) => step.name.startsWith("Revoke unused links-"));
  assertEquals(links.length, 3);
  const ids = links.map((step) =>
    step.dataArtifacts.filter((row: { tags?: { type?: string } }) => row.tags?.type === "resource")
      .map((row: { dataId: string }) => row.dataId)
  );
  assert(ids.every((row) => row.length));
  assertEquals(new Set(ids.flat()).size, ids.flat().length);
});
Deno.test("failed session revoke skips links and clears private replies", async () => {
  await using f = await fixture();
  f.state.failRevoke = true;
  const { steps } = await f.run(false);
  assertEquals(steps["Revoke the sessions-0"].status, "failed");
  assertEquals(steps["Revoke unused links-0"].status, "skipped");
  assertEquals(f.state.revokedLinks, []);
});
Deno.test("empty labels and links skip HTTP contact", async () => {
  await using f = await fixture();
  f.inputs.intent.labels = [];
  f.inputs.intent.links = [];
  const { steps } = await f.run();
  assertEquals(steps["Read session inventory"].status, "skipped");
  assertEquals(f.state.calls, []);
});
