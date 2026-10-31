/** Native scratch fixture: private keys come from test-only process environment, never files. */
let active = "7";
const values = () => ({
  anchor: Deno.env.get("MODEL_TEST_ANCHOR")!,
  vm71: Deno.env.get("MODEL_TEST_VM71")!,
  vm72: Deno.env.get("MODEL_TEST_VM72")!,
  other: Deno.env.get("MODEL_TEST_OTHER")!,
});
async function state() {
  return JSON.parse(await Deno.readTextFile("model-key-test.json"));
}
async function save(value: unknown) {
  await Deno.writeTextFile("model-key-test.json", JSON.stringify(value));
}
export async function fixtureHttp(method: string, path: string) {
  if (method !== "GET") throw new Error("Unexpected write to Gateway");
  const id = path.match(/direct-operations\/(\d+)/)?.[1];
  if (!id) throw new Error("Unknown parent");
  active = id;
  const value = await state();
  if (path.includes("/private-material")) {
    const keys = values();
    return {
      status: 200,
      body: {
        data: {
          model_key: id === "7" ? keys.vm71 : keys.vm72,
          model_router: {
            anchor_key: keys.anchor,
            management_key: Deno.env.get("MODEL_TEST_MANAGEMENT")!,
          },
        },
      },
    };
  }
  return { status: 200, body: { data: value.operations[id] } };
}
export function fixtureIdentity(args: { node_id: number; user: string }) {
  if (args.node_id !== 14 || args.user !== "orbit") {
    throw new Error("Wrong Gateway");
  }
  return Promise.resolve({
    ...args,
    hostname: "gateway",
    effective_user: "orbit",
    sudo_ready: true as const,
  });
}
export const fixtureRequest: typeof fetch = async (url, init = {}) => {
  const value = await state();
  const keys = values();
  const mapping = new Map(
    Object.entries(keys).map(([label, key]) => [key, label]),
  );
  const token = new Headers(init.headers).get("Authorization")?.slice(7);
  const path = new URL(String(url)).pathname;
  if (path === "/v0/management/api-keys") {
    if (token !== Deno.env.get("MODEL_TEST_MANAGEMENT")) {
      throw new Error("Wrong management");
    }
    if (!value.started[active]) {
      value.started[active] = true;
      value.events.push("start-" + active);
      await save(value);
      await new Promise((resolve) => setTimeout(resolve, 150));
    }
    if (init.method === "PUT") {
      value.members = JSON.parse(String(init.body)).map((key: string) =>
        mapping.get(key)
      );
    }
    if (init.method === "PATCH") {
      const body = JSON.parse(String(init.body));
      const label = mapping.get(body.old), next = mapping.get(body.new);
      const index = value.members.indexOf(label);
      if (index === -1) value.members.push(next);
      else value.members[index] = next;
    }
    await save(value);
    return Response.json({
      "api-keys": value.members.map((label: keyof typeof keys) => keys[label]),
    });
  }
  if (path !== "/v1/models") throw new Error("Unknown upstream endpoint");
  if (!token) {
    value.anonymous[active] = (value.anonymous[active] ?? 0) + 1;
    if (value.anonymous[active] === 2) value.events.push("end-" + active);
    await save(value);
  }
  return new Response("", {
    status: token && value.members.includes(mapping.get(token)) ? 200 : 401,
  });
};
