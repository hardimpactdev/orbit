import {
  assert,
  assertEquals,
  assertRejects,
  assertThrows,
} from "jsr:@std/assert@1.0.14";
import { DockerEngine } from "./docker_engine.ts";
import type { SystemCommand } from "../node/system_command.ts";

Deno.test("Engine accepts Metrics-owned identity and sends private create JSON only on stdin", async () => {
  const calls: SystemCommand[] = [];
  const engine = new DockerEngine((command) => {
    calls.push(command);
    return Promise.resolve({ success: true, stdout: '{"Id":"created"}' });
  });
  const body = {
    Image: "grafana/grafana:12.0.0",
    Labels: { "com.orbit.managed": "metrics", "com.orbit.service": "grafana" },
    Env: ["PASSWORD=private-value"],
    HostConfig: {
      Mounts: [{
        Type: "volume",
        Source: "orbit-metrics-grafana",
        Target: "/var/lib/grafana",
      }],
    },
  };
  assertEquals(await engine.create("orbit-metrics-grafana", body), {
    success: true,
    stdout: '{"Id":"created"}',
  });
  assertEquals(calls.length, 1);
  assertEquals(calls[0].command, "/usr/bin/sudo");
  assertEquals(calls[0].args.slice(0, 3), ["-n", "--", "/usr/bin/curl"]);
  assertEquals(JSON.parse(calls[0].stdin!), body);
  assertEquals(
    calls[0].args.at(-1),
    "http://localhost/containers/create?name=orbit-metrics-grafana",
  );
  assert(!calls[0].args.join(" ").includes("private-value"));
});

Deno.test("Engine control uses argv and propagates a proven command refusal", async () => {
  const abort = new AbortController();
  const engine = new DockerEngine((command, signal) => {
    assertEquals(signal, abort.signal);
    assertEquals(command, {
      command: "/usr/bin/sudo",
      args: [
        "-n",
        "--",
        "/usr/bin/docker",
        "--host=unix:///var/run/docker.sock",
        "container",
        "stop",
        "orbit-metrics-prometheus",
      ],
      timeoutMs: 45000,
    });
    return Promise.resolve({ success: false, stdout: "" });
  });
  assertEquals(
    await engine.call(
      ["container", "stop", "orbit-metrics-prometheus"],
      abort.signal,
      45000,
    ),
    {
      success: false,
      stdout: "",
    },
  );
});

Deno.test("Engine refuses a create name that changes the fixed socket request", () => {
  const engine = new DockerEngine(() => {
    throw new Error("must not invoke transport");
  });
  for (
    const name of [
      "../foreign",
      "owned&privileged=true",
      "--foreign",
      "owned\nforeign",
    ]
  ) {
    assertThrows(
      () => engine.create(name, { Image: "image" }),
      Error,
      "Docker container name is invalid",
    );
  }
});

Deno.test("Engine leaves uncertain effects thrown and discards private transport diagnostics", async () => {
  const engine = new DockerEngine(() => {
    throw new Error("private-value");
  });
  for (
    const invoke of [
      () => engine.call(["container", "restart", "orbit-metrics-grafana"]),
      () =>
        engine.create("orbit-metrics-grafana", {
          Image: "image",
          Env: ["SECRET=private-value"],
        }),
    ]
  ) {
    const error = await assertRejects(
      invoke,
      Error,
      "Docker Engine command has no confirmed outcome",
    );
    assert(!error.message.includes("private-value"));
  }
});

Deno.test("Engine CLI validation sends configuration on stdin instead of argv", async () => {
  let captured: SystemCommand | undefined;
  const engine = new DockerEngine((command) => {
    captured = command;
    return Promise.resolve({ success: true, stdout: "valid" });
  });
  await engine.call(
    [
      "container",
      "run",
      "--interactive",
      "prom/prometheus:v3.5.0",
      "promtool",
      "check",
      "config",
      "/dev/stdin",
    ],
    undefined,
    120000,
    "private-prometheus-config",
  );
  assertEquals(captured?.stdin, "private-prometheus-config");
  assertEquals(
    captured?.args.some((part) => part.includes("private-prometheus-config")),
    false,
  );
});
