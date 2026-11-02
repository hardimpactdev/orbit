import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { convergeSwampWorkers, removeSwampWorkers } from "./role_swamp_workers.ts";
import type { SystemTransport } from "./system_command.ts";

const SWAMP_WORKERS_PROGRAM = await Deno.readTextFile(
  new URL("../fleet-recipe/scripts/swamp_workers.py", import.meta.url),
);

const intent = {
  role: "swamp",
  node_id: 209,
  agent_sha256: "a".repeat(64),
  assignment_id: 244,
  user: "orbit",
  workers: ["a", "b"],
  concurrency: 8,
  scheduled_runs: 4,
};

function recording(stdout: string, success = true) {
  const calls: Parameters<SystemTransport>[0][] = [];
  const transport: SystemTransport = (input) => {
    calls.push(input);
    return Promise.resolve({ success, stdout });
  };
  return { calls, transport };
}

Deno.test("converge runs the fixed root program with the admitted public intent only", async () => {
  const { calls, transport } = recording('{"completed":true,"changed":true}');
  assertEquals(await convergeSwampWorkers(intent, SWAMP_WORKERS_PROGRAM, undefined, transport), {
    completed: true,
    changed: true,
  });
  assertEquals(calls.length, 1);
  assertEquals(calls[0].args, [
    "-n",
    "--",
    "/usr/bin/python3",
    "-I",
    "-c",
    SWAMP_WORKERS_PROGRAM,
  ]);
  assertEquals(JSON.parse(calls[0].stdin ?? ""), {
    operation: "converge",
    data: intent,
  });
});

Deno.test("the program renders loopback workers that share the role label", () => {
  for (
    const part of [
      "ws://127.0.0.1:' + str(PORT)",
      "PORT = 9418",
      "--label role=swamp --label node=",
      "--concurrency ",
      "orbit-swamp-worker@",
      "LoadCredential=enrollment:",
      "0o440",
    ]
  ) assertEquals(SWAMP_WORKERS_PROGRAM.includes(part), true, part);
});

Deno.test("converge runs serve with the role's limits and a 5 minute drain, and never restarts it", () => {
  for (
    const part of [
      "orbit-swamp-serve.service",
      "--max-concurrent-scheduled-runs ' + str(data['scheduled_runs']) + ' --shutdown-drain-timeout 5m'",
      "TimeoutStopSec=360",
      "' SWAMP_DATASTORE_POLL_INTERVAL=5m'",
      "elif not serving() and not systemctl('enable', '--now', 'orbit-swamp-serve.service')",
    ]
  ) assertEquals(SWAMP_WORKERS_PROGRAM.includes(part), true, part);
  assertEquals(/systemctl\('(restart|reload-or-restart)', [^)]*orbit-swamp-serve/.test(SWAMP_WORKERS_PROGRAM), false);
});

Deno.test("grants mode renders every manifest grant for the Gateway principal", async () => {
  const repo = await Deno.makeTempDir();
  try {
    await Deno.mkdir(`${repo}/config`);
    const manifest = new URL("../../../../config/gateway-workflow-grants.json", import.meta.url);
    await Deno.copyFile(manifest, `${repo}/config/gateway-workflow-grants.json`);
    const result = await new Deno.Command("python3", {
      args: ["-I", new URL("../fleet-recipe/scripts/swamp_workers.py", import.meta.url).pathname, "grants", repo],
    }).output();
    assertEquals(result.success, true, new TextDecoder().decode(result.stderr));
    const source = JSON.parse(await Deno.readTextFile(manifest));
    const grants = JSON.parse(await Deno.readTextFile(`${repo}/grants/orbit-gateway.yaml`)).grants;
    assertEquals(grants.length, source.vaults.length + source.workflows.length);
    assertEquals(grants.every((grant: { subject: string }) => grant.subject === "user:orbit-gateway"), true);
    assertEquals(
      grants.find((grant: { resource: string }) => grant.resource === "workflow:schedule-*").actions,
      ["run", "read", "write"],
    );
    assertEquals(
      grants.some((grant: { condition?: string }) => grant.condition?.includes("^schedule-command-")),
      true,
    );
  } finally {
    await Deno.remove(repo, { recursive: true });
  }
});

