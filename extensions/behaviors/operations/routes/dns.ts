import { localCommand } from "../_lib/local-worker.ts";
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
  command: "/usr/bin/sudo",
  args: [
    "-n",
    "--",
    "/usr/bin/timeout",
    "--signal=TERM",
    "--kill-after=5s",
    "60s",
    "/usr/bin/python3",
    "-I",
    "-c",
    privateDnsProgram,
  ],
});

/** Fixed local code and data on stdin. No RemoteProgram, SSH or Gateway execution relay. */
export async function runPrivateDns(input: z.infer<typeof DnsPublication>) {
  const launch = dnsLaunch();
  const child = localCommand(launch.command, {
    args: launch.args,
    stdin: "piped",
    stdout: "piped",
    stderr: "null",
    signal: AbortSignal.timeout(70000),
  }).spawn();
  let finished = false;
  try {
    const writer = child.stdin.getWriter();
    await writer.write(
      new TextEncoder().encode(
        JSON.stringify({ records: input.records, catalog: input.catalog }),
      ),
    );
    await writer.close();
    let size = 0;
    const chunks: Uint8Array[] = [];
    for await (const chunk of child.stdout) {
      size += chunk.length;
      if (size > 4096) {
        throw new Error("Private DNS returned no bounded outcome");
      }
      chunks.push(chunk);
    }
    const status = await child.status;
    finished = true;
    if (!status.success) {
      throw new Error("Private DNS publication is uncertain");
    }
    const bytes = new Uint8Array(size);
    let offset = 0;
    for (const chunk of chunks) {
      bytes.set(chunk, offset);
      offset += chunk.length;
    }
    try {
      return JSON.parse(new TextDecoder().decode(bytes));
    } catch {
      throw new Error("Private DNS returned no typed outcome");
    }
  } finally {
    if (!finished) {
      try {
        child.kill("SIGTERM");
      } catch { /* Already stopped. */ }
      await child.status.catch(() => {});
    }
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
