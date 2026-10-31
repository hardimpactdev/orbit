import { type NodeCommand, run } from "../_lib/node-commands.ts";
import { z } from "npm:zod@4.4.3";
import { privateDnsProgram } from "./dns-publication.ts";

export const DnsPublication = z.object({
  node_id: z.number().int().positive(),
  records: z.string().max(4194304),
  catalog: z.string().max(4194304),
  digest: z.string().regex(/^[a-f0-9]{64}$/),
});
const Code = z.string().regex(/^[a-z0-9_.-]{1,100}$/);
async function hash(text: string) {
  return Array.from(
    new Uint8Array(
      await crypto.subtle.digest("SHA-256", new TextEncoder().encode(text)),
    ),
    (b) => b.toString(16).padStart(2, "0"),
  ).join("");
}

export const dnsLaunch = () => ({
  command: "/usr/bin/timeout",
  args: [
    "--signal=TERM",
    "--kill-after=5s",
    "60s",
    "/usr/bin/python3",
    "-I",
    "-c",
    privateDnsProgram,
  ],
});

/** Fixed program and stdin data on the admitted Node, using its canonical worker transport. */
export async function runPrivateDns(
  input: z.infer<typeof DnsPublication>,
  command: NodeCommand = run,
) {
  const launch = dnsLaunch();
  let result: Awaited<ReturnType<NodeCommand>>;
  try {
    result = await command(
      [launch.command, ...launch.args],
      new TextEncoder().encode(
        JSON.stringify({ records: input.records, catalog: input.catalog }),
      ),
      "root",
      { deadlineMs: 70000, maxOutputBytes: 4096 },
    );
  } catch {
    throw new Error("Private DNS publication is uncertain");
  }
  if (result.stdout.length > 4096) {
    throw new Error("Private DNS returned no bounded outcome");
  }
  if (!result.success) throw new Error("Private DNS publication is uncertain");
  try {
    return JSON.parse(new TextDecoder().decode(result.stdout));
  } catch {
    throw new Error("Private DNS returned no typed outcome");
  }
}
export async function publishPrivateDns(raw: unknown, invoke = runPrivateDns) {
  const input = DnsPublication.parse(raw);
  JSON.parse(input.catalog);
  if (await hash(input.catalog) !== input.digest) {
    throw new Error("Private DNS catalog digest does not match");
  }
  const result = z.discriminatedUnion("outcome", [
    z.object({
      outcome: z.literal("published"),
      data: z.object({
        ready: z.literal(true),
        digest: z.string().regex(/^[a-f0-9]{64}$/),
      }),
    }),
    z.object({
      outcome: z.literal("refused"),
      data: z.object({ error_code: Code }),
    }),
  ]).parse(await invoke(input));
  if (result.outcome === "refused") {
    return { error_code: result.data.error_code };
  }
  if (result.data.digest !== input.digest) {
    throw new Error("Private DNS has no confirmed catalog");
  }
  return { ...result, ready: true };
}
