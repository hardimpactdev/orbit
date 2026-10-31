import { z } from "npm:zod@4.4.3";
import {
    metricsServiceSpecification,
    serviceRule,
    serviceRules,
    validateServiceState,
} from "../integrations/metrics_service_spec.ts";
import { sameState } from "../integrations/metrics_policy.ts";
export const ServiceState = z.object({
    config: z.string().nullable(),
    unit: z.string().nullable(),
    rules: z.array(z.array(z.string())).max(4),
    active: z.boolean(),
    enabled: z.boolean(),
    binary: z.boolean(),
}).strict();
export const ServiceObservation = ServiceState.omit({ rules: true }).extend({
    directory_present: z.boolean(),
}).strict();
export const ServicePlanArgs = z.object({
    desired: ServiceState,
    observation: ServiceObservation,
    ufw_status: z.string(),
    ufw_added: z.string(),
}).strict();
/** Canonical service ownership, validation and rule ordering without an execution dependency. */
export function servicePlan(raw: unknown) {
    const args = ServicePlanArgs.parse(raw);
    if (!/^Status: active$/m.test(args.ufw_status)) {
        throw new Error("metrics.service_invalid");
    }
    const { directory_present, ...state } = args.observation;
    const before = { ...state, rules: serviceRules(args.ufw_added) },
        after = args.desired;
    validateServiceState(before);
    validateServiceState(after);
    const leading = args.ufw_added.split("\n").filter((s) =>
        s.startsWith("ufw ")
    ).slice(0, after.rules.length).map((s) =>
        s.split(/\s+/).at(-1)!.replace(/^['"]|['"]$/g, "")
    );
    const same = sameState(before.rules, after.rules) &&
        sameState(leading, after.rules.map((r) => r.at(-1)));
    return {
        before,
        after,
        directory_present,
        remove_rules: same ? [] : before.rules.toReversed(),
        add_rules: same ? [] : after.rules.map(serviceRule).toReversed(),
    };
}
export const serviceSpecification = metricsServiceSpecification();
