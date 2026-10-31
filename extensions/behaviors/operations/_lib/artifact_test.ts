import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { boundedArtifactResponse } from "../../automatic-deploy/gateway.ts";
import { GatewayOperation } from "./direct.ts";

const args = {
  operation_id: 17,
  kind: "sample.apply",
  node_id: 9,
  run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
};
const bytes = new TextEncoder().encode("pinned binary");
const digest = Array.from(
  new Uint8Array(await crypto.subtle.digest("SHA-256", bytes)),
  (b) => b.toString(16).padStart(2, "0"),
).join("");
const path =
  `/api/v1/direct-operations/17/private-material?run_id=${args.run_id}&artifact=binary`;

Deno.test("shared artifact fetch accepts only the exact run reference and admitted digest", async () => {
  for (
    const change of [
      "none",
      "foreign-path",
      "digest",
      "size",
      "bound",
      "bytes",
      "status",
    ]
  ) {
    let downloads = 0;
    const gateway = new GatewayOperation((_method, requested) => {
      if (requested.includes("&artifact=")) {
        downloads++;
        const body = bytes.slice();
        if (change === "bytes") body[0] ^= 1;
        return Promise.resolve({
          status: change === "status" ? 403 : 200,
          body,
        });
      }
      return Promise.resolve({
        status: 200,
        body: {
          data: {
            binary: {
              path: change === "foreign-path"
                ? "https://unrelated.test/file"
                : path,
              sha256: change === "digest" ? "f".repeat(64) : digest,
              bytes: change === "size" ? bytes.length + 1 : bytes.length,
              max_bytes: change === "bound" ? 268435457 : 268435456,
            },
          },
        },
      });
    }, args);
    if (change === "none") {
      assertEquals(await gateway.artifact("binary", digest), bytes);
    } else {await assertRejects(
        () => gateway.artifact("binary", digest),
        Error,
        "Private material callback failed",
      );}
    assertEquals(
      downloads,
      ["foreign-path", "digest", "bound"].includes(change) ? 0 : 1,
    );
  }
});

Deno.test("binary HTTP replies reject missing oversized truncated and overlong bodies", async () => {
  assertEquals(
    await boundedArtifactResponse(
      new Response(bytes, {
        headers: { "Content-Length": String(bytes.length) },
      }),
    ),
    bytes,
  );
  for (
    const length of [
      null,
      "0",
      "268435457",
      "1",
      String(bytes.length + 1),
      "not-a-number",
    ]
  ) {
    const headers = length === null ? undefined : { "Content-Length": length };
    await assertRejects(() =>
      boundedArtifactResponse(new Response(bytes, { headers }))
    );
  }
});
