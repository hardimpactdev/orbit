import { expect, it } from "vite-plus/test";
import { page } from "vite-plus/test/browser";
import { openApp, pane, row } from "./app";
import type { DependencyResults } from "../../src/api/dependencies";

const path = "/api/v1/instances/1/dependencies";

it("reads locked Swamp versions only when selected, with Tasks disabled", async () => {
    const app = await openApp("/instances/1", { tasks: false });
    expect(app.gateway.requests.some((request) => request.path === path)).toBe(false);
    await page.getByTestId("instance-dependencies").click();
    await expect.element(row("Versions", "example/library")).toMatchTextContent("1.0.0");
    await expect.element(row("Versions", "fixture/local")).toMatchTextContent("dev-main");
    await expect
        .element(page.getByText("Locked repository versions", { exact: false }))
        .toBeVisible();
    expect(
        app.gateway.requests.filter((request) => request.path.includes("/dependencies")),
    ).toEqual([{ method: "GET", path, body: undefined }]);
    expect(document.body.textContent).not.toContain("Installed version");
});

it("reads Project evidence and selects each ecosystem without merging resolutions", async () => {
    const app = await openApp("/projects/1");
    await page.getByTestId("project-dependencies").click();
    await expect.element(row("Versions", "example/library")).toBeVisible();
    await page
        .getByRole("combobox", { name: "Dependency source" })
        .selectOptions("551ecad39fc8664221be8bf55181c6bb89ccc711164700449546234823f6c4d0");
    await expect.element(row("Versions", "example-lib").first()).toBeVisible();
    await expect.element(pane("Versions")).toMatchTextContent("0.9.0");
    expect(
        app.gateway.requests
            .filter((request) => request.path.includes("/dependencies"))
            .every((r) => r.method === "GET"),
    ).toBe(true);
});

it("shows blocked findings, advisory evidence and unknown PR lookup without inferring absence", async () => {
    await openApp("/instances/1", {
        wrapTransport(inner) {
            return async (method, url, body, options) => {
                const answer = await inner(method, url, body, options);
                if (url !== path) return answer;
                const data = (answer.payload as { data: DependencyResults }).data;
                data.batch_pr = {
                    status: "unknown",
                    checked_at: "2026-10-28T00:11:00Z",
                    last_success_at: "2026-10-28T00:10:00Z",
                    error_code: "lookup_unavailable",
                    value: {
                        repository: "github.com/fixture/dependency-app",
                        number: 17,
                        url: "https://github.com/fixture/dependency-app/pull/17",
                        head_commit: "c".repeat(40),
                        group_id: 2195,
                        run_id: "run-1",
                    },
                };
                if (data.report !== null)
                    data.report.findings = [
                        {
                            id: "blocked-advisory",
                            package: "example/library",
                            kind: "advisory",
                            current_versions: ["1.0.0"],
                            target_version: null,
                            advisory_ids: ["GHSA-2345-6789-cfgh"],
                            state: "blocked",
                            reason: {
                                code: "no_verified_target",
                                text: "No verified compatible fix is available.",
                            },
                            links: null,
                        },
                    ];
                return answer;
            };
        },
    });
    await page.getByTestId("instance-dependencies").click();
    await expect
        .element(page.getByTestId("dependency-batch-pr"))
        .toMatchTextContent("unknown · Last known #17");
    await page.getByRole("button", { name: "Findings", exact: true }).click();
    await expect
        .element(row("Findings", "example/library"))
        .toMatchTextContent("No verified target");
    await expect.element(pane("Findings")).toMatchTextContent("GHSA-2345-6789-cfgh");
    await expect
        .element(pane("Findings"))
        .toMatchTextContent("No verified compatible fix is available.");
});

it("keeps stale versions visible and distinguishes unavailable evidence", async () => {
    await openApp("/instances/1", {
        wrapTransport(inner) {
            return async (method, url, body, options) => {
                const answer = await inner(method, url, body, options);
                if (url === path) {
                    const data = (answer.payload as { data: DependencyResults }).data;
                    data.availability = "stale";
                    if (data.report !== null) data.report.freshness = "stale";
                }
                return answer;
            };
        },
    });
    await page.getByTestId("instance-dependencies").click();
    await expect.element(row("Versions", "example/library")).toMatchTextContent("1.0.0");
    await expect.element(page.getByText("Swamp: stale", { exact: false })).toBeVisible();
    await openApp("/instances/2");
    await page.getByTestId("instance-dependencies").click();
    await expect
        .element(pane("Versions"))
        .toMatchTextContent("Swamp dependency results are unavailable.");
});

it("shows loading and read errors without an empty successful view", async () => {
    let finish: (() => void) | undefined;
    const waiting = new Promise<void>((resolve) => {
        finish = resolve;
    });
    await openApp("/instances/1", {
        wrapTransport(inner) {
            return async (method, url, body, options) => {
                if (url !== path) return inner(method, url, body, options);
                await waiting;
                return {
                    status: 409,
                    payload: {
                        error: {
                            code: "dependencies.instance_unavailable",
                            message: "Instance is unavailable.",
                        },
                    },
                };
            };
        },
    });
    await page.getByTestId("instance-dependencies").click();
    await expect.element(pane("Dependencies")).toMatchTextContent("Loading Swamp dependencies…");
    finish?.();
    await expect.element(page.getByRole("alert")).toMatchTextContent("Instance is unavailable.");
});

it("keeps versions and source controls readable on a phone", async () => {
    await page.viewport(393, 659);
    await openApp("/projects/1/dependencies");
    await expect.element(row("Versions", "example/library")).toBeVisible();
    await expect.element(row("Versions", "fixture/local")).toMatchTextContent("path");
    await expect.element(page.getByRole("combobox", { name: "Dependency source" })).toBeVisible();
    expect(document.documentElement.scrollWidth).toBeLessThanOrEqual(393);
    expect(pane("Versions").element().getBoundingClientRect().width).toBeGreaterThan(300);
});

it.each([false, true])(
    "loads the next bounded page without mixing publication generations (changed=%s)",
    async (changed) => {
        const app = await openApp("/projects/1/dependencies", {
            wrapTransport(inner) {
                return async (method, url, body, options) => {
                    const answer = await inner(method, url, body, options);
                    if (!url.startsWith("/api/v1/projects/1/dependencies")) return answer;
                    const data = (answer.payload as { data: DependencyResults }).data;
                    const second = url.includes("packages_page=2");
                    if (data.report !== null) {
                        data.report.package_count = 101;
                        data.report.packages = Array.from({ length: second ? 1 : 100 }, (_, n) => ({
                            id: `package-${second ? 100 : n}`,
                            name: `package-${second ? 100 : n}`,
                            version: "1.0.0",
                            direct: true,
                            scopes: ["regular"],
                            origin: "registry",
                        }));
                        if (second && changed) data.report.publication_id = "changed-publication";
                    }
                    return answer;
                };
            },
        });
        await expect.element(row("Versions", "package-99")).toBeInTheDocument();
        row("Versions", "package-99").element().scrollIntoView();
        if (changed) {
            await expect
                .element(
                    page.getByRole("button", { name: "Results changed. Refresh to continue." }),
                )
                .toBeVisible();
            expect(pane("Versions").element().textContent).not.toContain("package-100");
        } else {
            await expect.element(row("Versions", "package-100")).toBeVisible();
        }
        expect(app.gateway.requests.filter((r) => r.path.includes("packages_page=2"))).toHaveLength(
            1,
        );
    },
);
