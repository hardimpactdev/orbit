import { projectById, projects } from "./_support/projects.ts";
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.11";
import {
    type DependencyProject,
    DependencyProjectsSchema,
} from "../extensions/behaviors/_lib/dependency_project_schema.ts";

interface Source {
    slug: string;
    project: {
        id: number;
        repository_url: string;
        task_check: string;
        default_branch: string;
    };
    source: { repository: string; commit: string };
    manifestPaths: string[];
    setup: Record<string, string | Record<string, unknown>>;
}
const sources: Source[] = JSON.parse(
    await Deno.readTextFile(
        new URL("./fixtures/project-extensions/fleet.json", import.meta.url),
    ),
);
const repo = new URL("../", import.meta.url);
const yaml = async (file: string) =>
    parse(await Deno.readTextFile(new URL(file, repo))) as Record<
        string,
        unknown
    >;
for (const source of sources) {
    Deno.test(`${source.slug} preserves its current Project settings, configured paths and age policy`, async () => {
        const config: DependencyProject = projectById(source.project.id);
        assertEquals(config.dependencyAutoMerge, false);
        assertEquals("script" in config, false);
        assertEquals(config.projectId, source.project.id);
        assertEquals(config.repository, source.project.repository_url);
        assertEquals(config.check, source.project.task_check);
        assertEquals(config.defaultBranch, source.project.default_branch);
        const paths =
            (source.setup["maintainer.json"] as { paths: string[] } | undefined)
                ?.paths ?? ["."];
        const manifest = (path: string, name: string) =>
            `${path === "." ? "" : path + "/"}${name}`;
        assertEquals(
            config.worker!.composerPaths,
            paths.filter((p) =>
                source.manifestPaths.includes(manifest(p, "composer.json"))
            ),
        );
        assertEquals(
            config.worker!.jsPaths,
            paths.filter((p) =>
                source.manifestPaths.includes(manifest(p, "package.json"))
            ),
        );
        const expected: Record<string, { minimumReleaseAgeDays: number }> = {};
        for (const path of paths) {
            const vet = source.setup[manifest(path, "vet.json")] as
                | Record<string, number>
                | undefined;
            const npm = source.setup[manifest(path, ".npmrc")] as
                | string
                | undefined;
            const bun = source.setup[manifest(path, "bunfig.toml")] as
                | string
                | undefined;
            const ages = [
                vet?.["minimum-release-age"],
                npm?.match(/^min-release-age=(\d+)/m)?.[1]
                    ? Number(npm.match(/^min-release-age=(\d+)/m)![1])
                    : undefined,
                bun?.match(/^minimumReleaseAge\s*=\s*(\d+)/m)?.[1]
                    ? Number(bun.match(/^minimumReleaseAge\s*=\s*(\d+)/m)![1]) /
                        86400
                    : undefined,
            ].filter((age) => age !== undefined);
            assert(
                new Set(ages).size <= 1,
                "source ecosystem policies disagree",
            );
            if (ages.length) {
                expected[path] = { minimumReleaseAgeDays: ages[0]! };
            }
        }
        assertEquals(config.worker!.policy, expected);
        if (source.slug === "operator") {
            assertEquals(config.worker!.composerPaths, ["server/web"]);
            assertEquals(config.worker!.jsPaths, ["server/web"]);
        }
        if (["hauzer", "mealou"].includes(source.slug)) {
            assertEquals(config.worker!.jsPaths, [".", "mobile"]);
        }
        if (
            [
                "launch-starter-kit",
                "treadmill-website",
                "orbit-website",
                "ai-cli-proxy",
            ].includes(source.slug)
        ) assertEquals(config.worker!.policy, {});
        assert(/^[0-9a-f]{40}$/.test(source.source.commit));
    });
}

