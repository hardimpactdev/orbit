import {
    assertEquals,
    assertRejects,
    assertThrows,
} from "jsr:@std/assert@1.0.19";
import { DependencyProjectSchema } from "../extensions/behaviors/_lib/dependency_project_schema.ts";
import {
    changedSources,
    composerFacts,
    declared,
    fresh,
    manifests,
    npmFacts,
    type RegistryFacts,
    type SourcePackages,
    updates,
} from "../extensions/behaviors/_lib/maintenance/inspect.ts";

const project = (overrides: Record<string, unknown> = {}) =>
    DependencyProjectSchema.parse({
        projectId: 4,
        repository: "https://github.com/acme/shop.git",
        check: "composer check",
        defaultBranch: "main",
        worker: {
            composerPaths: ["."],
            jsPaths: ["web"],
            jsPackageManagers: { web: "npm" },
            policy: { ".": { minimumReleaseAgeDays: 3 } },
        },
        ...overrides,
    });
const sha = (n: number) => n.toString(16).padStart(40, "0");
const tree = [
    { path: "composer.json", sha: sha(1) },
    { path: "composer.lock", sha: sha(2) },
    { path: "README.md", sha: sha(3) },
    { path: "web/package.json", sha: sha(4) },
    { path: "web/package-lock.json", sha: sha(5) },
    { path: "web/packages/ui/package.json", sha: sha(6) },
    { path: "web/node_modules/x/package.json", sha: sha(7) },
];
const now = Date.parse("2026-11-03T12:00:00Z");
const days = (n: number) => new Date(now - n * 86400000).toISOString();

Deno.test("manifests selects each source's manifest, lockfile and workspace manifests only", () => {
    assertEquals(manifests(project(), tree), {
        '[".","composer","composer"]': {
            "composer.json": sha(1),
            "composer.lock": sha(2),
        },
        '["web","npm","npm"]': {
            "web/package-lock.json": sha(5),
            "web/package.json": sha(4),
            "web/packages/ui/package.json": sha(6),
        },
    });
    assertThrows(
        () =>
            manifests(
                project(),
                tree.filter((e) => e.path !== "composer.lock"),
            ),
        Error,
        "manifest_missing: composer.lock",
    );
});

Deno.test("changed sources are those whose file set or blob SHAs differ", () => {
    const current = manifests(project(), tree);
    assertEquals(changedSources(current, current), []);
    assertEquals(
        changedSources(current, {}),
        ['["web","npm","npm"]', '[".","composer","composer"]'].sort(),
    );
    const moved = {
        ...current,
        '[".","composer","composer"]': {
            "composer.json": sha(1),
            "composer.lock": sha(9),
        },
    };
    assertEquals(changedSources(moved, current), [
        '[".","composer","composer"]',
    ]);
    const dropped = {
        '[".","composer","composer"]': current['[".","composer","composer"]'],
    };
    assertEquals(changedSources(dropped, current), ['["web","npm","npm"]']);
});

Deno.test("declared reads direct constraints and refuses a malformed section", () => {
    const composer = {
        path: ".",
        ecosystem: "composer",
        package_manager: "composer",
    } as const;
    assertEquals(
        declared(composer, {
            require: { php: "^8.4", "laravel/framework": "^12.0" },
            "require-dev": { "pestphp/pest": "^3.0" },
        }),
        { php: "^8.4", "laravel/framework": "^12.0", "pestphp/pest": "^3.0" },
    );
    assertThrows(
        () => declared(composer, { require: ["laravel/framework"] }),
        Error,
        "invalid_manifest_section",
    );
});

const facts = (
    ecosystem: "composer" | "npm",
    name: string,
    releases: [string, number][],
    advisories: RegistryFacts["advisories"] = [],
): [string, RegistryFacts] => [`${ecosystem}:${name}`, {
    ecosystem,
    name,
    fetchedAt: new Date(now).toISOString(),
    releases: releases.map(([version, age]) => ({
        version,
        publishedAt: days(age),
    })),
    advisories,
}];
const composerSource = (
    packages: SourcePackages["packages"],
    ranges: Record<string, string>,
): SourcePackages => ({
    source: { path: ".", ecosystem: "composer", package_manager: "composer" },
    packages,
    declared: ranges,
});
const pkg = (name: string, version: string, direct = true) => ({
    id: `${name}@${version}`,
    name,
    version,
    direct,
    scopes: ["regular" as const],
    origin: "registry" as const,
});

Deno.test("updates proposes a major only past the declared range and the release age", () => {
    const sources = [
        composerSource([
            pkg("laravel/framework", "v12.3.0"),
            pkg("guzzlehttp/guzzle", "7.9.0", false),
        ], { "laravel/framework": "^12.0" }),
    ];
    const map = new Map([
        facts("composer", "laravel/framework", [["12.4.0", 10], ["13.0.0", 5], [
            "14.0.0",
            1,
        ]]),
        facts("composer", "guzzlehttp/guzzle", [["8.0.0", 30]]),
    ]);
    assertEquals(updates(project(), sources, map, now), {
        targets: [{
            path: ".",
            manager: "composer",
            package: "laravel/framework",
            version: "13.0.0",
            purpose: "major",
            advisories: [],
            advisoryUrls: [],
        }],
        blocked: [],
    });
});

