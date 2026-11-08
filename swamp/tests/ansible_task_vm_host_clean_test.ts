/**
 * The refresh's host phases that delete: `host-clean` removes only what no current path makes, and a rebuild deletes the base VM and
 * its seed before it builds the base again. The real playbook runs on localhost against a fake `incus` that logs every call.
 */
import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";

const hasAnsible =
  new Deno.Command("sh", { args: ["-c", "command -v ansible-playbook"] })
    .outputSync().success;
const playbook =
  new URL("../ansible/task-vm-host.yml", import.meta.url).pathname;

const own = {
  "features.images": "true",
  "features.storage.volumes": "true",
  "features.profiles": "true",
};
const projects = [
  { name: "default", config: {} },
  { name: "orbit-tasks", config: own },
  { name: "orbit-baselines", config: own },
  {
    name: "orbit-measure-nr",
    config: { ...own, "features.networks": "false" },
  },
  { name: "orbit-proto", config: own },
  { name: "research", config: own },
  { name: "orbit-ci", config: own },
];
const listings: Record<string, unknown> = {
  "project list --format json": projects,
  "--project orbit-tasks image list --format json": [
    { fingerprint: "e4851b7413e2aa", aliases: [{ name: "ubuntu-26.04-vm" }] },
    { fingerprint: "add4d4eaefacaa", aliases: [{ name: "orbit-task-base" }] },
    { fingerprint: "66762546792eaa", aliases: [] },
  ],
  "--project orbit-tasks list --format json": [
    { name: "beast-base" },
    { name: "beast-tvm-1" },
    { name: "tvm-610" },
    { name: "orbit-baseline-old" },
  ],
  "--project orbit-tasks storage volume list orbit-tasks --format json": [
    { name: "beast-base-seed", type: "custom" },
    { name: "beast-tvm-1-seed", type: "custom" },
    { name: "beast-tvm-1-identity", type: "custom" },
    { name: "tvm-610-topology-seed", type: "custom" },
    { name: "orbit-topology-seed-add4d4eaefac", type: "custom" },
    { name: "orbit-baseline-seed", type: "custom" },
    { name: "beast-base", type: "virtual-machine" },
  ],
  "storage list --format json": [{ name: "default" }, { name: "orbit-tasks" }],
  "--project orbit-baselines list --format json": [{
    name: "orbit-baseline-gateway",
  }],
  "--project orbit-baselines image list --format json": [{
    fingerprint: "faf0e887aa8d",
  }],
  "--project orbit-baselines storage volume list default --format json": [],
  "--project orbit-baselines storage volume list orbit-tasks --format json": [
    { name: "orbit-baseline-verify-seed", type: "custom" },
  ],
  "--project orbit-measure-nr list --format json": [{ name: "nr-builder" }, {
    name: "nr-task",
  }],
  "--project orbit-measure-nr image list --format json": [{
    fingerprint: "11b87b93aa32",
  }],
  "--project orbit-measure-nr storage volume list default --format json": [],
  "--project orbit-measure-nr storage volume list orbit-tasks --format json": [
    { name: "nr-seed-new", type: "custom" },
    { name: "nr-builder", type: "virtual-machine" },
  ],
  // A retired project keeps the profile its instances used; Incus refuses to delete the project while it exists.
  "--project orbit-baselines profile list --format json": [
    { name: "default" },
    { name: "baseline" },
  ],
  "--project orbit-proto list --format json": [],
  "--project orbit-proto image list --format json": [{
    fingerprint: "c1c2cf4051fb",
  }],
};

