import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { runRecipeProgram } from "./recipe_local.ts";

Deno.test("default recipe runner drains stdout while writing a larger typed input", async () => {
  const payload = "x".repeat(256 * 1024);
  const output = await runRecipeProgram({
    // Only this disposable process and its anonymous pipes are touched. A small
    // pipe makes the regression independent of the machine's default capacity.
    program: String.raw`
import fcntl
import json
import sys
fcntl.fcntl(sys.stdout.fileno(), fcntl.F_SETPIPE_SZ, 4096)
sys.stdout.write('{"padding":"' + ('p' * 60000) + '","input_bytes":')
sys.stdout.flush()
value = json.load(sys.stdin)
sys.stdout.write(str(len(value['payload'])) + '}\n')
sys.stdout.flush()
`,
    input: { payload },
    mutates: false,
    timeoutMs: 3000,
  });
  assertEquals(output, {
    padding: "p".repeat(60000),
    input_bytes: payload.length,
  });
});

Deno.test("default recipe runner rejects a deadline even if its child handles TERM successfully", async () => {
  const error = await assertRejects(() =>
    runRecipeProgram({
      program: String.raw`
import json
import signal
import sys
import time
signal.signal(signal.SIGTERM, lambda *_: sys.exit(0))
json.load(sys.stdin)
print('{}', flush=True)
time.sleep(10)
`,
      input: {},
      mutates: false,
      timeoutMs: 500,
    }), DOMException);
  assertEquals(error.name, "TimeoutError");
});
