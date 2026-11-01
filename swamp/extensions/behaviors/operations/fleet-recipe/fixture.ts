import { z } from "npm:zod@4.4.3";
const Args = z.object({
    hosts: z.string().optional(),
    sudo: z.boolean().optional(),
    interpreter: z.string().optional(),
    argv: z.array(z.string()).optional(),
    script: z.string().optional(),
    stdin: z.string().optional(),
}).passthrough();
const Output = z.object({ stdout: z.string() });
const execute = async (
    args: z.infer<typeof Args>,
    ctx: { writeResource(s: string, n: string, v: unknown): Promise<unknown> },
) => {
    const state = JSON.parse(await Deno.readTextFile("fixture.json"));
    const source = args.script ?? args.argv?.at(-1) ?? "";
    const action = /sys\.argv=\["recipe","([^"]+)"\]/.exec(source)?.[1];
    const command = action ??
        (args.argv?.includes("recipe-validate")
            ? "caddy-validation"
            : source.includes("Install the admitted Caddy")
            ? "caddy-package"
            : source.includes("def account(data):")
            ? "account"
            : source.includes("orbit-agent") && source.includes("sha256")
            ? "observer-install"
            : source.includes("SECRET = Path")
            ? "observer-identity"
            : args.script
            ? "file"
            : (args.argv?.join(" ") ?? "exec"));
    Deno.writeTextFileSync("calls.jsonl", JSON.stringify(command)+"\n", {append:true,create:true});
    if (state.fail === command && !await Deno.stat("fail-used").then(()=>true,()=>false)) {
        Deno.writeTextFileSync("fail-used", "used", {createNew:true});
        throw new Error("Synthetic uncertain effect");
    }
    let result: unknown = { ready: true, changed: false };
    if (command === "node.private-dns-validate") result = {ready: !state.dnsValidationInvalid, before:{commands:[["/usr/bin/systemctl","stop","orbit-private-dns.service"],["/usr/bin/systemctl","disable","orbit-private-dns.socket"]],records:null,catalog:null}, ...(state.dnsValidationInvalid ? {error_code:"node.private_dns_invalid"} : {})};
    else if (command === "node.private-dns-health") result = {ready: !state.dnsInvalid, ...(state.dnsInvalid ? {error_code:"node.private_dns_failed"} : {})};
    else if (command === "caddy-validation") result = { valid: !state.caddyInvalid };
    else if (command === "caddy-package") result = { caddy_installed: true };
    else if (command === "inspect") {
        result = {
            curl: {
                installed: "1",
                version: "2",
                changed: true,
                status: "outdated",
            },
        };
    } else if (command === "packages") {
        result = {
            changed_packages: ["curl"],
            changed_tools: ["curl"],
            restart_units: state.noServices ? [] : ["curl.service"],
            health_units: state.noServices ? [] : ["curl.service"],
            newer_than_recipe: {},
            healthy: true,
        };
    } else if (command === "services") {
        result = {
            restart_units: state.noServices ? [] : ["curl.service"],
            health_units: state.noServices ? [] : ["curl.service"],
        };
    } else if (command === "verify") {
        result = { ready: true, newer_than_recipe: {} };
    } else if (command === "delivery") {
        result = {
            identity: "sha256:" + "b".repeat(64),
            agent_sha256: "a".repeat(64),
            cli_version: "0.12.0",
        };
    } else if (command === "account") {
        result = { user: "orbit", group: "orbit", home: "/home/orbit" };
    } else if (command === "observer-install") {
        result = { installed: true, changed: false, sha256: "a".repeat(64) };
    } else if (source.includes("def install(data)")) {
        result = { installed: null, configured: false };
    }
    const data = { stdout: JSON.stringify(result) };
    await ctx.writeResource("output", "output", data);
    return data;
};
export const behavior = {
    type: "@fixture/recipe-ssh",
    version: "2026.11.01.1",
    resources: {
        output: { schema: Output, lifetime: "1d", garbageCollection: 10 },
    },
    methods: {
        exec: { description: "Fake official SSH", arguments: Args, execute },
        script: {
            description: "Fake official SSH script",
            arguments: Args,
            execute,
        },
    },
};
