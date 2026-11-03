/** Run the same scratch repository through loopback serve and a real native worker. */
import { assert } from "jsr:@std/assert@1.0.19";
import { NativeRepo } from "./native_harness.ts";
export async function localWorker<T>(
  repo: NativeRepo,
  action: (flags: string[], processes: { serve: Deno.ChildProcess; worker?: Deno.ChildProcess }) => Promise<T>,
  options: { labels?: string[]; worker?: boolean; serveArgs?: string[] } = {},
) {
  repo.timeoutMs = 120_000;
  await repo.cli(["access", "token", "mint", "fixture-admin", "--principal", "user:fixture"]);
  const admin = `${repo.dir}/admin`;
  await Deno.writeTextFile(
    admin,
    JSON.parse((await repo.cli(["access", "token", "reveal", "fixture-admin", "--yes"])).stdout).token,
    { mode: 0o600 },
  );
  const listener = Deno.listen({ hostname: "127.0.0.1", port: 0 });
  const port = (listener.addr as Deno.NetAddr).port;
  listener.close();
  const url = `ws://127.0.0.1:${port}`, flags = ["--server", url, "--token-file", admin];
  const children: { child: Deno.ChildProcess; output: Promise<Deno.CommandOutput>; log: string }[] = [];
  const start = async (args: string[], log: string) => {
    const child = await repo.spawn(args);
    children.push({ child, output: child.output(), log });
    return child;
  };
  try {
    const serve = await start([
      "serve",
      "--host",
      "127.0.0.1",
      "--port",
      String(port),
      "--auth-mode",
      "token",
      "--admins",
      "user:fixture",
      "--remote-only",
      ...options.serveArgs ?? [],
    ], "serve.log");
    let exited: Deno.CommandOutput | undefined;
    void children[0].output.then((result) => { exited = result; });
    let ready = false;
    const deadline = Date.now() + repo.timeoutMs;
    while (Date.now() < deadline && !exited) {
      try {
        const response = await fetch(`http://127.0.0.1:${port}/ready`, { signal: AbortSignal.timeout(1000) });
        await response.body?.cancel();
        if (response.ok) {
          ready = true;
          break;
        }
      } catch { /* starting */ }
      await new Promise((resolve) => setTimeout(resolve, 100));
    }
    assert(ready, exited
      ? `Loopback serve exited before readiness: ${new TextDecoder().decode(exited.stdout)}${new TextDecoder().decode(exited.stderr)}`
      : `Loopback serve did not become ready within ${repo.timeoutMs}ms`);
    let worker: Deno.ChildProcess | undefined;
    if (options.worker !== false) {
      const enrollment = `${repo.dir}/enrollment`;
      await Deno.writeTextFile(
        enrollment,
        JSON.parse(
          (await repo.cli(["worker", "token", "create", "orbit-node-209", "--duration", "10m", ...flags])).stdout,
        ).token,
        { mode: 0o600 },
      );
      await repo.cli(["access", "token", "mint", "fixture-worker", "--principal", "worker:orbit-node-209", ...flags]);
      const token = `${repo.dir}/worker-server`;
      await Deno.writeTextFile(
        token,
        JSON.parse((await repo.cli(["access", "token", "reveal", "fixture-worker", "--yes"])).stdout).token,
        { mode: 0o600 },
      );
      worker = await start([
        "worker",
        "connect",
        url,
        "--token-file",
        enrollment,
        "--server-token-file",
        token,
        "--cache-dir",
        `${repo.dir}/worker-cache`,
        ...(options.labels ?? ["node=209"]).flatMap((label) => ["--label", label]),
      ], "worker.log");
    }
    return await action(flags, { serve, worker });
  } finally {
    for (const { child } of children.toReversed()) {
      try {
        child.kill("SIGTERM");
      } catch { /* exited */ }
    }
    for (const { output, log } of children) {
      const result = await output;
      await Deno.writeTextFile(
        `${repo.dir}/${log}`,
        new TextDecoder().decode(result.stdout) + new TextDecoder().decode(result.stderr),
      );
    }
    await repo.assertPrivateAbsent();
  }
}
