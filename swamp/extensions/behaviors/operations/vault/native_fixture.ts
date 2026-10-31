/** Synthetic service state contains no private bytes; credentials exist only in the test environment. */
const sentinel = () => Deno.env.get("ORBIT_PRIVATE_TEST_SENTINEL")!;
export async function fixtureHttp(
  method: string,
  path: string,
  body?: Record<string, unknown>,
) {
  const state = JSON.parse(await Deno.readTextFile("native-vault.json"));
  const id = /direct-operations\/(\d+)/.exec(path)![1];
  const operation = state.operations[id];
  state.calls.push(path);
  let data: unknown = operation;
  if (path.includes("/private-material")) {
    data = {
      configuration: {
        master_password: sentinel(),
        client_secret: sentinel(),
        wanted: [{ cipher: { fields: [{ hidden: true, value: sentinel() }] } }],
      },
    };
  } else if (method === "POST") {
    if (body?.status === "succeeded" && state.loseProjection && id === "17") {
      state.loseProjection = false;
      await Deno.writeTextFile("native-vault.json", JSON.stringify(state));
      throw new Error("Projection unavailable");
    }
    operation.status = body?.status;
    operation.result = body?.result ?? null;
  }
  await Deno.writeTextFile("native-vault.json", JSON.stringify(state));
  return { status: 200, body: { data } };
}
export async function fixtureVault(action: string) {
  const state = JSON.parse(await Deno.readTextFile("native-vault.json"));
  state.effects++;
  await Deno.writeTextFile("native-vault.json", JSON.stringify(state));
  return {
    outcome: "succeeded",
    data: {
      backend: action === "sync"
        ? "synced"
        : action === "setup"
        ? "configured"
        : "absent",
      ...(action === "sync"
        ? { sources: [], cipher_ids: [], revisions: [], fingerprints: [] }
        : action === "setup"
        ? {
          email: "fixture@example.test",
          organization_id: "org",
          organization_name: "Orbit",
          collection_id: "collection",
          collection_name: "Test",
        }
        : {}),
      ...(state.echoPrivate ? { email: sentinel() } : {}),
    },
  };
}
