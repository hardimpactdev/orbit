export const inventory = (paths: string[]) =>
  btoa(
    paths.map((path) =>
      `worktree ${path}\0HEAD ${"b".repeat(40)}\0branch refs/heads/main\0\0`
    ).join(""),
  );
export function member(id = 1, layout: "checkout" | "worktree" = "checkout") {
  const checkout = layout === "checkout"
    ? "/srv/apps/proof/main"
    : "/srv/apps/proof/work";
  return {
    instance_id: id,
    source: {
      root: "/srv/apps",
      operation: "0c4f71cf-84bd-4b80-8da4-8cd1b39cb8b6",
      member: String(id),
      digest: "a".repeat(64),
      user: "orbit",
      group: "orbit",
      checkout,
      common_repository: "/srv/apps/proof/main",
      layout,
      source_identity: "42:91",
    },
    branch: "main",
    receipt: "c".repeat(64),
    force: true,
    origin: "https://github.com/example/proof.git",
    worktrees: inventory(["/srv/apps/proof/main", "/srv/apps/proof/work"]),
    commit: "b".repeat(40),
    grouping_directory: "/srv/apps/proof",
    unresolved: false,
    independent_worktree: false,
    own_units: ["orbit-process-1-ssr.service"],
  };
}
export function fixture() {
  return {
    node_id: 9,
    members: [member()],
    database: database(member()),
    runtime: {
      processes: [{
        process_id: 1,
        runtime: "systemd" as const,
        name: "orbit-process-1-ssr.service",
        vite: null,
      }],
      schedules: [{
        id: "4377084c-a00e-4148-b35e-93617a91f6b6",
        group: "orbit",
        cascade: true as const,
      }],
    },
  };
}
export function database(source: ReturnType<typeof member>) {
  return {
    owner_instance_id: source.instance_id,
    intent_id: 4321,
    connection_ids: [source.instance_id],
    servers: [],
    sqlite: [{
      connection_id: source.instance_id,
      node_id: 9,
      checkout: source.source.checkout,
      path: `${source.source.checkout}/database.sqlite`,
    }],
  };
}
