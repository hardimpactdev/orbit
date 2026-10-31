import { localCommand } from "../_lib/local-worker.ts";
import { certificateActions } from "../certificates/actions.ts";
import { certificateProgram } from "../certificates/system.ts";
import {
  convergeFirewall,
  convergeRouteLan,
} from "../routes/firewall-local.ts";
import { z } from "npm:zod@4.4.3";
import { installObserver } from "./observer_install_system.ts";
import { footprintLocalActions } from "./footprint_local_actions.ts";
import { localSystemCommand, type SystemTransport } from "./system_command.ts";
import {
  Agent,
  Code,
  Path,
  Sha,
  type Snapshot,
} from "./converge_direct_contract.ts";
import { convergeNodeNetwork } from "./network_direct.ts";
import { DnsProgram } from "./converge_dns_program.ts";
import type { LocalDependencies } from "./converge_footprint.ts";

/** Bounded fixed local programs; stdin carries data and stderr is never persisted. */
async function output(
  command: string,
  args: string[],
  stdin?: unknown,
  deadline = 60000,
) {
  const child = localCommand(command, {
    args,
    stdin: stdin === undefined ? "null" : "piped",
    stdout: "piped",
    stderr: "null",
    signal: AbortSignal.timeout(deadline),
  }).spawn();
  let finished = false;
  try {
    if (stdin !== undefined) {
      const writer = child.stdin.getWriter();
      await writer.write(
        new TextEncoder().encode(JSON.stringify(stdin) + "\n"),
      );
      await writer.close();
    }
    const chunks: Uint8Array[] = [];
    let size = 0;
    for await (const chunk of child.stdout) {
      size += chunk.length;
      if (size > 65536) {
        throw new Error("Local footprint output exceeded its bound");
      }
      chunks.push(chunk);
    }
    const status = await child.status;
    finished = true;
    const bytes = new Uint8Array(size);
    let offset = 0;
    for (const chunk of chunks) {
      bytes.set(chunk, offset);
      offset += chunk.length;
    }
    return { bytes, success: status.success };
  } finally {
    if (!finished) {
      try {
        child.kill("SIGTERM");
      } catch { /* Already exited. */ }
      await child.status.catch(() => {});
    }
  }
}

export async function localAccount(user: string) {
  z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/).parse(user);
  const passwd = await output("/usr/bin/getent", ["passwd", user]);
  const row = new TextDecoder().decode(passwd.bytes).trim().split(":");
  if (
    !passwd.success || row.length !== 7 || row[0] !== user ||
    !/^\d+$/.test(row[2]) || row[2] === "0" || !/^\d+$/.test(row[3])
  ) {
    throw new Error("The admitted Node account cannot be resolved");
  }
  const group = await output("/usr/bin/getent", ["group", row[3]]);
  const fields = new TextDecoder().decode(group.bytes).trim().split(":");
  if (!group.success || fields.length !== 4 || fields[2] !== row[3]) {
    throw new Error("The Node account group cannot be resolved");
  }
  return {
    home: Path.parse(row[5]),
    group: z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/).parse(fields[0]),
  };
}

/** Verify all delivery bytes before any privileged program can execute them. */
export async function downloadAgent(
  location: string,
  pin: string,
  signal?: AbortSignal,
  request: typeof fetch = fetch,
): Promise<Uint8Array> {
  Sha.parse(pin);
  const timeout = AbortSignal.timeout(120000);
  const readSignal = signal ? AbortSignal.any([signal, timeout]) : timeout;
  let url = new URL(location);
  let response: Response | undefined;
  for (let redirect = 0; redirect <= 5; redirect++) {
    if (url.protocol !== "https:" || url.username || url.password) {
      throw new Error("Agent delivery needs a credential-free HTTPS URL");
    }
    readSignal.throwIfAborted();
    response = await request(url, { redirect: "manual", signal: readSignal });
    if (![301, 302, 303, 307, 308].includes(response.status)) break;
    const next = response.headers.get("location");
    await response.body?.cancel();
    if (!next || redirect === 5) {
      throw new Error("Agent delivery redirects exceeded their bound");
    }
    url = new URL(next, url);
  }
  if (!response?.ok || !response.body) {
    throw new Error("Pinned agent delivery is unavailable");
  }
  let size = 0;
  const chunks: Uint8Array[] = [];
  for await (const chunk of response.body) {
    size += chunk.length;
    if (size > 128 * 1024 * 1024) {
      throw new Error("Agent delivery exceeded its bound");
    }
    chunks.push(chunk);
  }
  const bytes = new Uint8Array(size);
  let offset = 0;
  for (const chunk of chunks) {
    bytes.set(chunk, offset);
    offset += chunk.length;
  }
  const digest = Array.from(
    new Uint8Array(await crypto.subtle.digest("SHA-256", bytes)),
    (b) => b.toString(16).padStart(2, "0"),
  ).join("");
  if (digest !== pin) {
    throw new Error("Agent delivery differs from the admitted digest");
  }
  return bytes;
}

