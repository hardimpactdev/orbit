import { z } from "npm:zod@4.4.3";
const Name = z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/);
const Path = z.string().min(1).max(4096).startsWith("/").refine((v) =>
    !/[\0\r\n]/.test(v) && !v.split("/").includes("..")
);
const Text = z.string().max(1048576).refine((v) => !v.includes("\0"));
export const RuntimeInput = z.object({
    user: Name,
    version: z.string().regex(/^[0-9]+\.[0-9]+$/),
    marker: z.string().min(1).max(256),
    mode: z.literal("monitor"),
    home: Path,
    application: Path,
    initial_application: Path,
    configuration: z.object({
        main: Text,
        pool: Text,
        initial_pool: Text,
        local_defaults: Text,
        master_ini: Text,
        unit: Text,
    }).strict(),
}).strict();
const Args = z.object({
    runtime: RuntimeInput,
    observation: z.string(),
}).strict();
const Observation = z.object({ present: z.boolean(), initial: z.boolean() })
    .strict();
const Input = z.object({
    present: z.boolean(),
    pool: z.string(),
    path: z.string(),
    main: z.string(),
    service: z.string(),
    version: z.string(),
    scan_dir: z.string(),
});
export function prepareMonitorInput(raw: unknown) {
    const { runtime, observation } = Args.parse(raw);
    const observed = Observation.parse(JSON.parse(observation));
    const directory = `/etc/orbit/php-fpm/${runtime.user}/generated`;
    return {
        present: observed.present,
        pool: observed.initial
            ? runtime.configuration.initial_pool
            : runtime.configuration.pool,
        path: `${directory}/pool.conf`,
        main: `${directory}/php-fpm.conf`,
        service: `orbit-${runtime.user}-php${runtime.version}-fpm.service`,
        version: runtime.version,
        scan_dir: `/etc/php/${runtime.version}/fpm/conf.d:${directory}`,
    };
}
export const behavior = {
    type: "@nckrtl/php-monitor-command",
    version: "2026.10.31.2",
    resources: {
        input: { schema: Input, lifetime: "infinite", garbageCollection: 10 },
    },
    methods: {
        prepare: {
            description:
                "Select the captured pool for the existing production runtime",
            arguments: Args,
            execute: async (args: unknown, ctx: {
                writeResource: (
                    spec: string,
                    name: string,
                    value: unknown,
                ) => Promise<unknown>;
            }) => ({
                dataHandles: [
                    await ctx.writeResource(
                        "input",
                        "input",
                        prepareMonitorInput(args),
                    ),
                ],
            }),
        },
    },
};
