import { z } from "npm:zod@4.4.3";
const Args = z.object({
    hosts: z.string().optional(),
    sudo: z.boolean().optional(),
    direction: z.string().optional(),
    src: z.string().optional(),
    dst: z.string().optional(),
    recursive: z.boolean().optional(),
    argv: z.array(z.string()).optional(),
    stdout: z.string().optional(),
}).passthrough();
async function run(
    args: z.infer<typeof Args>,
    ctx: {
        writeResource(
            spec: string,
            name: string,
            value: unknown,
        ): Promise<unknown>;
    },
) {
    const path = Deno.cwd() + "/fixture.json";
    const state = JSON.parse(await Deno.readTextFile(path));
    const argv = args.argv ?? [];
    const index = argv.indexOf("/usr/bin/python3");
    const name = argv[0] === "/usr/bin/docker"
        ? "probe-" + argv[1]
        : index < 0
        ? argv.includes("start") ? "system-start" : "transport"
        : argv[index + 2];
    state.calls.push(name);
    let output: Record<string, unknown> = { ready: true };
    const inventory = {
        container_ids: ["original", "other"],
        probe: ["original"],
        unmanaged: ["original"],
        images: ["image"],
        tags: [],
        volumes: [],
        networks: [],
        process_ids: ["42"],
        healthy: true,
        plan: { recipe: "fixture" },
    };
    if (name === "inventory") output = inventory;
    if (name === "catalogue") output = inventory;
    if (name === "readiness") output = { ready: true, fallback: false };
    if (name === "fallback-config") state.fallback = true;
    if (name === "report") {
        output = {
            healthy: true,
            fallback: state.fallback === true,
            containers: [{ name: "original", state: "running", health: null }],
            process_ids: ["42"],
        };
    }
    if (name === "packages" && !state.packages) state.packages = 1;
    const lose = state.lose === name ||
        (name === "probe-start" && state.fail_probe);
    if (lose) state.lose = null;
    const temp = path + "." + crypto.randomUUID();
    await Deno.writeTextFile(temp, JSON.stringify(state));
    await Deno.rename(temp, path);
    if (lose) throw new Error("Fixture lost result after the action");
    const value = { stdout: JSON.stringify(output) };
    await ctx.writeResource("output", "output", value);
    return value;
}
export const behavior = {
    type: "@fixture/docker-transport",
    version: "2026.11.01.1",
    resources: {
        output: {
            schema: z.object({ stdout: z.string() }),
            lifetime: "1d",
            garbageCollection: 10,
        },
    },
    methods: {
        exec: { description: "Fake SSH", arguments: Args, execute: run },
        copy: { description: "Fake copy", arguments: Args, execute: run },
    },
};
