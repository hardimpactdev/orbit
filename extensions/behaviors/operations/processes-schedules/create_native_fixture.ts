import {
  caddy,
  http as baseHttp,
  invoke as baseInvoke,
} from "./start_native_fixture.ts";
export { caddy };
export function http(method: string, url: string, body?: unknown) {
  const reply = baseHttp(method, url, body);
  if (method === "GET" && url.includes("/private-material?")) {
    return Promise.resolve({
      status: 200,
      body: {
        data: {
          installation: {
            runtime: "systemd",
            content: `[Service]\nEnvironment=PASSWORD=${
              Deno.env.get("PROCESS_INSTALLATION_SECRET")
            }\n`,
          },
        },
      },
    });
  }
  return reply;
}
export function invoke(
  name: string,
  input: Record<string, unknown>,
  pin?: string,
  signal?: AbortSignal,
): Promise<Record<string, unknown>> {
  if (
    name === "process.stage-unit" || name === "process.activate-unit" ||
    name === "process.status"
  ) {
    const state = JSON.parse(Deno.readTextFileSync("start-native.json"));
    state.events.push(name);
    state.commands.push({ name });
    Deno.writeTextFileSync("start-native.json", JSON.stringify(state));
    return Promise.resolve(
      name === "process.stage-unit"
        ? { staged: true, previous: false, state: null }
        : name === "process.activate-unit"
        ? { backup_left: false }
        : {
          state: state.operation.input.installation.desired === "running"
            ? "active"
            : "inactive",
        },
    );
  }
  return baseInvoke(name, input, pin, signal);
}
