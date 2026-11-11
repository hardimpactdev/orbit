/** Run the shipped host phase of a task container host against a stateful Incus boundary, including a second unchanged run. */
import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";

export const hasAnsible =
  new Deno.Command("sh", { args: ["-c", "command -v ansible-playbook"] })
    .outputSync().success;
export const root = new URL("../../ansible/", import.meta.url);
export const load = (name: string) =>
  parse(Deno.readTextFileSync(new URL(`tasks/${name}`, root))) as Record<
    string,
    unknown
  >[];
export const plays = parse(
  Deno.readTextFileSync(new URL("task-vm-host.yml", root)),
) as Record<string, unknown>[];
export const play = plays.find((p) => p.name === "Prepare the task VM host")!;
export const GiB = 1073741824;

export type Task = Record<string, unknown>;

/**
 * The tasks of `name` as an unprivileged user can run them here: the container includes inline, so the phase runs as one play.
 * Package, kernel-module, sysctl and firewall modules need Linux and root: they become files in the fixture folder that the test reads.
 */
export function local(name: string, folder: string): Task[] {
  const out: Task[] = [];
  for (const source of load(name)) {
    const task = structuredClone(source);
    const include = task["ansible.builtin.include_tasks"] as string | undefined;
    if (include?.startsWith("task-container-")) {
      out.push({
        name: task.name,
        when: task.when,
        block: local(include, folder),
      });
      continue;
    }
    for (const part of ["block", "always"]) {
      if (Array.isArray(task[part])) {
        task[part] = (task[part] as Task[]).flatMap((inner) =>
          transform(inner, folder)
        );
      }
    }
    out.push(...transform(task, folder));
  }
  return out;
}

export function transform(task: Task, folder: string): Task[] {
  if ("block" in task) return [task];
  if (task["ansible.builtin.include_tasks"] === "artifact-fetch.yml") {
    delete task["ansible.builtin.include_tasks"];
    task["ansible.builtin.copy"] = {
      dest: "{{ artifact.path }}",
      content: "{{ artifact | to_json }}",
      mode: "0600",
    };
    return [task];
  }
  if ("community.general.ufw" in task) {
    task.vars = {
      ...(task.vars as object ?? {}),
      fixture_rule: task["community.general.ufw"],
    };
    delete task["community.general.ufw"];
    task["ansible.builtin.copy"] = {
      dest:
        `${folder}/firewall-{{ fixture_rule.comment | regex_replace('[^a-z-]', '_') }}.json`,
      content: "{{ fixture_rule | to_json }}",
      mode: "0600",
    };
  }
  if ("ansible.posix.sysctl" in task) {
    task.vars = {
      ...(task.vars as object ?? {}),
      fixture_sysctl: task["ansible.posix.sysctl"],
    };
    delete task["ansible.posix.sysctl"];
    task["ansible.builtin.copy"] = {
      dest: `${folder}/sysctl/{{ fixture_sysctl.name }}`,
      content: "{{ fixture_sysctl.value }} {{ fixture_sysctl.sysctl_file }}",
      mode: "0600",
    };
  }
  for (const module of ["ansible.builtin.apt", "community.general.modprobe"]) {
    if (module in task) {
      delete task[module];
      task["ansible.builtin.debug"] = { msg: module };
    }
  }
  for (const module of ["ansible.builtin.copy", "ansible.builtin.file"]) {
    const args = task[module] as Record<string, unknown> | undefined;
    if (args) {
      delete args.owner;
      delete args.group;
    }
  }
  return [task];
}

