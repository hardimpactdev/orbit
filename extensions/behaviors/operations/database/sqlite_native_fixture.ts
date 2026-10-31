import { createHash } from "node:crypto";
import { type Http } from "../../automatic-deploy/gateway.ts";
import {
  cleanupSnapshot,
  exportSnapshot,
  type Invoke,
  receiveSnapshot,
  seedTarget,
} from "./sqlite_actions.ts";
const statePath = "sqlite-native-state.json";
const read = async () => JSON.parse(await Deno.readTextFile(statePath));
const save = async (state: Record<string, unknown>) =>
  await Deno.writeTextFile(statePath, JSON.stringify(state));
const payload = new TextEncoder().encode(
  "SQLite format 3\0" +
    [
      112,
      114,
      105,
      118,
      97,
      116,
      101,
      45,
      100,
      98,
      45,
      115,
      101,
      110,
      116,
      105,
      110,
      101,
      108,
    ].map((v) => String.fromCharCode(v)).join(""),
);
const sha256 = createHash("sha256").update(payload).digest("hex");
export const fixtureHttp: Http = async (method, path, body) => {
  const state = await read();
  state.calls.push(path);
  if (path.endsWith("/failure")) {
    state.operation.status = "failed";
    state.operation.error_code = "operation.native_failed";
    state.operation.result = null;
    await save(state);
    return { status: 200, body: { data: state.operation } };
  }
  if (method === "GET") {
    await save(state);
    return { status: 200, body: { data: state.operation } };
  }
  const projection = body as Record<string, unknown>;
  if (
    typeof projection.started_at !== "string" ||
    !Number.isFinite(Date.parse(projection.started_at))
  ) {
    return { status: 422, body: { error: { code: "validation.failed" } } };
  }
  if (projection.status !== "running" && state.loseProjection) {
    state.loseProjection = false;
    await save(state);
    throw new Error("lost projection");
  }
  Object.assign(state.operation, projection);
  await save(state);
  return { status: 200, body: { data: state.operation } };
};
export const fixtureInvoke: Invoke = async (call) => {
  const state = await read();
  const input = call.input;
  state.commands.push({ action: call.action, input });
  if (call.action === "seedSource") {
    if (input.mode === "cleanup") {
      state.cleanups++;
      await Deno.remove(String(input.snapshot_path)).catch(() => {});
    } else {
      state.exports++;
      if (state.failExport) {
        await save(state);
        throw new Error(new TextDecoder().decode(payload));
      }
      await Deno.writeFile(String(input.snapshot_path), payload, {
        mode: 0o600,
      });
      await save(state);
      return { bytes: payload.length, sha256, snapshot: input.snapshot_path };
    }
  } else if (call.action === "seedTarget") {
    if (input.mode === "cleanup") {
      state.targetCleanups++;
      await save(state);
      return { state: "cleaned" };
    }
    if (input.mode === "prepare") {
      state.prepares++;
      await Deno.writeFile(state.incoming, new Uint8Array(), { mode: 0o600 });
      await save(state);
      return { state: "ready", incoming: state.incoming };
    }
    state.installs++;
    const bytes = await Deno.readFile(state.incoming);
    if (
      createHash("sha256").update(bytes).digest("hex") !==
        input.expected_digest || bytes.length !== input.expected_size
    ) throw new Error("invalid install");
    await save(state);
    if (state.failInstall) throw new Error(new TextDecoder().decode(payload));
    return { state: "changed" };
  }
  await save(state);
  return {};
};
export const fixtureActions = {
  exportSnapshot,
  receiveSnapshot: (
    target: Parameters<typeof receiveSnapshot>[0],
    ref: unknown,
    ctx: Parameters<typeof receiveSnapshot>[2],
  ) => receiveSnapshot(target, ref, ctx),
  cleanupSnapshot,
  seedTarget,
};