export function installAgent(
  snapshot: Snapshot,
  signal?: AbortSignal,
  invoke: SystemTransport = localSystemCommand,
) {
  const input = Agent.parse(snapshot.payloads.agent);
  return installObserver(input.url, snapshot.agent_sha256, invoke, signal);
}

export async function publishDns(
  input: Record<string, unknown>,
  signal?: AbortSignal,
) {
  const payload = z.object({
    records: z.string().max(4194304),
    catalog: z.string().max(4194304),
  }).strict().parse(input);
  signal?.throwIfAborted();
  const reply = await output(
    "/usr/bin/sudo",
    ["-n", "--", "/usr/bin/python3", "-c", DnsProgram],
    payload,
    65000,
  );
  if (!reply.success) {
    throw new Error("Private DNS publication outcome is uncertain");
  }
  return z.record(z.string(), z.unknown()).parse(
    JSON.parse(new TextDecoder().decode(reply.bytes)),
  );
}

async function cleanResidue(snapshot: Snapshot, signal?: AbortSignal) {
  const input = z.object({
    rows: z.array(
      z.object({
        id: z.number().int().positive(),
        route_id: z.number().int().positive(),
        domain: z.string(),
        steps: z.array(z.string()),
        attempts: z.number().int().nonnegative(),
      }).strict(),
    ).max(10000),
    caddy: z.record(z.string(), z.unknown()),
    user: z.string(),
    php: z.record(z.string(), z.string()).nullable(),
    certificates: z.array(
      z.string().regex(
        /^route-[1-9][0-9]*(?:-router|-router-hostname-change|-ingress)?$/,
      ),
    ),
    firewall: z.array(
      z.object({
        operation: z.literal("remove"),
        route_id: z.number().int().positive(),
      }).strict(),
    ),
    ingress: z.record(z.string(), z.unknown()).nullable(),
    dns: z.record(z.string(), z.unknown()).nullable(),
  }).strict().parse(snapshot.payloads["route-residue"]);
  if (input.rows.length === 0) return { changed: false };
  const native = footprintLocalActions();
  const program = async (
    source: string,
    value: Record<string, unknown>,
    signal?: AbortSignal,
  ) => {
    const result = await localSystemCommand({
      command: "/usr/bin/sudo",
      args: ["-n", "--", "/usr/bin/python3", "-I", "-c", source],
      stdin: JSON.stringify(value),
      timeoutMs: 300000,
    }, signal);
    if (!result.success) {
      throw new Error("Route residue command has no confirmed outcome");
    }
    try {
      return z.record(z.string(), z.unknown()).parse(JSON.parse(result.stdout));
    } catch {
      throw new Error("Route residue command returned no bounded outcome");
    }
  };
  const certificates = certificateActions((call, signal) =>
    program(certificateProgram, call, signal)
  );
  const call = (
    name: Parameters<typeof native>[0]["name"],
    input: Record<string, unknown>,
  ) => native({ name, input }, signal);
  if (input.rows.some((r) => r.steps.includes("caddy"))) {
    if (input.caddy.error_code) {
      return { error_code: Code.parse(input.caddy.error_code) };
    }
    const result = await call("caddy.publish", input.caddy);
    if ("error_code" in result) return result;
    z.object({ result: z.enum(["published", "unchanged"]) }).parse(result);
  }
  const account = input.php !== null || input.certificates.length
    ? await localAccount(input.user)
    : null;
  if (input.php !== null) {
    const configurations = Object.fromEntries(
      Object.entries(input.php).map((
        [version, text],
      ) => [
        version,
        text.replaceAll("__orbit_converge_group__", account!.group).replaceAll(
          "/__orbit_converge_home__",
          account!.home,
        ),
      ]),
    );
    const result = await call("php.converge-shared-pools", { configurations });
    if ("error_code" in result) return result;
    z.object({ changed: z.boolean() }).parse(result);
  }
  for (const scope of input.certificates) {
    const result = await certificates.remove({
      node_id: snapshot.node_id,
      agent_sha256: snapshot.agent_sha256,
      scope,
      home: account!.home,
      in_use: false,
    }, signal);
    if ("error_code" in result) return result;
  }
  for (const rule of input.firewall) {
    const result = await convergeRouteLan(rule, program, signal);
    if ("error_code" in result) return result;
  }
  if (input.ingress !== null) {
    const result = await convergeFirewall(input.ingress, program, signal);
    if ("error_code" in result) return result;
  }
  if (input.dns !== null) {
    const result = await publishDns(input.dns, signal);
    if ("error_code" in result) return result;
    z.object({ ready: z.literal(true) }).parse(result);
  }
  return { changed: true };
}

export const convergeLocalDependencies: LocalDependencies = {
  system: localSystemCommand,
  action: footprintLocalActions(),
  install: installAgent,
  account: localAccount,
  dns: publishDns,
  residue: cleanResidue,
  network: convergeNodeNetwork,
};
