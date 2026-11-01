import { z } from "npm:zod@4.4.3";
import { WorkspaceInput, WorkspaceResult, workspaceResult } from "./input.ts";

const Args = z.object({ intent: WorkspaceInput }).strict();
const Public = z.object({ input_json: z.string(), checkout_path: z.string() });
type Context = {
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
/** Public validation and result shaping only; the native SSH steps own effects. */
export const behavior = {
    type: "@nckrtl/task-vm-workspace-input",
    version: "2026.11.01.1",
    resources: {
        input: { schema: Public, lifetime: "infinite", garbageCollection: 10 },
        workspace: {
            schema: WorkspaceResult,
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        read: {
            description:
                "Validate the captured workspace and serialize public intent",
            arguments: Args,
            async execute(raw: z.infer<typeof Args>, ctx: Context) {
                const { intent } = Args.parse(raw);
                const { ssh_execution: _ssh, ...receipt } = intent;
                return {
                    dataHandles: [
                        await ctx.writeResource("input", "input", {
                            input_json: JSON.stringify(receipt),
                            checkout_path: intent.checkout_path,
                        }),
                    ],
                };
            },
        },
        result: {
            description:
                "Expose the public directory receipt from official SSH",
            arguments: z.object({ observation: z.unknown() }).strict(),
            async execute(raw: { observation: unknown }, ctx: Context) {
                return {
                    dataHandles: [
                        await ctx.writeResource(
                            "workspace",
                            "workspace",
                            workspaceResult(raw.observation),
                        ),
                    ],
                };
            },
        },
    },
};
