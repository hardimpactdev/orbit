import { z } from "npm:zod@4.4.3";
import { DestroyInput, Name } from "../database/destroy_input.ts";

export const behavior = {
    type: "@nckrtl/database-destroy-plan",
    version: "2026.11.01.1",
    resources: {
        plan: {
            schema: z.object({
                databases: z.array(Name),
                users: z.array(z.string()),
                json: z.string(),
            }),
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        read: {
            description:
                "Resolve only admitted exact and literal-prefix database names",
            arguments: z.object({
                intent: z.record(z.string(), z.unknown()),
                inventory: z.string().max(1024 * 1024),
            }).strict(),
            execute: async (
                args: { intent: Record<string, unknown>; inventory: string },
                ctx: {
                    writeResource(
                        spec: string,
                        name: string,
                        value: unknown,
                    ): Promise<unknown>;
                },
            ) => {
                const { ssh: _ssh, ...raw } = args.intent;
                const i = DestroyInput.parse(raw);
                const live = z.object({ databases: z.array(Name).max(10000) })
                    .strict().parse(JSON.parse(args.inventory));
                const databases = [
                    ...new Set([...i.databases, ...live.databases]),
                ];
                const owners: Record<string, string> = {};
                for (const name of databases) {
                    if (
                        !i.databases.includes(name) &&
                        (i.driver !== "mysql" ||
                            !i.test_prefixes.some((p) => name.startsWith(p)))
                    ) throw new Error("database.ownership_unproven");
                    const owner = i.owners[name] ??
                        i.owners[
                            i.test_prefixes.find((p) => name.startsWith(p)) ??
                                ""
                        ];
                    if (
                        !owner || !/^[A-Za-z0-9_]{1,63}$/.test(owner) ||
                        ["root", "postgres"].includes(owner)
                    ) throw new Error("database.ownership_unproven");
                    owners[name] = owner;
                }
                const users = [
                    ...new Set(i.users.filter((n) => n !== "default")),
                ];
                const value = {
                    databases,
                    users,
                    json: JSON.stringify({ databases, users, owners }),
                };
                await ctx.writeResource("plan", "plan", value);
                return value;
            },
        },
    },
};
