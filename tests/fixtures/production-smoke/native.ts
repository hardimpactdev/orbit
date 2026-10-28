import { productionSmoke } from "../../../extensions/behaviors/production-smoke/mod.ts";
import { observation, operation, passed } from "./fixture.ts";
export const behavior = {
  ...productionSmoke((ctx) => async (method, path, body, key) => {
    const root = (ctx as unknown as { repoDir: string }).repoDir;
    if (path.endsWith("/context")) {
      return { status: 200, body: { data: observation } };
    }
    const file = `${root}/dispatch.json`;
    if (method === "POST") {
      let refuse = false;
      try {
        await Deno.stat(`${root}/refuse-first`);
        refuse = true;
      } catch { /* ordinary admission */ }
      if (refuse) {
        await Deno.remove(`${root}/refuse-first`);
        await Deno.writeTextFile(
          `${root}/refused.json`,
          JSON.stringify({ input: body, key }),
        );
        return {
          status: 409,
          body: {
            error: {
              code: "operation.target_busy",
              message: "Older smoke holder has not yielded.",
            },
          },
        };
      }
      let count = 0;
      try {
        count = JSON.parse(await Deno.readTextFile(file)).count;
      } catch { /* fixture first dispatch */ }
      await Deno.writeTextFile(
        file,
        JSON.stringify({ count: count + 1, input: body, key }),
      );
      return { status: 202, body: { data: operation(body) } };
    }
    let stored;
    try {
      stored = JSON.parse(await Deno.readTextFile(file));
    } catch (error) {
      if (!(error instanceof Deno.errors.NotFound)) throw error;
      return {
        status: 200,
        body: {
          data: {
            request_key: "smoke.7.9",
            found: false,
            operation: null,
            smoke: null,
          },
        },
      };
    }
    let ready = false;
    try {
      await Deno.stat(`${root}/ready-result`);
      ready = true;
    } catch { /* fixture pending */ }
    return {
      status: 200,
      body: {
        data: {
          request_key: "smoke.7.9",
          found: true,
          operation: operation(stored.input),
          smoke: ready ? passed(stored.input) : null,
        },
      },
    };
  }),
  type: "@fixture/production-smoke",
};
