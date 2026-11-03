/** Q-SECRET: opt-in probe inside the existing disposable minimal@6 proof VM, as root. */
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse, stringify } from "jsr:@std/yaml@1.0.12";
Deno.test({
  name: "Q-SECRET cfgmgmt file content stays private across create, drift, check and refusal",
  ignore: Deno.env.get("ORBIT_TEST_QSECRET_PROOF") !== "1",
  async fn() {
    const source = "/home/orbit/swamp-proof/src", repo = `${source}/swamp`, state = "/home/orbit/.orbit/swamp-proof";
    const env = {
      PATH: "/usr/local/bin:/usr/bin:/bin",
      HOME: "/home/orbit",
      SWAMP_HOME: `${state}/home`,
      SWAMP_CONFIG_DIR: `${state}/config`,
      SWAMP_NO_UPDATE_CHECK: "1",
      SWAMP_NO_TELEMETRY: "1",
      SWAMP_API_KEY_FILE: "/home/orbit/.config/swamp/club-api-key",
    };
    const markers = Array.from(
      { length: 2 },
      () => Array.from(crypto.getRandomValues(new Uint8Array(32)), (b) => b.toString(16).padStart(2, "0")).join(""),
    );
    const seen: { surface: string; sha256: string }[] = [], runs: string[] = [];
    async function clean(raw: string, label: string) {
      assert(
        markers.every((m) => !raw.includes(m) && !raw.includes(btoa(`QSECRET=${m}\n`))),
        `Q-SECRET leak in ${label}`,
      );
      const hash = Array.from(
        new Uint8Array(await crypto.subtle.digest("SHA-256", new TextEncoder().encode(raw))),
        (b) => b.toString(16).padStart(2, "0"),
      ).join("");
      seen.push({ surface: label, sha256: hash });
    }
    async function exec(args: string[], input?: string, cwd = repo, timeout = 120_000) {
      const child = new Deno.Command(args[0], {
        args: args.slice(1),
        cwd,
        env,
        clearEnv: true,
        stdin: input === undefined ? "null" : "piped",
        stdout: "piped",
        stderr: "piped",
        signal: AbortSignal.timeout(timeout),
      }).spawn();
      if (input !== undefined) {
        const writer = child.stdin.getWriter();
        await writer.write(new TextEncoder().encode(input));
        await writer.close();
      }
      const result = await child.output();
      return {
        ...result,
        stdout: new TextDecoder().decode(result.stdout),
        stderr: new TextDecoder().decode(result.stderr),
      };
    }
    async function call(args: string[], body?: string, server = true, ok = true) {
      const result = await exec([
        "/usr/sbin/runuser",
        "-u",
        "orbit",
        "--",
        "swamp",
        ...args,
        "--repo-dir",
        repo,
        "--json",
        ...(server
          ? [
            "--server",
            "wss://swamp.orbit:443",
            "--token-file",
            `${state}/credentials/operator`,
            "--ca-cert",
            `${state}/tls/ca.pem`,
          ]
          : []),
      ], body);
      await clean(result.stdout + "\n" + result.stderr, "CLI " + args.slice(0, 3).join(" "));
      if (ok) assert(result.success, result.stdout + result.stderr);
      for (let index = 0; index < result.stdout.length; index++) {
        if (!"{[".includes(result.stdout[index])) continue;
        try {
          return JSON.parse(result.stdout.slice(index));
        } catch { /* skip a non-JSON prefix */ }
      }
      if (ok) throw new Error("Missing JSON reply");
      return { returncode: result.code, output: result.stdout + result.stderr };
    }
    const name = "proof-qsecret-file";
    let created = await call(["workflow", "get", name], undefined, true, false);
    if (!created.path) created = await call(["workflow", "create", name]);
    const path = created.path.startsWith("/") ? created.path : `${repo}/${created.path}`;
    const workflow = parse(await Deno.readTextFile(path)) as Record<string, unknown>;
    Object.assign(workflow, {
      vaults: ["orbit-control-inputs"],
      queueTimeout: 30,
      inputs: {
        type: "object",
        properties: { path: { type: "string" }, method: { type: "string", enum: ["apply", "check"] } },
        required: ["path", "method"],
      },
      jobs: [{
        name: "file",
        labels: { role: "swamp" },
        steps: [{
          name: "Converge private file",
          labels: { role: "swamp" },
          writes: true,
          task: {
            type: "behavior",
            behaviorType: "@swamp/cfgmgmt/file",
            behaviorName: "proof-qsecret-file-target",
            globalArgs: {
              nodeHost: "10.231.0.10",
              nodeUser: "orbit",
              nodeIdentityFile: "/etc/orbit/swamp-role-worker/ssh-identity",
              become: true,
              path: "${{ inputs.path }}",
              ensure: "present",
              content: '${{ vault.get("orbit-control-inputs", "qsecret-file") }}',
              owner: "root",
              group: "root",
              mode: "0600",
            },
            methodName: "${{ inputs.method }}",
            inputs: {},
          },
        }],
      }],
    });
    await Deno.writeTextFile(path, stringify(workflow));
    await Deno.chown(path, 1000, 1000);
    await call(["workflow", "validate", name]);
    const start = performance.now();
    const target = async (...args: string[]) => {
      const result = await exec(["incus", "exec", "target", "--", ...args], undefined, repo, 20_000);
      assert(result.success, "Disposable target command failed");
      return result;
    };
    await target("rm", "-f", "/etc/orbit/qsecret-proof.env");
    try {
      for (const marker of markers) {
        const value = `QSECRET=${marker}\n`;
        const code =
          'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); config(["swamp.run_token"=>trim(file_get_contents("/home/orbit/.orbit/swamp-proof/credentials/operator"))]); app(App\\Infrastructure\\Swamp\\SwampVaultExecutionCopy::class)->synchronize("orbit-control-inputs","qsecret-file",stream_get_contents(STDIN)); echo "stored";';
        const put = await exec(
          ["/usr/sbin/runuser", "-u", "orbit", "--", "php", "-r", code],
          value,
          `${source}/apps/gateway`,
          60_000,
        );
        await clean(put.stdout + put.stderr, "native vault.put acknowledgement");
        assert(put.success);
        for (const method of ["check", "apply", "check"]) {
          const reply = await call(
            ["workflow", "run", name, "--stdin"],
            JSON.stringify({ path: "/etc/orbit/qsecret-proof.env", method }),
          );
          assertEquals(reply.status, "succeeded");
          const id = reply.id ?? reply.runId;
          runs.push(id);
          for (const kind of ["get", "logs", "outputs"]) await call(["workflow", "history", kind, id]);
        }
        assert((await target("cat", "/etc/orbit/qsecret-proof.env")).stdout === value, "Target content differs");
        assertEquals(
          (await target("stat", "-c", "%a %U %G", "/etc/orbit/qsecret-proof.env")).stdout.trim(),
          "600 root root",
        );
      }
      await target("mkdir", "-p", "/etc/orbit/qsecret-directory");
      const refused = await call(
        ["workflow", "run", name, "--stdin"],
        JSON.stringify({ path: "/etc/orbit/qsecret-directory", method: "apply" }),
        true,
        false,
      );
      assert(refused.status === "failed" || (refused.returncode ?? 0) !== 0, "Directory write should fail");
      for (const kind of ["get", "logs", "outputs"]) await call(["workflow", "history", kind, name]);
      await call(["data", "query", 'modelName == "proof-qsecret-file-target" && version >= 0', "--select", "content"]);
      await call(["behavior", "get", "proof-qsecret-file-target"]);
      for (const unit of ["orbit-swamp-serve", "orbit-node-worker"]) {
        const result = await exec(["journalctl", "-u", unit, "--no-pager", "-o", "cat"], undefined, repo, 20_000);
        await clean(result.stdout + result.stderr, "journal " + unit);
      }
      const journal = await target("journalctl", "--no-pager", "-o", "cat");
      await clean(journal.stdout + journal.stderr, "target journal");
    } finally {
      await call(["vault", "delete", "orbit-control-inputs", "qsecret-file"]);
    }
    console.log(
      JSON.stringify({
        question: "Q-SECRET",
        proof_pass: true,
        runtime: await call(["--version"], undefined, true, false),
        source: (await exec(["/usr/sbin/runuser", "-u", "orbit", "--", "git", "-C", source, "rev-parse", "HEAD"]))
          .stdout.trim(),
        topology: "minimal@6",
        runs,
        run_seconds: Math.round((performance.now() - start) / 100) / 10,
        surfaces: seen,
        file: { mode: "0600", owner: "root", group: "root", content_matches: true },
        scenarios: ["create", "change", "check drift", "check compliant", "refused directory write"],
        diff: "file changes array; pinned runtime has no separate data diff command",
      }),
    );
  },
});
