import { z } from "npm:zod@4.4.3";
const Args = z.object({
    hosts: z.string().optional(),
    timeoutSec: z.number().optional(),
    sudo: z.boolean().optional(),
    interpreter: z.string().optional(),
    argv: z.array(z.string()).optional(),
    script: z.string().optional(),
    stdin: z.string().optional(),
}).passthrough();
async function execute(
    a: z.infer<typeof Args>,
    ctx: { writeResource(s: string, n: string, v: unknown): Promise<unknown> },
) {
    const state = JSON.parse(Deno.readTextFileSync("fixture.json"));
    const command = a.argv?.[0] === "/usr/local/bin/orbit-agent"
        ? a.argv[2]
        : a.argv?.includes("recipe-validate")
        ? "caddy-validation"
        : (/sys\.argv=\["recipe","([^"]+)"\]/.exec(
            a.script ?? a.argv?.at(-1) ?? "",
        )?.[1] ?? a.argv?.join(" ") ?? "script");
    const envelope = a.stdin?.startsWith("{") ? JSON.parse(a.stdin) : null;
    Deno.writeTextFileSync(
        "calls.jsonl",
        JSON.stringify({
            command,
            argv: a.argv,
            envelope,
            timeoutSec: a.timeoutSec,
        }) + "\n",
        { append: true, create: true },
    );
    let data: Record<string, unknown> = { ready: true, changed: false };
    if (command === "account.lookup") {
        data = {
            user: "orbit",
            uid: 1000,
            group: "orbit",
            home: "/home/orbit",
            shell: "/bin/zsh",
        };
    } else if (command === "agent.install") {
        data = {
            replaced: true,
            sha256: state.pin,
            previous_sha256: state.pin,
            observer: "active",
        };
    } else if (command === "agent.configure") {
        if (envelope.input.allow_secret_rotation) {
            const secret = Array.from(
                crypto.getRandomValues(new Uint8Array(32)),
                (x) => x.toString(16).padStart(2, "0"),
            ).join("");
            Deno.writeTextFileSync(state.host + "/secret", secret, {
                mode: 0o600,
            });
        }
        const hash = Array.from(
            new Uint8Array(
                await crypto.subtle.digest(
                    "SHA-256",
                    new TextEncoder().encode(
                        Deno.readTextFileSync(state.host + "/secret"),
                    ),
                ),
            ),
            (x) => x.toString(16).padStart(2, "0"),
        ).join("");
        data = { secret_hash: hash, restarted: true, unclosed: [] };
    } else if (command === "node.prepare-storage-root") data = {};
    else if (command === "caddy.install-package") {
        data = { changed: false, version: "2.10.2" };
    } else if (command === "node.install-private-dns-listener") {
        data = { installed: true, changed: false };
    } else if (command === "node.private-dns-validate") {
        data = {
            ready: true,
            before: { records: null, catalog: null, commands: [] },
        };
    } else if (command === "caddy-validation") data = { valid: true };
    if (
        state.fail === command &&
        !await Deno.stat("failed-once").then(() => true, () => false)
    ) {
        Deno.writeTextFileSync("failed-once", "used", { createNew: true });
        throw new Error("Synthetic lost command reply");
    }
    if (state.refuse === command) {
        const out = {
            stdout: JSON.stringify({
                ok: false,
                error: { code: "node.storage_root_unusable" },
            }),
        };
        await ctx.writeResource("output", "output", out);
        return out;
    }
    let stdout = JSON.stringify(
        command.includes(".") && !command.startsWith("node.private-dns-") &&
            a.argv?.[0] === "/usr/local/bin/orbit-agent"
            ? { ok: true, data }
            : data,
    );
    if (a.argv?.[0] === "/usr/bin/mktemp") {
        stdout = "/var/tmp/orbit-agent-ABCDEFGH\n";
    }
    if (a.argv?.[0] === "/usr/bin/sha256sum") {
        stdout = state.pin + "  /usr/local/bin/orbit-agent\n";
    }
    const out = { stdout };
    await ctx.writeResource("output", "output", out);
    return out;
}
export const behavior = {
    type: "@fixture/node-agent-ssh",
    version: "2026.11.01.1",
    resources: {
        output: {
            schema: z.object({ stdout: z.string() }),
            lifetime: "1d",
            garbageCollection: 10,
        },
    },
    methods: {
        exec: {
            description: "Offline official SSH seam",
            arguments: Args,
            execute,
        },
        script: {
            description: "Offline script seam",
            arguments: Args,
            execute,
        },
    },
};