export function fixture(
  {
    memory = 32 * GiB,
    written = "307200",
    installed = "installed",
    server = "7.0.1",
    subids = 1000000000,
    sctp = "install /bin/false ",
    sctpLoaded = false,
    arc = "0",
    native = false,
  }: {
    memory?: number;
    // The ARC cap the host runs before the phase; 0 is no cap.
    arc?: string;
    // An existing native pool on a ZFS dataset, as on Shark, instead of a new loop pool.
    native?: boolean;
    written?: string;
    installed?: string;
    server?: string;
    subids?: number;
    sctp?: string;
    sctpLoaded?: boolean;
  } = {},
) {
  const folder = Deno.makeTempDirSync();
  for (const dir of ["bin", "disks", "sysctl", "debs", "sys"]) {
    Deno.mkdirSync(`${folder}/${dir}`);
  }
  if (sctpLoaded) Deno.mkdirSync(`${folder}/sys/sctp`);
  Deno.writeTextFileSync(`${folder}/arc`, `${arc}\n`);
  Deno.writeTextFileSync(
    `${folder}/meminfo`,
    `MemTotal:       ${memory / 1024} kB\nMemFree:          1000 kB\n`,
  );
  for (const ids of ["subuid", "subgid"]) {
    Deno.writeTextFileSync(
      `${folder}/${ids}`,
      `orbit:100000:65536\nroot:1000000:${subids}\n`,
    );
  }
  const state = {
    "storage-pools": native
      ? [{
        name: "orbit-tasks",
        driver: "zfs",
        config: { source: "data/orbit-tasks" },
      }]
      : [],
    projects: [],
    networks: [],
    "network-acls": [],
    profiles: {},
    images: [],
    instances: [],
    server_version: server,
    zfs_pool_name: native ? "data/orbit-tasks" : "orbit-tasks",
  };
  Deno.writeTextFileSync(`${folder}/state.json`, JSON.stringify(state));
  const scripts: Record<string, string> = {
    incus: `exec '${Deno.execPath()}' run -A '${
      new URL("../fixtures/task-vm-host-incus.ts", import.meta.url).pathname
    }' "$@"`,
    zfs:
      'case "$*" in *written*) printf "%s\\n" "$WRITTEN" ;; *) printf "%s\\n" "$DATASETS" ;; esac',
    // dpkg-query's db:Status-Status is independent of the package's hold selection.
    "dpkg-query": '[ -n "$INSTALLED" ] || exit 1; printf "%s" "$INSTALLED"',
    modprobe: 'printf "%s\\n" "$SCTP"',
    "apt-get": `printf "%s\\n" "$*" >> '${folder}/apt-get'`,
    // The host's current values: Ubuntu's vm.max_map_count is above the floor and stays.
    sysctl: 'printf "16384\\n128\\n65536\\n200\\n20000\\n1048576\\n"',
  };
  for (const [name, body] of Object.entries(scripts)) {
    Deno.writeTextFileSync(`${folder}/bin/${name}`, `#!/bin/sh\n${body}\n`);
    Deno.chmodSync(`${folder}/bin/${name}`, 0o755);
  }
  const source = JSON.stringify([{
    ...play,
    hosts: "localhost",
    become: false,
    tasks: local("task-vm-host-prepare.yml", folder),
  }])
    .replaceAll("/etc/modprobe.d/orbit-task-vm-zfs.conf", `${folder}/arc.conf`)
    .replaceAll("/sys/module/zfs/parameters/zfs_arc_max", `${folder}/arc`)
    .replaceAll("/var/lib/incus/disks/", `${folder}/disks/`)
    .replaceAll(
      "/etc/modules-load.d/orbit-{{ container_host.kind }}.conf",
      `${folder}/modules.conf`,
    )
    .replaceAll("/etc/subuid", `${folder}/subuid`)
    .replaceAll("/etc/subgid", `${folder}/subgid`)
    .replaceAll("/proc/meminfo", `${folder}/meminfo`)
    .replaceAll("/sys/module/sctp", `${folder}/sys/sctp`)
    .replaceAll("/var/cache/orbit/debs", `${folder}/debs`);
  Deno.writeTextFileSync(`${folder}/play.yml`, stringify(JSON.parse(source)));
  const packages = ["incus", "incus-base", "incus-client"].map((name, i) => ({
    name,
    sha256: String(i + 1).repeat(64),
    urls: [
      `https://mirror.example/${name}_${String(i + 1).repeat(64)}.deb`,
      `https://pkgs.zabbly.com/incus/lts-7.0/pool/main/i/incus/${name}_7.0.2-ubuntu26.04-1_amd64.deb`,
    ],
  }));
  const host = {
    project: "orbit-tasks",
    pool: "orbit-tasks",
    network: "orbittask0",
    cidr: "10.252.10.0/24",
    pool_size: "640GiB",
    zfs_dataset: native ? "data/orbit-tasks" : null,
    image: "ubuntu-26.04-vm",
    containers: {
      project: "orbit-task-containers",
      network: "orbittaskc0",
      cidr: "10.252.11.0/24",
      address: "10.252.11.1/24",
      alias: "ubuntu-26.04-ct",
      places: 3,
      incus: { line: "7.0", packages },
    },
  };
  Deno.writeTextFileSync(`${folder}/intent.json`, JSON.stringify({ host }));
  return {
    folder,
    run() {
      const done = new Deno.Command("ansible-playbook", {
        args: [
          "-i",
          "localhost,",
          "-c",
          "local",
          "-e",
          `@${folder}/intent.json`,
          "-e",
          "ansible_python_interpreter={{ ansible_playbook_python }}",
          `${folder}/play.yml`,
        ],
        env: {
          HOST_FIXTURE: folder,
          DATASETS: "",
          WRITTEN: written,
          INSTALLED: installed,
          SCTP: sctp,
          PATH: `${folder}/bin:${Deno.env.get("PATH")}`,
          ANSIBLE_NOCOLOR: "1",
          ANSIBLE_HOME: `${folder}/home`,
          ANSIBLE_LOCAL_TEMP: `${folder}/tmp`,
        },
      }).outputSync();
      return {
        ok: done.success,
        output: new TextDecoder().decode(done.stdout) +
          new TextDecoder().decode(done.stderr),
      };
    },
    state: () => JSON.parse(Deno.readTextFileSync(`${folder}/state.json`)),
    calls: () =>
      Deno.readTextFileSync(`${folder}/calls`).trim().split("\n").map((
        line,
      ) => JSON.parse(line) as string[]),
    read: (name: string) => Deno.readTextFileSync(`${folder}/${name}`),
    has: (name: string) => {
      try {
        Deno.statSync(`${folder}/${name}`);
        return true;
      } catch {
        return false;
      }
    },
    cleanup: () => Deno.removeSync(folder, { recursive: true }),
  };
}
