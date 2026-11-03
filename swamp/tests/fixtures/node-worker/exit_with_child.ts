/** A worker-like main process exits once while its connection child stays alive. */
const [phase, ready] = Deno.args;

try {
  await Deno.stat(phase);
} catch {
  await Deno.writeTextFile(phase, "first start");
  new Deno.Command("/usr/bin/sleep", { args: ["10"], stdout: "null", stderr: "null" }).spawn().unref();
  Deno.exit(1);
}
await Deno.writeTextFile(ready, "restarted");
await new Promise((resolve) => setTimeout(resolve, 10_000));
