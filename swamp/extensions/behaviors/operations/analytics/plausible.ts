import { z } from "npm:zod@4.4.3";
import { Domain } from "./host-intent.ts";

const Text = z.string().min(1).max(2000).regex(/^[^\r\n\0]+$/);
const Props = z.record(z.string().min(1), Text)
    .refine((props) => Object.keys(props).length <= 3);
const EventData = z.object({ event_name: Text, custom_props: Props.optional() })
    .strict();
const PageData = z.object({
    path: Text.startsWith("/"),
    custom_props: Props.optional(),
}).strict();
const RevenueData = EventData.extend({
    currency: z.string().regex(/^[A-Z]{3}$/),
});
export const Goal = z.discriminatedUnion("goal_type", [
    z.object({ goal_type: z.literal("Goal.CustomEvent"), goal: EventData })
        .strict(),
    z.object({ goal_type: z.literal("Goal.Pageview"), goal: PageData })
        .strict(),
    z.object({ goal_type: z.literal("Goal.Revenue"), goal: RevenueData })
        .strict(),
]);
export type GoalIntent = z.infer<typeof Goal>;
export const Site = z.object({
    domain: Domain,
    previous_domain: z.string().nullable().default(null),
    timezone: z.string().min(1).max(100),
    goals: z.array(Goal).max(100).default([]),
}).strict();
export type SiteIntent = z.input<typeof Site>;
