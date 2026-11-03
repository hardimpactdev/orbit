import { assert, assertEquals, assertFalse, assertMatch, assertNotEquals } from "jsr:@std/assert@1.0.19";
import { nativeRepo, swampBinary } from "../../../../tests/_support/native_harness.ts";
import { localWorker } from "../../../../tests/_support/local_worker.ts";
import { exists, stepsOf } from "../../../../tests/_support/native_history.ts";
async function fixture(remote = false) {
  const repo = await nativeRepo();
  await repo.copyBehaviors("operations/conn-pairing");
  // Test helpers and test constants are not runtime behaviors.
  const admin = "native-admin-secret-" + crypto.randomUUID(),
    credential = "native-issued-secret-" + crypto.randomUUID();
  const secondCredential = "native-issued-secret-" + crypto.randomUUID();
  repo.secrets = [admin, credential, secondCredential];
  const state = { calls: 0, mode: "" };
  const server = Deno.serve({ hostname: "127.0.0.1", port: 0, onListen() {} }, async (request) => {
    state.calls++;
    const body = await request.json();
    if (
      new URL(request.url).pathname !== "/api/auth/pairing-token" ||
      request.headers.get("Authorization") !== "Bearer " + admin ||
      !["fixture phone", "second phone"].includes(body.label)
    ) return new Response(null, { status: 401 });
    const value = body.label === "second phone" ? secondCredential : credential;
    return Response.json({
      id: state.mode === "echo" ? value : "public-link",
      credential: value,
      expiresAt: "2026-11-01T02:00:00Z",
    }, { status: state.mode === "refusal" ? 503 : 200 });
  });
  await repo.cli(["vault", "create", "local_encryption", "orbit-control-inputs"]);
  const key = "conn-pairing-create-00000000-0000-4000-8000-000000000001";
  await repo.cli(["vault", "put", "orbit-control-inputs", key], { input: admin });
  await repo.workflow("operation-conn-pairing-create", (flow) => {
    if (remote) { for (const step of flow.jobs.flatMap((j) => j.steps)) step.target = "orbit-node-209"; }
  });
  const inputs = {
    operation_id: 7,
    node_id: 14,
    kind: "conn.pairing_create",
    intent: {
      url: `http://127.0.0.1:${server.addr.port}`,
      pairing_id: 17,
      label: "fixture phone",
      request_body: JSON.stringify({ label: "fixture phone", scopes: ["access:write"] }),
    },
    execution_secrets: { admin_session: { vault: "orbit-control-inputs", key } },
  };
  async function run(success = true, flags: string[] = []) {
    const started = JSON.parse(
      (await repo.cli(
        ["workflow", "run", "operation-conn-pairing-create", "--input", JSON.stringify(inputs), ...flags],
        { success },
      )).stdout,
    );
    const history = JSON.parse((await repo.cli(["workflow", "history", "get", started.id, ...flags])).stdout);
    const steps = stepsOf(history);
    assertEquals(steps["Remove private reply"].status, "succeeded");
    assertFalse(exists(steps["Create private reply"].outputs.stdout.trim()));
    return { history, steps, output: steps["Store the link in vault"].outputs ?? {} };
  }
  async function readCredential(key: string) {
    // Intentional private read stays in memory, outside public-output assertions.
    const env: Record<string, string> = { ...Deno.env.toObject(), SWAMP_NO_UPDATE_CHECK: "1", SWAMP_NO_TELEMETRY: "1" };
    for (
      const key of [
        "SWAMP_SERVER_URL",
        "SWAMP_SERVE_URL",
        "SWAMP_SERVER_TOKEN",
        "SWAMP_SERVER_TOKEN_FILE",
        "SWAMP_REPO_DIR",
        "SWAMP_CA_CERT",
        "SWAMP_EXTENSIONS_DIR",
      ]
    ) delete env[key];
    const result = await new Deno.Command(await swampBinary(), {
      args: ["vault", "read-secret", "orbit-control-inputs", key, "--json"],
      cwd: repo.dir,
      env,
      clearEnv: true,
      stdout: "piped",
      stderr: "piped",
      signal: AbortSignal.timeout(30_000),
    }).output();
    assert(result.success, "Private vault read failed");
    return JSON.parse(new TextDecoder().decode(result.stdout)).value;
  }
  return {
    repo,
    inputs,
    state,
    credential,
    secondCredential,
    run,
    readCredential,
    async [Symbol.asyncDispose]() {
      await server.shutdown();
      await repo[Symbol.asyncDispose]();
    },
  };
}
Deno.test("native sensitive output exposes only a reference with the correct private vault value", async () => {
  await using f = await fixture();
  await f.repo.cli(["workflow", "validate", "operation-conn-pairing-create"]);
  const { history, output } = await f.run();
  const result = JSON.parse(output.stdout);
  assertEquals(history.status, "succeeded");
  assertEquals(f.state.calls, 1);
  assertEquals([result.pairing_id, result.pairing_link_id], [17, "public-link"]);
  assertMatch(result.credential_ref.key, /^nckrtl-conn-pairing-[a-f0-9-]+-issue-issued-pairing-17-credential$/);
  assertEquals(output.credential, "${{ vault.get('orbit-control-inputs', '" + result.credential_ref.key + "') }}");
  assert(
    await f.readCredential(result.credential_ref.key) === f.credential,
    "Native sensitive vault value did not match",
  );
  await f.repo.assertPrivateAbsent(JSON.stringify(history));
});
Deno.test("later issue preserves unread credential", async () => {
  await using f = await fixture();
  const first = JSON.parse((await f.run()).output.stdout).credential_ref.key;
  Object.assign(f.inputs.intent, {
    pairing_id: 18,
    label: "second phone",
    request_body: JSON.stringify({ label: "second phone", scopes: ["access:write"] }),
  });
  const second = JSON.parse((await f.run()).output.stdout).credential_ref.key;
  assertNotEquals(first, second);
  assert(await f.readCredential(first) === f.credential, "First unread credential overwritten");
  assert(await f.readCredential(second) === f.secondCredential, "Second credential not isolated");
  assertEquals(f.state.calls, 2);
});
for (const mode of ["refusal", "echo"]) {
  Deno.test(`issue ${mode} refuses before public persistence and never retries`, async () => {
    await using f = await fixture();
    f.state.mode = mode;
    const { history, steps, output } = await f.run(false);
    assertEquals(history.status, "failed");
    assertEquals(f.state.calls, 1);
    if (mode === "refusal") assertFalse("stdout" in output);
    else {assertEquals(
        (steps["Store the link in vault"].dataArtifacts ?? []).filter((row: { tags?: { type?: string } }) =>
          row.tags?.type === "resource"
        ),
        [],
      );}
  });
}
Deno.test("remote worker protects generated sensitive output before public result", async () => {
  await using f = await fixture(true);
  await localWorker(f.repo, async (flags) => {
    const { history, output } = await f.run(true, flags);
    assertEquals(history.status, "succeeded");
    const result = JSON.parse(output.stdout);
    assertEquals(result.pairing_id, 17);
    assertEquals(result.pairing_link_id, "public-link");
    assert(result.credential_ref.key.startsWith("nckrtl-conn-pairing-"));
    assertEquals(output.credential, "${{ vault.get('orbit-control-inputs', '" + result.credential_ref.key + "') }}");
    assertEquals(f.state.calls, 1);
    assert(await f.readCredential(result.credential_ref.key) === f.credential, "Worker vault value did not match");
    await f.repo.assertPrivateAbsent(JSON.stringify(history));
  });
});