Deno.test("updates fixes an advisory with the lowest eligible unaffected release, for transitive packages too", () => {
    const sources = [
        composerSource([pkg("guzzlehttp/psr7", "2.4.0", false)], {}),
    ];
    const advisory = {
        id: "PKSA-1",
        url: "https://github.com/advisories/GHSA-1",
        affected: "<2.4.5",
    };
    const map = new Map([
        facts("composer", "guzzlehttp/psr7", [["2.4.3", 20], ["2.4.5", 10], [
            "2.5.0",
            9,
        ]], [advisory]),
    ]);
    assertEquals(updates(project(), sources, map, now).targets, [
        {
            path: ".",
            manager: "composer",
            package: "guzzlehttp/psr7",
            version: "2.4.5",
            purpose: "security",
            advisories: ["PKSA-1"],
            advisoryUrls: ["https://github.com/advisories/GHSA-1"],
        },
    ]);
    const unverified = new Map([
        facts("composer", "guzzlehttp/psr7", [["2.4.5", 10]], [{
            ...advisory,
            url: null,
        }]),
    ]);
    assertEquals(updates(project(), sources, unverified, now), {
        targets: [],
        blocked: [{
            path: ".",
            manager: "composer",
            package: "guzzlehttp/psr7",
            reason: "Advisory has no verified source URL",
        }],
    });
});

Deno.test("updates treats a 0.x minor as a major, as the version rules do", () => {
    const sources = [
        composerSource([pkg("acme/zero", "0.3.2")], { "acme/zero": "^0.3" }),
    ];
    const map = new Map([
        facts("composer", "acme/zero", [["0.3.9", 20], ["0.4.0", 20]]),
    ]);
    assertEquals(
        updates(project(), sources, map, now).targets.map((
            t,
        ) => [t.version, t.purpose]),
        [["0.4.0", "major"]],
    );
});

Deno.test("updates keeps the highest fix and every advisory for copies of one package", () => {
    const copies = [
        composerSource([
            pkg("acme/lib", "1.0.0", false),
            pkg("acme/lib", "1.5.0", false),
        ], {}),
    ];
    const advisories = [
        { id: "A", url: "https://example.test/a", affected: "<1.1.0" },
        { id: "B", url: "https://example.test/b", affected: ">=1.5.0 <1.6.0" },
    ];
    const map = new Map([
        facts(
            "composer",
            "acme/lib",
            [["1.1.0", 30], ["1.6.0", 20]],
            advisories,
        ),
    ]);
    assertEquals(updates(project(), copies, map, now).targets, [
        {
            path: ".",
            manager: "composer",
            package: "acme/lib",
            version: "1.6.0",
            purpose: "security",
            advisories: ["A", "B"],
            advisoryUrls: ["https://example.test/a", "https://example.test/b"],
        },
    ]);
});

Deno.test("updates skips a package under a running exception", () => {
    const exceptions = {
        schema: 1,
        exceptions: [{
            path: ".",
            manager: "composer",
            package: "laravel/framework",
            recorded_on: "2026-11-01",
            days: 7,
        }],
    };
    const sources = [
        composerSource([pkg("laravel/framework", "12.3.0")], {
            "laravel/framework": "^12.0",
        }),
    ];
    const map = new Map([
        facts("composer", "laravel/framework", [["13.0.0", 5]]),
    ]);
    assertEquals(
        updates(project({ exceptions }), sources, map, now).targets,
        [],
    );
});

const respond = (routes: Record<string, unknown>) => (url: string) => {
    const key = Object.keys(routes).find((prefix) => url.startsWith(prefix));
    return Promise.resolve(
        key === undefined
            ? new Response("missing", { status: 404 })
            : Response.json(routes[key]),
    );
};

Deno.test("composer facts expand Packagist's minified releases and read advisories", async () => {
    const fetched = await composerFacts(
        "acme/lib",
        new Date(now),
        respond({
            "https://repo.packagist.org/p2/acme/lib.json": {
                packages: {
                    "acme/lib": [
                        {
                            version: "2.0.0",
                            time: days(2),
                            require: { php: "^8.4" },
                        },
                        { version: "1.9.0", time: days(40) },
                        { version: "2.1.0-beta1", time: days(1) },
                    ],
                },
            },
            "https://packagist.org/api/security-advisories/": {
                advisories: {
                    "acme/lib": [{
                        advisoryId: "PKSA-9",
                        link: "https://example.test/a",
                        affectedVersions: ">=1.0,<1.9.1|>=2.0,<2.0.1",
                    }],
                },
            },
        }),
    );
    assertEquals(fetched.releases, [
        { version: "2.0.0", publishedAt: days(2) },
        { version: "1.9.0", publishedAt: days(40) },
    ]);
    assertEquals(fetched.advisories.map((a) => a.id), ["PKSA-9"]);
    assertEquals(fresh(fetched, now + 3600000), true);
    assertEquals(fresh(fetched, now + 86400000), false);
});

Deno.test("npm facts refuse a foreign document and read bulk advisories", async () => {
    const routes = {
        "https://registry.npmjs.org/-/npm/v1/security/advisories/bulk": {
            vite: [{
                id: 1105,
                url: "https://github.com/advisories/GHSA-x",
                vulnerable_versions: "<6.1.1",
            }],
        },
        "https://registry.npmjs.org/vite": {
            name: "vite",
            versions: { "6.1.0": {}, "6.1.1": {}, "7.0.0-beta.1": {} },
            time: {
                created: days(900),
                "6.0.9": days(60),
                "6.1.0": days(30),
                "6.1.1": days(20),
                "7.0.0-beta.1": days(2),
            },
        },
    };
    const fetched = await npmFacts("vite", new Date(now), respond(routes));
    assertEquals(fetched.releases.map((r) => r.version), ["6.1.0", "6.1.1"]);
    assertEquals(fetched.advisories, [{
        id: "1105",
        url: "https://github.com/advisories/GHSA-x",
        affected: "<6.1.1",
    }]);
    await assertRejects(
        () =>
            npmFacts(
                "vite",
                new Date(now),
                respond({
                    "https://registry.npmjs.org/vite": { name: "other" },
                }),
            ),
        Error,
        "npm_foreign_package",
    );
});
