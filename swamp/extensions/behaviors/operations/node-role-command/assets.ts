import { z } from "npm:zod@4.4.3";
const User = z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/);
const Account = z.object({
    user: User,
    group: User,
    home: z.string().startsWith("/"),
}).strict();
const Args = z.object({ passwd: z.string(), group: z.string() }).strict();

/** One `getent passwd <user>` line and one `id -gn <user>` line, read over official SSH. */
export function account(args: z.infer<typeof Args>): z.infer<typeof Account> {
    const fields = args.passwd.trim().split(":");
    if (fields.length !== 7) throw new Error("account.passwd_invalid");
    return Account.parse({
        user: fields[0],
        group: args.group.trim(),
        home: fields[5],
    });
}

export const behavior = {
    type: "@nckrtl/node-role-command",
    version: "2026.11.02.1",
    resources: {
        result: { schema: Account, lifetime: "1d", garbageCollection: 10 },
    },
    methods: {
        account: {
            description:
                "Decode the enrolled account from its passwd and group lines",
            arguments: Args,
            execute: async (
                args: z.infer<typeof Args>,
                ctx: {
                    writeResource(
                        spec: string,
                        name: string,
                        value: unknown,
                    ): Promise<unknown>;
                },
            ) => {
                const result = account(Args.parse(args));
                await ctx.writeResource("result", "result", result);
                return result;
            },
        },
    },
};