Deno.test("the fleet retains private project configuration in one data file without per-project workflows", async () => {
    assertEquals(sources.length, 18);
    assertEquals(new Set(sources.map((s) => s.project.id)).size, 18);
    assert(!sources.some((s) => s.slug === "horizon-new-dawn"));
    const ids = projects.map((p) => p.projectId);
    assertEquals(new Set(ids).size, ids.length);
    DependencyProjectsSchema.parse(projects);
    const gateway = await yaml(
        "models/@nckrtl/orbit-gateway/orbit-gateway.yaml",
    ) as {
        globalArguments: Record<string, unknown>;
    };
    assertEquals(gateway.globalArguments.dependencyProjects, []);
    assertEquals("projectExtensions" in gateway.globalArguments, false);
    for (const source of sources) {
        assert(ids.includes(source.project.id), source.slug);
        for (const mode of ["routine", "detect"]) {
            await Deno.stat(
                new URL(
                    `workflows/workflow-project-${source.slug}-${mode}.yaml`,
                    repo,
                ),
            ).then(
                () => {
                    throw new Error("Project-specific workflow still exists");
                },
                (error) => {
                    if (!(error instanceof Deno.errors.NotFound)) throw error;
                },
            );
        }
    }
});

Deno.test("private project config preserves every exception ledger found in the 20-repository inventory", async () => {
    const inventory = JSON.parse(
        await Deno.readTextFile(
            new URL(
                "./fixtures/project-extensions/exception-inventory.json",
                import.meta.url,
            ),
        ),
    ) as {
        name: string;
        nameWithOwner: string;
        fileSha?: string;
        exceptions: unknown;
    }[];
    assertEquals(inventory.length, 20);
    const withLedger = inventory.filter((row) => row.exceptions !== null);
    assertEquals(withLedger.map((row) => row.nameWithOwner), [
        "nckrtl/recall",
        "hardimpactdev/laravel-toolbar-site",
        "nckrtl/operator-website",
        "nckrtl/operator",
    ]);
    for (const row of withLedger) {
        assert(/^[a-f0-9]{40}$/.test(row.fileSha!));
        const config = projectById(
            sources.find((source) => source.slug === row.name)!.project.id,
        );
        assertEquals(config.exceptions, row.exceptions);
    }
});

Deno.test("the Project routine entrypoint accepts full Gateway inputs and runs on 209", async () => {
    const ids = new Set<string>();
    for (const mode of ["routine"]) {
        const workflow = await yaml(
            `workflows/workflow-project-${mode}.yaml`,
        ) as {
            id: string;
            name: string;
            trigger?: unknown;
            queueTimeout?: unknown;
            inputs: {
                required: string[];
                additionalProperties: boolean;
                properties: Record<string, unknown>;
            };
            jobs: {
                target: string;
                labels?: unknown;
                steps: {
                    name: string;
                    target: string;
                    labels?: unknown;
                    task: Record<string, unknown>;
                }[];
            }[];
        };
        assertEquals(workflow.name, `project-${mode}`);
        assert(!ids.has(workflow.id));
        ids.add(workflow.id);
        assertEquals(workflow.trigger, undefined);
        assertEquals(workflow.queueTimeout, undefined);
        assertEquals(workflow.inputs.required, [
            "operation_id",
            "node_id",
            "kind",
            "intent",
            "execution_secrets",
        ]);
        assertEquals(workflow.inputs.additionalProperties, false);
        assertEquals(workflow.inputs.properties.kind, {
            type: "string",
            const: `project.${mode}`,
        });
        assertEquals(workflow.inputs.properties.node_id, {
            type: "integer",
            enum: [209],
        });
        assertEquals(workflow.inputs.properties.projectId, undefined);
        for (const job of workflow.jobs) {
            assertEquals(job.labels, { role: "swamp" });
            assertEquals(job.target, undefined);
            for (const step of job.steps) {
                assertEquals(step.labels, { role: "swamp" });
                assertEquals(step.target, undefined);
                assertEquals(step.task.modelType, undefined);
            }
        }
    }
});
