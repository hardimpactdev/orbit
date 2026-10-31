import { assertEquals } from "jsr:@std/assert@1";
import { behavior } from "./script_assets.ts";

Deno.test("native asset metadata records paths and public intent as step outputs", async () => {
  const writes: unknown[] = [];
  const bootstrap = { node_id: 209, target: { host: "dev.example" } };
  const result = await behavior.methods.read.execute(
    { intent: { bootstrap } },
    {
      extensionFile: (name) => `/worker/cache/fingerprint/${name}`,
      writeResource: (...args) => Promise.resolve(writes.push(args)),
    },
  );
  assertEquals(result, {
    scan_script: "/worker/cache/fingerprint/scripts/scan_host_key.py",
    probe_script: "/worker/cache/fingerprint/scripts/probe_host.py",
    input_json: JSON.stringify(bootstrap),
  });
  assertEquals(writes, [["assets", "assets", result]]);
});
