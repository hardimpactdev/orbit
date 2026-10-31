const path = "instance-native.json";
function read() {
  return JSON.parse(Deno.readTextFileSync(path));
}
function save(value: unknown) {
  Deno.writeTextFileSync(path, JSON.stringify(value));
}
export function http(method: string, url: string, body?: unknown) {
  const state = read();
  state.calls.push({ method, url });
  if (method === "POST") {
    const report = body as Record<string, unknown>;
    if (url.endsWith("/failure")) {
      if (
        !["succeeded", "failed", "cancelled"].includes(state.operation.status)
      ) {
        state.operation.status = "failed";
        state.operation.result = {
          error_code: "operation.native_outcome_unknown",
        };
      }
    } else {
      state.operation.status = report.status;
      state.operation.result = report.result ?? null;
    }
  }
  save(state);
  if (url.includes("/private-material?")) {
    const secret = Deno.env.get("INSTANCE_RUNTIME_SECRET")!;
    const installations: Record<string, unknown> = {};
    for (const member of state.operation.input.members) {
      installations[String(member.id)] =
        state.operation.kind.startsWith("schedule.")
          ? { command: `/usr/bin/printf ${secret}` }
          : {
            installation: {
              runtime: "docker",
              spec: member.input.installation.spec,
              definition: {
                image: "example:1",
                command: ["/usr/bin/sleep", "300"],
                restart: "no",
                working_directory: null,
                ports: [],
                mounts: [],
                environment: { PASSWORD: secret },
              },
            },
          };
    }
    return Promise.resolve({ status: 200, body: { data: { installations } } });
  }
  return Promise.resolve({ status: 200, body: { data: state.operation } });
}
export function invoke(
  name: string,
  input: Record<string, unknown>,
  _pin?: string,
  _signal?: AbortSignal,
): Promise<Record<string, unknown>> {
  const state = read();
  state.commands.push({
    name,
    id: input.process_id ?? input.id,
    ...(input.desired !== undefined ? { desired: input.desired } : {}),
  });
  save(state);
  return Promise.resolve(
    state.refuse && name !== "process.status"
      ? { error_code: "process.fixture_refused" }
      : name === "process.status"
      ? {
        state:
          state.commands.findLast((call: { id?: unknown; desired?: unknown }) =>
              call.id === input.process_id && call.desired !== undefined
            )?.desired === "stopped"
            ? "inactive"
            : "active",
      }
      : name === "vite.check-listener"
      ? { owned: true, ready: true }
      : {},
  );
}

export function caddy(_command: unknown): Promise<Record<string, unknown>> {
  const state = read();
  state.commands.push({ name: "caddy.publish" });
  save(state);
  return Promise.resolve(
    state.refuseCaddy
      ? { error_code: "caddy.publication_failed" }
      : { result: "published" },
  );
}
