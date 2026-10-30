/** Private fixture state lives outside native Swamp data; never used in production. */
function privateSentinel() {
  const value = Deno.env.get("ORBIT_PRIVATE_TEST_SENTINEL");
  if (!value) throw new Error("A test sentinel is required");
  return value;
}
export async function fixtureHttp(method: string, path: string, raw?: unknown) {
  const state = JSON.parse(await Deno.readTextFile("native-direct.json"));
  state.calls.push(path);
  const body = raw as Record<string, unknown>;
  if (state.unbound) return { status: 409, body: {} };
  const { environment, ...safe } = state.private;
  state.operation.input = { ...safe, action: "pairing_create" };
  let data: unknown = state.operation;
  if (path.includes("/private-material")) {
    data = {
      environment: { ...environment, admin_session: privateSentinel() },
    };
  } else if (path.endsWith("/secret-output")) {
    if (state.loseStore) {
      state.loseStore = false;
      await Deno.writeTextFile("native-direct.json", JSON.stringify(state));
      return { status: 500, body: {} };
    }
    state.private.issued = true;
    data = {
      stored: {
        credential: {
          $secret: "conn.pairing.credential",
          id: state.private.pairing_id,
        },
      },
    };
  } else if (method === "POST") {
    state.operation.status = body.status;
    state.operation.result = body.result ?? null;
    if ((body.result as { backend?: string })?.backend === "issuing") {
      state.private.issue_started = true;
    }
    if ((body.result as { backend?: string })?.backend === "issued") {
      state.private.pairing_link_id =
        (body.result as { pairing_link_id: string }).pairing_link_id;
      state.private.expires_at =
        (body.result as { expires_at: string }).expires_at;
    }
  }
  await Deno.writeTextFile("native-direct.json", JSON.stringify(state));
  if (state.loseProjection && body?.status === "succeeded") {
    state.loseProjection = false;
    await Deno.writeTextFile("native-direct.json", JSON.stringify(state));
    throw new Error("projection lost");
  }
  return { status: 200, body: { data } };
}
export async function fixtureT3(_method: string, path: string) {
  const state = JSON.parse(await Deno.readTextFile("native-direct.json"));
  if (path === "/api/auth/clients") {
    return [{ sessionId: "owned", client: { label: "phone" } }, {
      sessionId: "self",
      current: true,
      client: { label: "phone" },
    }];
  }
  state.effects++;
  await Deno.writeTextFile("native-direct.json", JSON.stringify(state));
  if (path === "/api/auth/pairing-token") {
    return {
      id: "link-1",
      credential: privateSentinel(),
      expiresAt: "2026-11-01T12:00:00.000Z",
    };
  }
  return { revoked: true };
}
