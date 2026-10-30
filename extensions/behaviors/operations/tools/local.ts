import { z } from "npm:zod@4.4.3";
import type { ToolIntentInput } from "./direct-contract.ts";

const ReadCommand = z.enum(["tool.apt-version", "tool.apt-inventory"]);
const Reply = z.discriminatedUnion("ok", [
  z.object({ ok: z.literal(true), data: z.record(z.string(), z.unknown()) }),
  z.object({
    ok: z.literal(false),
    error: z.object({ code: z.string().regex(/^[a-z0-9_.-]{1,100}$/) }),
  }),
]);
type Invoke = (
  name: z.infer<typeof ReadCommand>,
  pin: string,
  signal?: AbortSignal,
) => Promise<unknown>;

/** Existing Account reads need no privileged transport. Mutations remain gated. */
export function aptReader(invoke: Invoke = readAgent) {
  return async (
    name: string,
    input: Record<string, unknown>,
    node: z.infer<typeof ToolIntentInput>,
    signal?: AbortSignal,
  ): Promise<Record<string, unknown>> => {
    const command = ReadCommand.safeParse(name);
    if (
      !command.success || node.platform !== "linux" || node.manager !== "apt" ||
      Object.keys(input).length !== 0
    ) {
      throw new Error(
        "Tools requires the approved local Node privilege boundary",
      );
    }
    const reply = Reply.parse(
      await invoke(command.data, node.agent_sha256, signal),
    );
    return reply.ok ? reply.data : { error_code: reply.error.code };
  };
}

async function readAgent(
  name: z.infer<typeof ReadCommand>,
  pin: string,
  signal?: AbortSignal,
): Promise<unknown> {
  const binary = "/usr/local/bin/orbit-agent";
  const file = await Deno.lstat(binary);
  if (
    !file.isFile || file.isSymlink || file.uid !== 0 || file.mode === null ||
    (file.mode & 0o022) !== 0
  ) {
    throw new Error("The local agent binary is not root owned");
  }
  const child = new Deno.Command(binary, {
    args: ["command", name],
    stdin: "piped",
    stdout: "piped",
    stderr: "null",
    signal,
  }).spawn();
  const writer = child.stdin.getWriter();
  // The existing APT Account read commands declare a native 900-second budget.
  await writer.write(
    new TextEncoder().encode(
      JSON.stringify({ agent_sha256: pin, deadline_ms: 900000, input: {} }) +
        "\n",
    ),
  );
  await writer.close();
  const output = await child.output();
  if (!output.success || output.stdout.length > 4 * 1024 * 1024) {
    throw new Error("The local Tool read did not return a bounded result");
  }
  return JSON.parse(new TextDecoder().decode(output.stdout));
}

export const localTool = aptReader();
