import { z } from "npm:zod@4.4.3";

/*
 * The proof reaper (F11). The Gateway publishes the open Project 62
 * (`swamp-proof`) groups as the schedule's inputs, with each running VM's
 * pinned SSH target. One official `@swamp/ssh exec` per VM reads its
 * heartbeat; `decide` below picks the groups to cancel. The Gateway's
 * scheduled-result projection cancels them with the normal cancel action.
 * Nothing here calls the Gateway.
 */

export const IDLE_SECONDS = 3600;
/** A group this old is cancelled unless its lane renewed it with a fresh heartbeat. */
export const AGE_CAP_SECONDS = 5400;
// `bin/swamp-proof` titles a group `swamp-proof <host>:<session> <topology>@<version>`.
const LANE = /^swamp-proof (\S+)/;

const Id = z.number().int().positive();
const Group = z.object({
    id: Id,
    title: z.string(),
    started_at: z.number().int().nonnegative().nullable(),
    running: z.boolean(),
});
const Target = z.object({ group_id: Id }).passthrough();
export type ProofGroup = z.infer<typeof Group>;

/** The lane of a group title, or null for a title without one. */
export function lane(title: string): string | null {
    return LANE.exec(title)?.[1] ?? null;
}
/** Heartbeat mtime in epoch seconds, `missing` when the file is absent, `unknown` when unreadable. */
export type Heartbeat = number | "missing" | "unknown";
export type Cancellation = { group_id: number; reason: string };

/** Cancel a group superseded by a newer open group of its lane, older than AGE_CAP_SECONDS without a fresh heartbeat, or idle past IDLE_SECONDS. */
export function decide(
    groups: ProofGroup[],
    heartbeats: Map<number, Heartbeat>,
    now: number,
): Cancellation[] {
    const newest = new Map<string, number>();
    for (const g of groups) {
        const name = lane(g.title);
        if (name !== null) {
            newest.set(name, Math.max(newest.get(name) ?? 0, g.id));
        }
    }
    const out: Cancellation[] = [];
    for (const g of [...groups].sort((a, b) => a.id - b.id)) {
        const name = lane(g.title);
        const latest = name === null ? g.id : newest.get(name)!;
        if (latest !== g.id) {
            out.push({
                group_id: g.id,
                reason: `superseded by group ${latest}`,
            });
            continue;
        }
        const beat = heartbeats.get(g.id) ?? "unknown";
        const age = g.started_at === null ? null : now - g.started_at;
        const renewed = typeof beat === "number" && now - beat <= IDLE_SECONDS;
        // Without a fresh heartbeat (no pinned key, unreadable, or never provisioned) only the age is known.
        if (!renewed && age !== null && age > AGE_CAP_SECONDS) {
            out.push({
                group_id: g.id,
                reason: `older than ${AGE_CAP_SECONDS} s without a renewal`,
            });
            continue;
        }
        if (!g.running) continue;
        if (typeof beat === "number" && now - beat > IDLE_SECONDS) {
            out.push({ group_id: g.id, reason: `idle for ${now - beat} s` });
        } else if (beat === "missing" && age !== null && age > IDLE_SECONDS) {
            out.push({ group_id: g.id, reason: `no heartbeat after ${age} s` });
        }
    }
    return out;
}

const Exec = z.object({
    exitCode: z.number().int().nullable().optional(),
    stdout: z.string().optional(),
}).passthrough();

/** `stat -c %Y` of one heartbeat step: exit 0 is the mtime, exit 1 a missing file, anything else unknown. */
export function heartbeat(
    steps: Record<string, unknown>,
    index: number,
): Heartbeat {
    const step = z.object({ outputs: Exec }).passthrough().safeParse(
        steps[`heartbeat-${index}`],
    );
    if (!step.success) return "unknown";
    const { exitCode, stdout } = step.data.outputs;
    if (exitCode === 1) return "missing";
    const value = (stdout ?? "").trim();
    return exitCode === 0 && /^[0-9]+$/.test(value) ? Number(value) : "unknown";
}

const Args = z.object({
    revision: Id,
    groups: z.array(Group),
    targets: z.array(Target),
    steps: z.record(z.string(), z.unknown()),
});
type Context = {
    writeResource(
        spec: string,
        name: string,
        value: Record<string, unknown>,
    ): Promise<{ name: string }>;
};

export function proofReaper(
    clock: () => number = () => Math.floor(Date.now() / 1000),
) {
    return {
        type: "@nckrtl/proof-reaper",
        version: "2026.11.02.2",
        resources: {
            decision: {
                description: "Proof groups the Gateway should cancel",
                schema: z.object({
                    revision: Id,
                    cancel: z.array(
                        z.object({ group_id: Id, reason: z.string() }),
                    ),
                }),
                lifetime: "7d",
                garbageCollection: 1,
            },
        },
        methods: {
            decide: {
                description:
                    "Choose idle or superseded proof groups from the published inputs and heartbeats",
                arguments: Args,
                async execute(args: z.infer<typeof Args>, ctx: Context) {
                    // targets[i] is the forEach item of step heartbeat-i.
                    const beats = new Map(
                        args.targets.map((
                            t,
                            i,
                        ) => [t.group_id, heartbeat(args.steps, i)]),
                    );
                    return {
                        dataHandles: [
                            await ctx.writeResource("decision", "decision", {
                                revision: args.revision,
                                cancel: decide(args.groups, beats, clock()),
                            }),
                        ],
                    };
                },
            },
        },
    };
}

export const behavior = {
    ...proofReaper(),
    type: "@nckrtl/proof-reaper",
};