/** Runs the phase `tags` with `host` as its intent; returns the logged incus calls and whether the play passed. */
function run(
  tags: string,
  host: Record<string, unknown>,
  failing = "",
  overrides: Record<string, unknown> = {},
) {
  const folder = Deno.makeTempDirSync();
  Deno.mkdirSync(`${folder}/bin`);
  for (const [call, answer] of Object.entries({ ...listings, ...overrides })) {
    Deno.writeTextFileSync(
      `${folder}/${encodeURIComponent(call)}`,
      JSON.stringify(answer),
    );
  }
  // A listing answers from its file; every other call succeeds, but the one named in FAILING.
  Deno.writeTextFileSync(
    `${folder}/bin/incus`,
    `#!/bin/sh
echo "$*" >> "${folder}/log"
case " $* " in *" init "*) cat > "${folder}/launch-config" ;; esac
[ -n "$FAILING" ] && [ "$*" = "$FAILING" ] && exit 1
file="${folder}/$(printf '%s' "$*" | jq -sRr @uri)"
if [ -f "$file" ]; then cat "$file"; exit 0; fi
case "$*" in *" list "*"--format json"*|*" list --format json") echo "[]" ;; *"get "*) echo allow ;; esac
exit 0
`,
  );
  Deno.chmodSync(`${folder}/bin/incus`, 0o755);
  Deno.writeTextFileSync(`${folder}/intent.json`, JSON.stringify({ host }));
  const done = new Deno.Command("ansible-playbook", {
    args: [
      "-i",
      "localhost,",
      "-e",
      `@${folder}/intent.json`,
      "-e",
      `run_dir=${folder}`,
      "-e",
      "ansible_connection=local",
      "-e",
      "ansible_become=false",
      "-e",
      "ansible_python_interpreter={{ ansible_playbook_python }}",
      "--tags",
      tags,
      playbook,
    ],
    env: {
      PATH: `${folder}/bin:${Deno.env.get("PATH")}`,
      FAILING: failing,
      ANSIBLE_HOME: `${folder}/home`,
      ANSIBLE_LOCAL_TEMP: `${folder}/tmp`,
      ANSIBLE_NOCOLOR: "1",
    },
  }).outputSync();
  const log = (() => {
    try {
      return Deno.readTextFileSync(`${folder}/log`).trim().split("\n");
    } catch {
      return [];
    }
  })();
  let initStdin: string | null = null;
  try {
    initStdin = Deno.readTextFileSync(`${folder}/launch-config`);
  } catch (error) {
    if (!(error instanceof Deno.errors.NotFound)) throw error;
  }
  Deno.removeSync(folder, { recursive: true });
  return {
    log,
    initStdin,
    passed: done.success,
    output: new TextDecoder().decode(done.stdout),
  };
}

const host = {
  project: "orbit-tasks",
  pool: "orbit-tasks",
  ssh: {
    target: {
      host: "127.0.0.1",
      user: "orbit",
      port: 22,
      host_keys: ["ssh-ed25519 AAAA"],
    },
    identity_file: "/dev/null",
  },
  clean: {
    keep_images: ["ubuntu-26.04-vm"],
    prefixes: ["orbit-topology-seed-", "orbit-baseline-"],
    retired_projects: ["orbit-baselines", "orbit-measure-nr"],
    image_projects: ["orbit-proto"],
  },
};
const deletes = (log: string[]) =>
  log.filter((call) => / delete /.test(` ${call} `));

Deno.test({
  name:
    "host-clean removes only old images, retired prefixes, the retired projects whole and the images of the image projects",
  ignore: !hasAnsible,
  fn() {
    const { log, passed, output } = run("host-clean", host);
    assert(passed, output);
    assertEquals(deletes(log), [
      "--project orbit-tasks image delete add4d4eaefacaa",
      "--project orbit-tasks image delete 66762546792eaa",
      "--project orbit-tasks delete --force orbit-baseline-old",
      "--project orbit-tasks storage volume delete orbit-tasks orbit-topology-seed-add4d4eaefac",
      "--project orbit-tasks storage volume delete orbit-tasks orbit-baseline-seed",
      "--project orbit-baselines delete --force orbit-baseline-gateway",
      "--project orbit-baselines image delete faf0e887aa8d",
      "--project orbit-baselines storage volume delete orbit-tasks orbit-baseline-verify-seed",
      "--project orbit-baselines profile delete baseline",
      "project delete orbit-baselines",
      "--project orbit-measure-nr delete --force nr-builder",
      "--project orbit-measure-nr delete --force nr-task",
      "--project orbit-measure-nr image delete 11b87b93aa32",
      "--project orbit-measure-nr storage volume delete orbit-tasks nr-seed-new",
      "project delete orbit-measure-nr",
      "--project orbit-proto image delete c1c2cf4051fb",
    ]);
  },
});

Deno.test({
  name:
    "a project that cannot be emptied fails the phase after the other projects had their turn",
  ignore: !hasAnsible,
  fn() {
    const { log, passed, output } = run(
      "host-clean",
      host,
      "project delete orbit-baselines",
    );
    assertEquals(passed, false);
    assertStringIncludes(output, "task_vm.host_clean_failed: orbit-baselines");
    assert(log.includes("project delete orbit-measure-nr"));
    assert(log.includes("--project orbit-proto image delete c1c2cf4051fb"));
  },
});

