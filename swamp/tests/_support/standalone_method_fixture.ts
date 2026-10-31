import { assert, assertEquals } from "jsr:@std/assert@1.0.19";

/** Fake only the standalone process boundary; the target stage and driver remain real. */
export function standaloneMethodFixture(
  run: (args: Record<string, unknown>) => Promise<unknown>,
) {
  const command = class {
    constructor(_program: string, options: Deno.CommandOptions) {
      assertEquals(options.args?.slice(0, 5), [
        "model",
        "method",
        "run",
        "orbit-gateway",
        "detect",
      ]);
      assert(options.args?.includes("--literal-inputs"));
    }
    spawn() {
      let input = "", result: Promise<unknown> | undefined;
      return {
        stdin: new WritableStream<Uint8Array>({
          write(chunk) {
            input += new TextDecoder().decode(chunk);
          },
          close() {
            result = run(JSON.parse(input));
          },
        }),
        output: async () => {
          await result;
          return {
            success: true,
            stdout: new Uint8Array(),
            stderr: new Uint8Array(),
          };
        },
      };
    }
  };
  const original = Deno.Command;
  Deno.Command = command as unknown as typeof Deno.Command;
  return {
    restore: () => {
      Deno.Command = original;
    },
  };
}
