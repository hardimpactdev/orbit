import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { bootstrapSsh } from "../../../../tests/_support/bootstrap_ssh.ts";
import { type History, type Run } from "../../../../tests/_support/native_harness.ts";

Deno.test("remote worker resolves the vault identity and preserves terminal outputs and failure ordering", async () => {
  const { repo, publicKey, reference } = await bootstrapSsh("operation-node-bootstrap-probe", 209);
  await using _cleanup = repo;
  const marker = `${repo.dir}/fail-observation`;
  const ssh = await repo.fakeBinary("ssh", `
if (Deno.args.some((arg) => ["-O", "-N", "-MNf"].includes(arg))) Deno.exit(0);
const command = Deno.args.at(-1);
if (command.includes("uname")) {
  try { Deno.statSync(${JSON.stringify(marker)}); Deno.exit(1); } catch (error) { if (!(error instanceof Deno.errors.NotFound)) throw error; }
  console.log("Linux x86_64");
} else if (command.includes("cat ")) console.log(${JSON.stringify(publicKey)});
else throw new Error("Unexpected official SSH command: " + command);
`);
  await repo.workflow("operation-node-bootstrap-probe", (workflow) => {
    for (const job of workflow.jobs) {
      job.labels = { role: "swamp" };
      for (const step of job.steps as {labels?:unknown;task:{behaviorType?:string;globalArgs?:Record<string,unknown>}}[]) {
        step.labels = { role: "swamp" };
        if (step.task.behaviorType === "@swamp/ssh") step.task.globalArgs!.sshBinary = ssh;
      }
    }
  });
  await repo.cli(["access", "token", "mint", "fixture-admin", "--principal", "user:fixture"]);
  const tokenFile = async (name: string, token: string) => {
    const path = `${repo.dir}/${name}`;
    await Deno.writeTextFile(path, token, {mode:0o600});
    return path;
  };
  const admin = await tokenFile("admin", JSON.parse((await repo.cli(["access", "token", "reveal", "fixture-admin", "--yes"])).stdout).token);
  const listener = Deno.listen({hostname:"127.0.0.1",port:0});
  const port = listener.addr.port;
  listener.close();
  const url = `ws://127.0.0.1:${port}`;
  const serve = await repo.spawn(["serve", "--host", "127.0.0.1", "--port", String(port), "--auth-mode", "token", "--admins", "user:fixture", "--remote-only"]);
  const processes = [serve];
  const outputs = [serve.output()];
  try {
    let ready = false;
    for (let attempt = 0; attempt < 100; attempt++) {
      try {
        const response = await fetch(`http://127.0.0.1:${port}/ready`);
        await response.body?.cancel();
        if (response.ok) { ready = true; break; }
      } catch { /* Server is starting. */ }
      await new Promise((resolve) => setTimeout(resolve, 100));
    }
    assert(ready, "native serve did not become ready");
    const flags = ["--server", url, "--token-file", admin];
    const enrollment = await tokenFile("enrollment", JSON.parse((await repo.cli(["worker", "token", "create", "orbit-node-209", "--duration", "10m", ...flags])).stdout).token);
    await repo.cli(["access", "token", "mint", "fixture-worker", "--principal", "worker:orbit-node-209", ...flags]);
    const token = await tokenFile("worker-server", JSON.parse((await repo.cli(["access", "token", "reveal", "fixture-worker", "--yes"])).stdout).token);
    // The tracked graph requires role=swamp; node=209 alone leaves every step queued.
    const worker = await repo.spawn(["worker", "connect", url, "--token-file", enrollment, "--server-token-file", token,
      "--cache-dir", `${repo.dir}/worker-cache`, "--label", "node=209", "--label", "role=swamp"]);
    processes.push(worker);
    outputs.push(worker.output());
    const digest = new Uint8Array(await crypto.subtle.digest("SHA-256", Uint8Array.from(atob(publicKey.split(" ")[1]), (char) => char.charCodeAt(0))));
    const fingerprint = "SHA256:" + btoa(String.fromCharCode(...digest)).replace(/=+$/, "");
    const inputs = {
      operation_id: 7001, node_id: 209, kind: "node.bootstrap_probe",
      intent: { bootstrap: { node_id: 23, platform: "linux", expected_architecture: "x86_64",
        target: { node_id: 23, host: "dev.example", port: 2222, user: "candidate", fingerprint, host_keys: [publicKey] } } },
      execution_secrets: { bootstrap_identity: reference },
    };
    const run = async (value: typeof inputs, success = true) => {
      const result = JSON.parse((await repo.cli(["workflow", "run", "operation-node-bootstrap-probe", "--input", JSON.stringify(value), ...flags], {success})).stdout) as Run;
      const history = JSON.parse((await repo.cli(["workflow", "history", "get", result.id, ...flags])).stdout) as History;
      return { result, history, steps: history.jobs[0].steps };
    };
    const passed = await run(inputs);
    assertEquals(passed.result.status, "succeeded");
    assertEquals(passed.history.inputs, inputs);
    assertEquals(passed.steps.map((step) => [step.name, step.status]), [
      ["inspect-platform", "succeeded"], ["inspect-host-key", "succeeded"], ["probe-host", "succeeded"],
    ]);
    assertEquals(JSON.parse(passed.steps[2].outputs!.stdout as string), {
      probed: true, architecture: "x86_64", host_type: "ssh-ed25519", public_key: publicKey.split(" ")[1], host_key_fingerprint: fingerprint,
    });
    assertEquals(passed.steps[0].outputs!.exitCode, 0);
    assertEquals(passed.steps[1].outputs!.algorithm, "ssh-ed25519");
    const evidence = Deno.env.get("BOOTSTRAP_NATIVE_EVIDENCE");
    if (evidence) await Deno.writeTextFile(evidence, JSON.stringify(passed.history, null, 2) + "\n");
    const unpinned = structuredClone(inputs);
    unpinned.operation_id = 7003;
    unpinned.intent.bootstrap.target.host_keys = [];
    const rejected = await run(unpinned, false);
    assertEquals(rejected.result.status, "failed");
    assertEquals(rejected.steps[0].status, "failed");
    assertEquals(rejected.steps.at(-1)!.status, "skipped");
    inputs.operation_id = 7002;
    await Deno.writeTextFile(marker, "");
    const failed = await run(inputs, false);
    assertEquals(failed.history.status, "failed");
    assertEquals(failed.steps[0].status, "failed");
    assertEquals(failed.steps[1].status, "skipped");
  } finally {
    for (const process of processes.reverse()) {
      try { process.kill("SIGTERM"); } catch { /* Already exited. */ }
      const timer = setTimeout(() => { try { process.kill("SIGKILL"); } catch { /* Already exited. */ } }, 10_000);
      try { await process.status; } finally { clearTimeout(timer); }
    }
    for (const output of await Promise.all(outputs)) {
      await repo.assertPrivateAbsent(new TextDecoder().decode(output.stdout), new TextDecoder().decode(output.stderr));
    }
  }
});