Deno.test("the release restart compares manifest additionalFiles with the commit serve started from (Lab #4282)", async () => {
  const repo = await Deno.makeTempDir();
  const git = async (...args: string[]) => {
    const result = await new Deno.Command("git", { args, cwd: repo, stdout: "piped", stderr: "piped" }).output();
    assertEquals(result.success, true, new TextDecoder().decode(result.stderr));
    return new TextDecoder().decode(result.stdout).trim();
  };
  const manifest = async (dir: string, files: string[]) => {
    await Deno.mkdir(`${repo}/swamp/extensions/${dir}`, { recursive: true });
    await Deno.writeTextFile(
      `${repo}/swamp/extensions/${dir}/manifest.yaml`,
      `name: ${dir}\nadditionalFiles:\n${files.map((f) => `  - ${f}\n`).join("")}`,
    );
  };
  const commit = async () => {
    await git("add", "-A");
    await git("-c", "user.name=t", "-c", "user.email=t@t", "commit", "-qm", "c");
    return await git("rev-parse", "HEAD");
  };
  try {
    await git("init", "-q");
    await manifest("a", ["scripts/one.py"]);
    await manifest("b", ["scripts/two.py"]);
    const served = await commit();
    await Deno.writeTextFile(`${repo}/swamp/extensions/b/other.ts`, "");
    const same = await commit();
    await manifest("a", ["scripts/one.py", "scripts/three.py"]);
    const changed = await commit();
    const program = SWAMP_WORKERS_PROGRAM.split("if len(sys.argv) == 3 and sys.argv[1] == 'release-restart':")[0];
    const result = await new Deno.Command("python3", {
      args: ["-I", "-c", `${program}\nprint(json.dumps([sorted(p for p in set(b) | set(a) if b.get(p) != a.get(p))
    for b, a in [(additional_files(sys.argv[1]), additional_files(c)) for c in sys.argv[2:]]]))`, served, same, changed],
      cwd: repo,
    }).output();
    assertEquals(result.success, true, new TextDecoder().decode(result.stderr));
    assertEquals(JSON.parse(new TextDecoder().decode(result.stdout)), [[], ["swamp/extensions/a/manifest.yaml"]]);
  } finally {
    await Deno.remove(repo, { recursive: true });
  }
  for (
    const part of [
      "'HEAD@{' + started + '}'",
      "'ExecMainStartTimestamp'",
      "'--on-active=5s'",
      "https://swamp-club.com/lab/4282",
    ]
  ) assertEquals(SWAMP_WORKERS_PROGRAM.includes(part), true, part);
});

Deno.test("a refusal keeps its public code and an unreadable result is uncertain", async () => {
  assertEquals(
    await convergeSwampWorkers(
      intent,
      SWAMP_WORKERS_PROGRAM,
      undefined,
      recording('{"error_code":"swamp.worker_credentials_missing"}').transport,
    ),
    { error_code: "swamp.worker_credentials_missing" },
  );
  await assertRejects(() =>
    convergeSwampWorkers(intent, SWAMP_WORKERS_PROGRAM, undefined, recording("", false).transport)
  );
});

Deno.test("worker names and removal intent are validated before any command", async () => {
  const { calls, transport } = recording('{"completed":true,"changed":true}');
  await assertRejects(() =>
    convergeSwampWorkers({ ...intent, workers: ["a", "a"] }, SWAMP_WORKERS_PROGRAM, undefined, transport)
  );
  await assertRejects(() =>
    convergeSwampWorkers({ ...intent, workers: ["../x"] }, SWAMP_WORKERS_PROGRAM, undefined, transport)
  );
  assertEquals(calls.length, 0);
  await removeSwampWorkers({
    role: "swamp",
    node_id: 209,
    agent_sha256: "a".repeat(64),
    workers: ["a", "b"],
    purge_data: false,
  }, SWAMP_WORKERS_PROGRAM, undefined, transport);
  assertEquals(JSON.parse(calls[0].stdin ?? "").operation, "remove");
});