Deno.test({
  name:
    "host-clean refuses a task VM project that shares images or volumes with default",
  ignore: !hasAnsible,
  fn() {
    const shared = projects.map((p) =>
      p.name === "orbit-tasks" ? { ...p, config: {} } : p
    );
    listings["project list --format json"] = shared;
    try {
      const { log, passed, output } = run("host-clean", host);
      assertEquals(passed, false);
      assertStringIncludes(output, "task_vm.host_clean_refused");
      assertEquals(deletes(log), []);
    } finally {
      listings["project list --format json"] = projects;
    }
  },
});

Deno.test({
  name:
    "a profile that cannot be removed keeps its project and fails the phase after the other projects had their turn",
  ignore: !hasAnsible,
  fn() {
    const { log, passed, output } = run(
      "host-clean",
      host,
      "--project orbit-baselines profile delete baseline",
    );
    assertEquals(passed, false);
    assertStringIncludes(output, "task_vm.host_clean_failed: orbit-baselines");
    assert(!log.includes("project delete orbit-baselines"));
    assert(log.includes("project delete orbit-measure-nr"));
    assert(log.includes("--project orbit-proto image delete c1c2cf4051fb"));
  },
});

Deno.test({
  name:
    "a retired project that shares its profiles with default loses none of them",
  ignore: !hasAnsible,
  fn() {
    // Its profile list is that of `default`, so deleting from it would delete the host's own profiles.
    listings["project list --format json"] = projects.map((project) =>
      project.name === "orbit-baselines"
        ? { ...project, config: { ...own, "features.profiles": "false" } }
        : project
    );
    try {
      const { log, passed, output } = run("host-clean", host);
      assert(passed, output);
      assert(!log.some((call) => / profile delete /.test(` ${call} `)));
      assert(log.includes("project delete orbit-baselines"));
    } finally {
      listings["project list --format json"] = projects;
    }
  },
});

Deno.test({
  name:
    "a rebuild deletes the base VM and its seed; a recorded-base refresh preserves them",
  ignore: !hasAnsible,
  fn() {
    const base = {
      ...host,
      current: "beast-base/proved",
      network: "orbittask0",
      image: "ubuntu-26.04-vm",
      base: {
        vm: "beast-base",
        seed: "beast-base-seed",
        seed_size: "45GiB",
        cpus: 4,
        memory: "8GiB",
        disk: "40GiB",
        // Larger than a pipe buffer: the fake must read all input, like real incus init.
        launch_config: "config: {}\n#" + "x".repeat(128 * 1024),
        club_key: "/home/orbit/.swamp-club-key",
      },
    };
    const rebuild = run("base-start", { ...base, rebuild: true });
    assert(rebuild.passed, rebuild.output);
    assertEquals(rebuild.initStdin, base.base.launch_config + "\n");
    assertEquals(rebuild.log.slice(0, 2), [
      "--project orbit-tasks delete --force beast-base",
      "--project orbit-tasks storage volume delete orbit-tasks beast-base-seed",
    ]);
    const refresh = run("base-start", { ...base, rebuild: false });
    assert(refresh.passed, refresh.output);
    assertEquals(refresh.initStdin, base.base.launch_config + "\n");
    const bootConfig = '[boot-opts]\nmenu = "on"\nsplash-time = "0"\n';
    const setBoot =
      `--project orbit-tasks config set beast-base raw.qemu.conf=${bootConfig}`
        .trimEnd();
    // Existing bases must be repaired before their next start; an init-only setting leaves the old wait.
    const readBase = "--project orbit-tasks list --format json -- ^beast-base$";
    const oldBase = {
      name: "beast-base",
      status: "Stopped",
      config: {},
      devices: { "topology-seed": {} },
    };
    const repaired = run("base-start", { ...base, rebuild: false }, "", {
      [readBase]: [oldBase],
    });
    assert(repaired.passed, repaired.output);
    assertEquals(repaired.initStdin, null);
    assertStringIncludes(repaired.log.join("\n"), setBoot);
    assert(
      repaired.log.findIndex((call) => call.includes("raw.qemu.conf=")) <
        repaired.log.indexOf("--project orbit-tasks start -- beast-base"),
    );
    const current = run("base-start", { ...base, rebuild: false }, "", {
      [readBase]: [{ ...oldBase, config: { "raw.qemu.conf": bootConfig } }],
    });
    assert(current.passed, current.output);
    assertEquals(
      current.log.filter((call) => call.includes("raw.qemu.conf=")),
      [],
    );

    assertEquals(
      refresh.log.filter((call) => call.includes(" delete --force beast-base")),
      [],
    );
  },
});
