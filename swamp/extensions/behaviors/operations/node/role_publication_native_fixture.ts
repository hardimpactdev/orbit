import { z } from "npm:zod@4.4.3";
import { DirectArgs, Globals } from "../_lib/direct.ts";
const read = async () =>
  JSON.parse(await Deno.readTextFile("role-publication.json"));
const record = (value: unknown) =>
  Deno.writeTextFile("role-publication.ndjson", JSON.stringify(value) + "\n", {
    append: true,
  });
export async function fixtureHttp(method: string) {
  await record({ method });
  if (method !== "GET") throw new Error("Unexpected mutation in public reader");
  return { status: 200, body: { data: (await read()).operation } };
}
/** Native tests inject the fixed participant method boundary; no host effects. */
export function fixturePlacedPublication() {
  const arguments_ = DirectArgs.extend({
    target_node_id: z.number().int().positive(),
  });
  return {
    type: "@nckrtl/route-publication",
    version: "2026.10.31.1",
    globalArguments: Globals,
    methods: Object.fromEntries(
      ["publishPhp", "publishCaddy", "publishFirewall", "publishDns"].map(
        (method) => [method, {
          description: "Native fixture fixed participant boundary",
          arguments: arguments_,
          execute: async (args: z.infer<typeof arguments_>) => {
            await record({
              method,
              operation_id: args.operation_id,
              node_id: args.target_node_id,
            });
            if ((await read()).fail === method) {
              throw new Error("role_publication.fixture_failure");
            }
            return { ready: true };
          },
        }],
      ),
    ),
  };
}
