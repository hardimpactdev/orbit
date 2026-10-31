import { z } from "npm:zod@4.4.3";
const Path = z.string().regex(/^\/(?:[^/\0\r\n]+\/)*[^/\0\r\n]+$/).refine(
    (path) => path.split("/").every((part) => part !== "." && part !== ".."),
);
const User = z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/);
const Args = z.object({
    storage: z.object({
        root: Path.nullable(),
        user: User,
        create_missing: z.boolean(),
        protected_roots: z.array(Path),
        managed_checkouts: z.array(Path),
    }).strict(),
    account: z.object({ user: User, group: User, home: Path }),
}).strict();
const Prepared = z.object({
    root: Path,
    user: User,
    group: User,
    create_missing: z.boolean(),
}).strict();
export function enrollmentStorage(raw: unknown) {
    const { storage, account } = Args.parse(raw);
    const inside = (path: string, parent: string) =>
        path.startsWith(parent + "/");
    const root = storage.root ?? account.home + "/apps";
    if (
        account.user !== storage.user || root === account.home ||
        inside(account.home, root) ||
        (inside(root, account.home) &&
            root.slice(account.home.length + 1).split("/")[0].startsWith(
                ".",
            )) ||
        storage.protected_roots.some((path) =>
            root === path || inside(root, path) || inside(path, root)
        ) ||
        storage.managed_checkouts.some((path) =>
            root === path || inside(root, path)
        )
    ) {
        throw new Error("node.settings_path_protected");
    }
    return Prepared.parse({
        root,
        user: account.user,
        group: account.group,
        create_missing: storage.create_missing,
    });
}
export const behavior = {
    type: "@nckrtl/node-enrollment-settings",
    version: "2026.10.31.1",
    resources: {
        prepared: { schema: Prepared, lifetime: "1d", garbageCollection: 10 },
    },
    methods: {
        read: {
            description:
                "Bind admitted storage policy to the observed managed account",
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
                const result = enrollmentStorage(args);
                await ctx.writeResource("prepared", "prepared", result);
                return result;
            },
        },
    },
};
