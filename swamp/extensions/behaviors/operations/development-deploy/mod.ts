import { z } from "npm:zod@4.4.3";

type Context = {
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
const Entries = z.string().max(8 * 1024 * 1024);

/**
 * The cleanup before a development checkout: untracked or ignored entries inside a directory that the target
 * commit does not have, such as a removed package's node_modules. Everything else stays. Each action is one argv
 * under the checkout's lock; a directory is made writable first, so read-only caches can go.
 */
export function cleanup(
    home: string,
    untracked: string,
    directories: string,
): string[][] {
    const kept = new Set(
        directories.split("\0").filter((entry) => entry !== ""),
    );
    const lock = [
        "/usr/bin/flock",
        "--nonblock",
        "--conflict-exit-code",
        "73",
        home,
    ];
    const actions: string[][] = [];
    for (const raw of untracked.split("\0")) {
        const entry = raw.replace(/\/$/, "");
        if (entry === "" || !entry.includes("/")) continue;
        if (entry.startsWith("/") || entry.split("/").includes("..")) {
            throw new Error("deployment.identity_changed");
        }
        if (kept.has(entry.slice(0, entry.lastIndexOf("/")))) continue;
        const path = home + "/" + entry;
        actions.push(
            ...(raw.endsWith("/")
                ? [[...lock, "/usr/bin/chmod", "-R", "u+w", "--", path], [
                    ...lock,
                    "/usr/bin/rm",
                    "-rf",
                    "--",
                    path,
                ]]
                : [[...lock, "/usr/bin/rm", "-f", "--", path]]),
        );
    }
    return actions;
}

export const behavior = {
    type: "@nckrtl/development-deploy",
    version: "2026.11.02.1",
    resources: {
        cleanup: {
            schema: z.object({ actions: z.array(z.array(z.string())) }),
            lifetime: "workflow",
            garbageCollection: 1,
        },
    },
    methods: {
        cleanup: {
            description:
                "Plan the removal of untracked entries in directories the target commit lacks",
            arguments: z.object({
                home: z.string().regex(/^\/[^\0]+$/),
                untracked: Entries,
                directories: Entries,
            }),
            execute: async (
                args: { home: string; untracked: string; directories: string },
                ctx: Context,
            ) => {
                const value = {
                    actions: cleanup(
                        args.home,
                        args.untracked,
                        args.directories,
                    ),
                };
                await ctx.writeResource("cleanup", "cleanup", value);
                return value;
            },
        },
    },
};
