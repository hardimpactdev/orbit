import { z } from "npm:zod@4.4.3";
import { installObserver } from "./observer_install_system.ts";
import { footprintLocalActions } from "./footprint_local_actions.ts";
import { localSystemCommand, type SystemTransport } from "./system_command.ts";
import { Agent, Path, Sha, type Snapshot } from "./converge_direct_contract.ts";
import { convergeNodeNetwork } from "./network_direct.ts";
import { DnsProgram } from "./converge_dns_program.ts";
import type { LocalDependencies } from "./converge_footprint.ts";

/** Bounded fixed local programs; stdin carries data and stderr is never persisted. */
async function output(
    command: string,
    args: string[],
    stdin?: unknown,
    deadline = 60000,
    invoke: SystemTransport = localSystemCommand,
    signal?: AbortSignal,
) {
    return await invoke({
        command,
        args,
        stdin: stdin === undefined ? undefined : JSON.stringify(stdin) + "\n",
        timeoutMs: deadline,
        maxOutputBytes: 65536,
    }, signal);
}

export async function localAccount(
    user: string,
    invoke: SystemTransport = localSystemCommand,
    signal?: AbortSignal,
) {
    z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/).parse(user);
    const passwd = await output(
        "/usr/bin/getent",
        ["passwd", user],
        undefined,
        60000,
        invoke,
        signal,
    );
    const row = passwd.stdout.trim().split(":");
    if (
        !passwd.success || row.length !== 7 || row[0] !== user ||
        !/^\d+$/.test(row[2]) || row[2] === "0" || !/^\d+$/.test(row[3])
    ) {
        throw new Error("The admitted Node account cannot be resolved");
    }
    const group = await output(
        "/usr/bin/getent",
        ["group", row[3]],
        undefined,
        60000,
        invoke,
        signal,
    );
    const fields = group.stdout.trim().split(":");
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
        response = await request(url, {
            redirect: "manual",
            signal: readSignal,
        });
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
    invoke: SystemTransport = localSystemCommand,
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
        invoke,
        signal,
    );
    if (!reply.success) {
        throw new Error("Private DNS publication outcome is uncertain");
    }
    return z.record(z.string(), z.unknown()).parse(
        JSON.parse(reply.stdout),
    );
}

export function convergeDependencies(
    scripts?: { php: string; footprint: string; preparation?: string },
): LocalDependencies {
    return {
        system: localSystemCommand,
        action: footprintLocalActions(localSystemCommand, scripts),
        install: installAgent,
        account: localAccount,
        dns: publishDns,
        network: convergeNodeNetwork,
    };
}
export const convergeLocalDependencies = convergeDependencies();
