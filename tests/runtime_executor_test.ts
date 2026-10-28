import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { join } from "jsr:@std/path@1.1.4";
import { executeRetention } from "../extensions/behaviors/runtime_retention.ts";

Deno.test("retention dispatch isolates CPU work, clears remote URLs and terminates on cancellation", async () => {
  const root = await Deno.makeTempDir();
  try {
    const shim = join(root, "swamp"), proof = join(root, "proof.json");
    await Deno.writeTextFile(
      shim,
      `#!/usr/bin/env python3
import json,os,sys,time
with open(${
        JSON.stringify(proof)
      },'w') as f:json.dump({'argv':sys.argv[1:],'server':os.environ.get('SWAMP_SERVER_URL'),'serve':os.environ.get('SWAMP_SERVE_URL'),'pid':os.getpid()},f)
until=time.monotonic()+0.3
while time.monotonic()<until: pass
`,
    );
    await Deno.chmod(shim, 0o700);
    let ticks = 0;
    const timer = setInterval(() => ticks++, 10);
    try {
      await executeRetention(root, shim);
    } finally {
      clearInterval(timer);
    }
    assert(
      ticks >= 5,
      "serve event loop must remain available while child consumes CPU",
    );
    const value = JSON.parse(await Deno.readTextFile(proof));
    assert(value.pid !== Deno.pid);
    assertEquals(value.server, "");
    assertEquals(value.serve, "");
    assertEquals(value.argv, [
      "behavior",
      "run",
      "runtime-retention-executor",
      "prune",
      "--repo-dir",
      root,
      "--skip-reports",
      "--json",
    ]);
    // Start a real child that catches SIGTERM, as native Swamp does.
    await Deno.writeTextFile(
      shim,
      (await Deno.readTextFile(shim))
        .replace(
          "import json,os,sys,time",
          "import json,os,sys,time,signal\nsignal.signal(signal.SIGTERM,lambda *_:None)",
        )
        .replace("+0.3", "+30"),
    );
    await Deno.remove(proof);
    const controller = new AbortController();
    const pending = assertRejects(
      () => executeRetention(root, shim, controller.signal),
      Error,
      "cancelled",
    );
    for (let attempt = 0; attempt < 100; attempt++) {
      if (await Deno.stat(proof).then(() => true, () => false)) break;
      await new Promise((r) => setTimeout(r, 10));
    }
    const child = Number(JSON.parse(await Deno.readTextFile(proof)).pid);
    controller.abort();
    await pending;
    assert(
      !await Deno.stat(`/proc/${child}`).then(() => true, () => false),
      "SIGTERM-ignoring child must be killed and reaped",
    );
  } finally {
    await Deno.remove(root, { recursive: true });
  }
});
