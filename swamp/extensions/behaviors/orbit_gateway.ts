import { refreshMethod, scanResources } from "./dependency_scanner.ts";
import {
    pruneDependencyResults,
    type RetentionContext,
} from "./_lib/dependency_retention.ts";
import {
    FindingsSchema,
    ScheduleReceiptSchema,
} from "./_lib/project_schedule.ts";
import {
    DependencyProjectSchema,
    DependencyProjectsSchema,
} from "./_lib/dependency_project_schema.ts";
import { IndexSchema, ReportSchema } from "./_lib/dependency_results_schema.ts";
import { z } from "npm:zod@4";

const GlobalArgsSchema = z.object({
    dependencyProjects: DependencyProjectsSchema.default([]),
    enrollmentVault: z.string().min(1).default("default"),
    workerUrl: z.string().default("wss://swamp.orbit"),
    serveUrl: z.string().default("wss://swamp.orbit"),
    serveTokenFile: z.string().default(
        "/home/orbit/.config/swamp/serve-driver-token",
    ),
    gatewayUrl: z.string().default("https://gateway.orbit"),
    caFile: z.string().default(
        "/usr/local/share/ca-certificates/orbit-managed-root-ca.crt",
    ),
    timeoutSeconds: z.number().int().default(60),
});

export const behavior = {
    type: "@nckrtl/orbit-gateway",
    version: "2026.11.02.2",
    globalArguments: GlobalArgsSchema,
    resources: {
        ...scanResources,
        dependencyRefreshInput: {
            description: "Bounded credential-free PR refresh configuration",
            schema: z.object({
                runId: z.string(),
                rows: z.array(
                    z.object({
                        project: DependencyProjectSchema,
                        index: IndexSchema,
                    }),
                ),
                http: z.object({
                    gatewayUrl: z.string(),
                    caFile: z.string(),
                    timeoutSeconds: z.number(),
                }),
            }),
            lifetime: "infinite",
            garbageCollection: 2,
        },
        dependencyScanInput: {
            description: "Scan configuration handoff",
            schema: z.object({
                runId: z.string(),
                project: DependencyProjectsSchema.element,
                http: z.object({
                    gatewayUrl: z.string(),
                    caFile: z.string(),
                    timeoutSeconds: z.number(),
                }),
            }),
            lifetime: "infinite",
            garbageCollection: 1,
        },
        // Index history is seven days; reports are pruned only by retained-index reachability.
        dependencyIndex: {
            description: "orbit.dependencies.v1 Project/ref publication",
            schema: IndexSchema,
            lifetime: "infinite",
            garbageCollection: Number.MAX_SAFE_INTEGER,
        },
        dependencyReport: {
            description: "Immutable orbit.dependencies.v1 source report",
            schema: ReportSchema,
            lifetime: "infinite",
            garbageCollection: Number.MAX_SAFE_INTEGER,
        },
        schedule: {
            description:
                "Project schedule and admitted batch group-creation receipts",
            schema: ScheduleReceiptSchema,
            lifetime: "infinite",
            garbageCollection: 1,
        },
        findings: {
            description:
                "Project scan findings, eligible targets and blocked reasons",
            schema: FindingsSchema,
            lifetime: "infinite",
            garbageCollection: 1,
        },
    },
    methods: {
        refresh: refreshMethod,
        prune_dependency_results: {
            outputLifetime: "7d",
            description:
                "Prune seven-day dependency history while preserving referenced exact reports",
            arguments: z.object({}),
            execute: async (
                _args: Record<string, never>,
                context: RetentionContext,
            ) => {
                await pruneDependencyResults(context);
                return { dataHandles: [] };
            },
        },
    },
};
