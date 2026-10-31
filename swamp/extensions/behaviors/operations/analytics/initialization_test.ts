import { assertEquals } from "jsr:@std/assert@1.0.19";
import { initializePlausible } from "./initialization.ts";

const desired = {
  process_id: 250,
  container: "orbit-process-250-plausible",
  spec: "a".repeat(64),
  image: "ghcr.io/plausible/community-edition:v3.2.1",
  desired: "running" as const,
  command: ["/entrypoint.sh", "run"],
  restart: "unless-stopped",
  working_directory: null,
  ports: ["10.44.0.3:8000:8000/tcp"],
  mounts: [],
};
const environment = { SECRET_KEY_BASE: "initialization-private-fixture" };

Deno.test("Swamp initializes in order, waits for exit and removes only its temporary containers", async () => {
  const events: unknown[] = [];
  const result = await initializePlausible(
    desired,
    environment,
    (name, input) => {
      events.push([name, input.container ?? input.name, input.command]);
      if (name === "process.converge-container") {
        assertEquals(input.environment, environment);
        assertEquals(input.ports, []);
        assertEquals(input.restart, "no");
      }
      return Promise.resolve({});
    },
    undefined,
    (program, args, stdin) => {
      assertEquals(program, "/usr/bin/sudo");
      assertEquals(args.slice(0, 3), ["-n", "--", "/usr/bin/docker"]);
      assertEquals(stdin, undefined);
      events.push(args.slice(3));
      return Promise.resolve({ code: 0, stdout: "0\n" });
    },
  );
  assertEquals(result, { ready: true });
  assertEquals(events, [
    ["process.converge-container", `${desired.container}-init-createdb`, [
      "/entrypoint.sh",
      "db",
      "createdb",
    ]],
    ["container", "wait", `${desired.container}-init-createdb`],
    ["process.remove", `${desired.container}-init-createdb`, undefined],
    ["process.converge-container", `${desired.container}-init-migrate`, [
      "/entrypoint.sh",
      "db",
      "migrate",
    ]],
    ["container", "wait", `${desired.container}-init-migrate`],
    ["process.remove", `${desired.container}-init-migrate`, undefined],
  ]);
  assertEquals(
    JSON.stringify(result).includes(environment.SECRET_KEY_BASE),
    false,
  );
});

Deno.test("each failed initialization stops the sequence after cleanup", async () => {
  for (const failure of [0, 1]) {
    let exits = 0;
    const calls: string[] = [];
    const result = await initializePlausible(
      desired,
      environment,
      (name) => {
        calls.push(name);
        return Promise.resolve({});
      },
      undefined,
      () =>
        Promise.resolve({
          code: 0,
          stdout: exits++ === failure ? "7\n" : "0\n",
        }),
    );
    assertEquals(result, {
      error_code: "analytics.initialization_failed",
      rolled_back: true,
    });
    assertEquals(
      calls,
      Array.from(
        { length: failure + 1 },
        () => ["process.converge-container", "process.remove"],
      ).flat(),
    );
  }
});

Deno.test("foreign initialization identity refusal never waits or removes a container", async () => {
  const calls: string[] = [];
  const result = await initializePlausible(
    desired,
    environment,
    (name) => {
      calls.push(name);
      return Promise.resolve({ error_code: "process.name_collision" });
    },
    undefined,
    () => {
      throw new Error("must not wait");
    },
  );
  assertEquals(calls, ["process.converge-container"]);
  assertEquals(result, {
    error_code: "analytics.initialization_failed",
    rolled_back: true,
  });
});

Deno.test("uncertain initialization keeps diagnostics private and never starts migration", async () => {
  let calls = 0;
  const result = await initializePlausible(
    desired,
    environment,
    () => {
      calls++;
      return Promise.resolve({});
    },
    undefined,
    () => {
      throw new Error(environment.SECRET_KEY_BASE);
    },
  );
  assertEquals(calls, 2);
  assertEquals(result, {
    error_code: "analytics.initialization_failed",
    rolled_back: true,
  });
});

Deno.test("cancelled initialization removes owned ephemeral containers and volumes with an independent cleanup signal", async () => {
  const signal = AbortSignal.abort();
  const calls: string[] = [];
  const outcome = await initializePlausible(
    desired,
    environment,
    (name, input, actionSignal) => {
      calls.push(name);
      if (name === "process.remove") {
        assertEquals(actionSignal, undefined);
        assertEquals(input.anonymous_volumes, true);
      }
      return Promise.resolve({});
    },
    signal,
    () => {
      throw new Error("aborted private wait");
    },
  );
  assertEquals(calls, ["process.converge-container", "process.remove"]);
  assertEquals(outcome, {
    error_code: "analytics.initialization_failed",
    rolled_back: true,
  });
});
