import { z } from "npm:zod@4.4.3";
import {
    exporterRule,
    exporterSpecification,
} from "../integrations/metrics_exporter_spec.ts";

export const ExporterKind = z.enum(["node_exporter", "cadvisor"]);
export const ExporterObservation = z.object({
    configuration: z.string().max(1048576).nullable(),
    service_active: z.boolean(),
}).strict();
export const ExporterArgs = z.object({
    kind: ExporterKind,
    address: z.ipv4(),
    metrics_address: z.ipv4(),
    selected: z.boolean(),
    observation: ExporterObservation,
    ufw: z.string(),
}).strict();
const rule = (
    kind: z.infer<typeof ExporterKind>,
    address: string,
    source: string,
) => [
    "allow",
    "in",
    "on",
    "orbit",
    "proto",
    "tcp",
    "from",
    source,
    "to",
    address,
    "port",
    exporterSpecification(kind, address).port,
    "comment",
    exporterSpecification(kind, address).comment,
];
/** Ownership and firewall parsing remain canonical. No host action occurs here. */
export function exporterPlan(raw: unknown) {
    const args = ExporterArgs.parse(raw),
        specification = exporterSpecification(args.kind, args.address);
    const source = exporterRule(args.ufw, args.kind, args.address);
    const desiredSource = args.selected ? args.metrics_address : null;
    return {
        ...specification,
        before: { ...args.observation, firewall_source: source },
        after: {
            configuration: args.selected ? specification.configuration : null,
            service_active: args.selected ||
                args.observation.configuration === null &&
                    args.observation.service_active,
            firewall_source: desiredSource,
        },
        remove_rule: source !== null && source !== desiredSource
            ? rule(args.kind, args.address, source)
            : [],
        add_rule: desiredSource !== null && desiredSource !== source
            ? rule(args.kind, args.address, desiredSource)
            : [],
    };
}
