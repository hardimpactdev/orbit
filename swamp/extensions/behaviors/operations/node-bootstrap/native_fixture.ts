const filename = "bootstrap-fixture.json";
async function read() {
  return JSON.parse(await Deno.readTextFile(filename));
}
async function write(state: Record<string, unknown>) {
  await Deno.writeTextFile(filename, JSON.stringify(state));
}
export async function fixtureHttp(method: string, path: string, raw?: unknown) {
  const state = await read();
  const body = raw as Record<string, unknown>;
  state.calls.push({ method, path, body });
  if (path.includes("private-material")) {
    await write(state);
    return {
      status: 200,
      body: {
        data: {
          bootstrap_identity: Deno.env.get("ORBIT_BOOTSTRAP_TEST_IDENTITY"),
        },
      },
    };
  }
  if (path.endsWith("/failure")) {
    if (
      !["succeeded", "failed", "cancelled"].includes(state.operation.status)
    ) {
      state.operation.status = "failed";
      state.operation.result = {};
      state.operation.error_code = "operation.native_failed";
    }
  } else if (method === "POST") {
    state.operation.status = body.status;
    state.operation.result = body.result ?? null;
    state.operation.error_code = body.error_code;
  }
  await write(state);
  if (state.loseProjection && body?.status === "succeeded") {
    state.loseProjection = false;
    await write(state);
    throw new Error("lost callback response");
  }
  return { status: 200, body: { data: state.operation } };
}
export async function engineRefused() {
  return (await read()).engineRefused === true;
}
export async function fixtureApply(
  kind: string,
  raw: unknown,
  identity: string,
) {
  if (identity !== Deno.env.get("ORBIT_BOOTSTRAP_TEST_IDENTITY")) {
    throw new Error("node.bootstrap_identity_mismatch");
  }
  const state = await read();
  state.actions++;
  await write(state);
  if (state.privateFailure) throw new Error(identity);
  const bootstrap = raw as {
    target: { fingerprint: string };
    platform: string;
  };
  return {
    [
      kind === "node.bootstrap_probe"
        ? "probed"
        : kind === "node.bootstrap_install"
        ? "installed"
        : kind === "node.bootstrap_host"
        ? "bootstrapped"
        : "bootstrap_verified"
    ]: true,
    architecture: bootstrap.platform === "macos"
      ? "arm64" as const
      : "x86_64" as const,
    host_type: "ssh-ed25519",
    public_key: "AAAA",
    host_key_fingerprint: bootstrap.target.fingerprint,
  };
}

export async function fixturePackages() {
  const state = await read();
  state.actions++;
  await write(state);
  return { outcome: "current" as const, stale_masters: [], deferred: [] };
}
