import {
  assertEquals,
  assertMatch,
  assertRejects,
} from "jsr:@std/assert@1.0.19";
import type { Http } from "../../automatic-deploy/gateway.ts";
import { maintainConn } from "./maintenance.ts";

Deno.test("Conn maintenance admits pending registered environments, keeps active cleanup, and uses a stable bounded key", async () => {
  let revision = 4;
  const keys: string[] = [];
  const paths: string[] = [];
  const http: Http = async (method, path, _body, key) => {
    await Promise.resolve();
    paths.push(path);
    if (method === "GET") {
      return {
        status: 200,
        body: {
          data: [
            {
              environment_id: "e".repeat(255),
              status: "registered",
              cleanup_pending: true,
              cleanup_version: revision,
            },
            {
              environment_id: "busy",
              status: "registered",
              cleanup_pending: true,
              cleanup_version: revision,
            },
            {
              environment_id: "expired",
              status: "session_expired",
              cleanup_pending: true,
              cleanup_version: revision,
            },
            {
              environment_id: "clean",
              status: "registered",
              cleanup_pending: false,
              cleanup_version: 0,
            },
          ],
        },
      };
    }
    keys.push(key!);
    return path.includes("/busy/")
      ? { status: 409, body: { error: { code: "operation.target_busy" } } }
      : {
        status: 202,
        body: { data: { id: 91, kind: "conn.gateway_sessions_revoke" } },
      };
  };
  assertEquals(await maintainConn(http, "tick-1"), { admitted: [91], busy: 1 });
  assertEquals(await maintainConn(http, "tick-2"), { admitted: [91], busy: 1 });
  assertEquals(keys[0], keys[2]);
  revision++;
  await maintainConn(http, "tick-3");
  assertEquals(keys[0] === keys[4], false);
  assertMatch(keys[0], /^conn\.cleanup:[a-f0-9]{64}$/);
  assertEquals(
    paths.some((path) =>
      path.includes("/expired/") || path.includes("/clean/")
    ),
    false,
  );
});

Deno.test("Conn maintenance observes an already clean revision and refuses admission errors", async () => {
  const list = {
    status: 200,
    body: {
      data: [{
        environment_id: "proof",
        status: "registered",
        cleanup_pending: true,
        cleanup_version: 4,
      }],
    },
  };
  assertEquals(
    await maintainConn(
      (method) =>
        Promise.resolve(method === "GET" ? list : { status: 204, body: null }),
      "tick",
    ),
    { admitted: [], busy: 0 },
  );
  await assertRejects(
    () =>
      maintainConn(
        (method) =>
          Promise.resolve(
            method === "GET" ? list : {
              status: 403,
              body: { error: { code: "node_access.required" } },
            },
          ),
        "tick",
      ),
    Error,
    "conn.cleanup_admission_failed",
  );
  assertEquals(
    await maintainConn(
      () =>
        Promise.resolve({
          status: 403,
          body: { error: { code: "extension.disabled" } },
        }),
      "tick",
    ),
    { admitted: [], busy: 0 },
  );
});
