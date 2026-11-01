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
      "ws://127.0.0.1:9418",
      "--label role=swamp --label node=",
      "--concurrency ",
      "orbit-swamp-worker@",
      "LoadCredential=enrollment:",
      "0o440",
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
