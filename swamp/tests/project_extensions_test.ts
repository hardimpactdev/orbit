import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import { projectById } from "./_support/projects.ts";
import {
    batchReceipt,
    cleanedCancelledGroup,
    projectFindings,
    scheduleKey,
} from "../extensions/behaviors/_lib/project_schedule.ts";
import { type DependencyProject } from "../extensions/behaviors/_lib/dependency_project_schema.ts";
const original: DependencyProject = JSON.parse(
    await Deno.readTextFile(
        new URL(
            "./fixtures/project-extensions/nckrtl-com.json",
            import.meta.url,
        ),
    ),
);
const reports = JSON.parse(
    await Deno.readTextFile(
        new URL(
            "./fixtures/project-extensions/detection.json",
            import.meta.url,
        ),
    ),
);

Deno.test("the data file holds the recorded Project 20 configuration", () => {
    assertEquals(projectById(20), original);
});

Deno.test("weekly slots cross year boundaries and hourly inspection stays separate", () => {
    assertEquals(
        scheduleKey(20, "routine", new Date("2027-01-03T23:59:59Z")),
        "20-routine-2026-12-28",
    );
    assertEquals(
        scheduleKey(20, "routine", new Date("2027-01-04T00:00:00Z")),
        "20-routine-2027-01-04",
    );
    assertEquals(
        scheduleKey(20, "detect", new Date("2027-01-03T23:59:59Z")),
        "20-detect-2027-01-03T23:00",
    );
});
Deno.test("project findings retain reports and blocked reasons and create one deterministic whole batch receipt", async () => {
    const plans = reports.map((
        r: {
            candidates: {
                path: string;
                manager: string;
                package: string;
                latest?: string;
                advisories: { id: string; url: string }[];
            }[];
        },
    ) => ({
        schema: 1,
        targets: r.candidates.filter((c) => c.latest).map((c) => ({
            path: c.path,
            manager: c.manager,
            package: c.package,
            version: c.latest,
            purpose: c.advisories.length ? "security" : "major",
            advisories: c.advisories.map((a) => a.id),
            advisoryUrls: c.advisories.map((a) => a.url),
        })),
        blocked: [],
    }));
    const findings = await projectFindings(original, "source", reports, plans);
    const receipt = await batchReceipt(original, findings);
    assertEquals(receipt.targets?.length, 2);
    assertEquals((receipt.request.tasks as unknown[]).length, 1);
    assertEquals(await batchReceipt(original, findings), receipt);
    assertThrows(
        () => projectFindings(original, "source", [], plans),
        Error,
        "missing an ecosystem",
    );
    assertThrows(
        () =>
            projectFindings(original, "source", reports, [{
                schema: 1,
                targets: [{ ...plans[0].targets[0], path: "outside" }],
                blocked: [],
            }, plans[1]]),
        Error,
        "not configured",
    );
});

Deno.test("cancelled schedule cleanup requires ended children, no assistance and a removed or absent VM", () => {
    const clean = {
        status: "cancelled",
        taskable_id: null,
        vm_power: null,
        tasks: [{ status: "cancelled" }],
    };
    assertEquals(cleanedCancelledGroup(clean), true);
    assertEquals(
        cleanedCancelledGroup({
            ...clean,
            taskable_id: 7,
            vm_power: "destroyed",
        }),
        true,
    );
    for (
        const changes of [
            { status: "failed" },
            { taskable_id: 7 },
            { vm_power: "running" },
            { vm_power: "parked" },
            { vm_power: undefined },
            { assistance_requested: true },
            { assistance_reason: "Review cancellation" },
            { tasks: [{ status: "running" }] },
            { tasks: [{ status: "cancelled", assistance_requested: true }] },
        ]
    ) assertEquals(cleanedCancelledGroup({ ...clean, ...changes }), false);
});

