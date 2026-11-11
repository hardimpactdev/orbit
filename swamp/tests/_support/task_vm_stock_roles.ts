/** Real role task contracts behind the fleet fixture's Incus boundary; no host units or routes are changed. */
import { parse } from "jsr:@std/yaml@1";

type Task = Record<string, unknown>;
const root = new URL("../../ansible/", import.meta.url);
const gateway = new URL("../../../apps/gateway/", import.meta.url).pathname;
const read = (name: string): Task[] =>
  parse(Deno.readTextFileSync(new URL(name, root))) as Task[];
const text = (result: Deno.CommandOutput) =>
  new TextDecoder().decode(result.stdout) +
  new TextDecoder().decode(result.stderr);

/** Map filesystem ownership and the service-manager boundary only; all task conditions and module calls stay real. */
function sandbox(task: Task, folder: string): Task {
  for (const part of ["block", "always", "rescue"]) {
    if (Array.isArray(task[part])) {
      task[part] = (task[part] as Task[]).map((item) => sandbox(item, folder));
    }
  }
  for (const module of ["ansible.builtin.copy", "ansible.builtin.file"]) {
    const args = task[module] as Task | undefined;
    if (args) {
      delete args.owner;
      delete args.group;
    }
  }
  if (
    task["ansible.builtin.systemd"] ||
    task["ansible.builtin.systemd_service"]
  ) {
    task.stock_systemd = task["ansible.builtin.systemd"] ??
      task["ansible.builtin.systemd_service"];
    delete task["ansible.builtin.systemd"];
    delete task["ansible.builtin.systemd_service"];
  }
  // Only the paths that the real task file writes or reads are redirected. The admitted payload is redirected separately.
  return JSON.parse(
    JSON.stringify(task).replaceAll(
      "/etc/systemd/system",
      `${folder}/units`,
    )
      .replaceAll("/var/lib/orbit/private-dns", `${folder}/private-dns`)
      .replaceAll("/sys/class/net/orbit", `${folder}/orbit-interface`)
      .replaceAll(
        "/etc/wireguard/orbit.dns-link",
        `${folder}/peer-dns-link`,
      )
      .replaceAll("/etc/wireguard", `${folder}/wireguard`),
  );
}

export function stockBaseTasks(): Task[] {
  const plays = read("task-vm-host.yml");
  const base = plays.find((play) => play.name === "Task VM base")!;
  const start = (base.tasks as Task[]).find((task) =>
    task.name === "Start the base VM"
  )!;
  // The existing base creation fixture covers these same three shipped tasks against a stateful Incus API boundary.
  return (start.block as Task[]).slice(0, 3);
}

export function prepareStockRoles(folder: string) {
  Deno.writeTextFileSync(
    `${folder}/base-state.json`,
    JSON.stringify({ vm: null, seed: false, calls: [] }),
  );
  for (
    const name of [
      "tasks",
      "library",
      "units",
      "private-dns",
      "orbit-interface",
      "wireguard",
    ]
  ) Deno.mkdirSync(`${folder}/${name}`, { recursive: true });
  const socket = Deno.listen({ hostname: "127.0.0.1", port: 0 });
  const dnsPort = (socket.addr as Deno.NetAddr).port;
  socket.close();
  const http = Deno.listen({ hostname: "127.0.0.1", port: 0 });
  const httpPort = (http.addr as Deno.NetAddr).port;
  http.close();
  Deno.writeTextFileSync(
    `${folder}/ports.json`,
    JSON.stringify({ dnsPort, httpPort }),
  );
  const release = new Deno.Command("php8.5", {
    args: [
      "-r",
      `require $argv[1].'/app/Infrastructure/AppDev/PrivateDnsListenerRelease.php'; $r = new App\\Infrastructure\\AppDev\\PrivateDnsListenerRelease($argv[1]); echo json_encode(['id'=>$r->id(),'files'=>$r->files()]);`,
      gateway,
    ],
  }).outputSync();
  if (!release.success) throw new Error(text(release));
  const source = JSON.parse(new TextDecoder().decode(release.stdout));
  const releasePath = `${folder}/private-dns/releases/${source.id}`;
  const files = Object.entries(source.files).map(([path, content]) => ({
    path: `${releasePath}/${path}`,
    content,
  }));
  const directories = [
    ...new Set([
      releasePath,
      ...files.map((file) => file.path.slice(0, file.path.lastIndexOf("/"))),
    ]),
  ].sort();
  const catalog = JSON.stringify({
    records: { "gateway.orbit": "10.45.0.1" },
    suffixes: {},
    overrides: {},
    requesters: {},
  });
  const service =
    `[Service]\nExecStart=/usr/bin/php8.5 ${releasePath}/serve.php --listen=127.0.0.1 --port=${dnsPort} --catalog=${folder}/private-dns/catalog.json --upstream=127.0.0.55:53\n`;
  Deno.writeTextFileSync(
    `${folder}/dns-runtime.json`,
    JSON.stringify({
      unit:
        "[Service]\nType=oneshot\nExecStart=/usr/bin/resolvectl domain orbit ~orbit\nExecStart=/usr/bin/resolvectl default-route orbit false\nExecStart=/usr/bin/resolvectl dns orbit 127.0.0.1\n",
      listener: {
        release: releasePath,
        directories,
        files,
        service,
        socket:
          `[Socket]\nListenDatagram=127.0.0.1:${dnsPort}\nListenStream=127.0.0.1:${dnsPort}\n`,
        address: "10.45.0.1",
        records: "host-record=gateway.orbit,10.45.0.1\n",
        catalog,
        catalog_sha256: new TextDecoder().decode(
          new Deno.Command("php8.5", {
            args: ["-r", "echo hash('sha256', $argv[1]);", catalog],
          }).outputSync().stdout,
        ),
      },
    }),
  );
  // The shell wrapper keeps the real dig implementation, directed at the unprivileged fixture listener.
  Deno.writeTextFileSync(
    `${folder}/bin/dig`,
    `#!/bin/sh\nif [ "$1" = @10.45.0.1 ]; then shift; set -- @127.0.0.1 "$@"; fi\nexec /usr/bin/dig -p ${dnsPort} "$@"\n`,
  );
  Deno.chmodSync(`${folder}/bin/dig`, 0o755);
  Deno.writeTextFileSync(
    `${folder}/bin/resolvectl`,
    `#!/bin/sh\ncase "$1" in\n domain) echo 'Link 2 (orbit): ~orbit';;\n default-route) echo 'Link 2 (orbit): no';;\n dns) printf 'Link 2 (orbit): '; if [ -f '${folder}/resolver-address' ]; then cat '${folder}/resolver-address'; else echo 127.0.0.1; fi;;\nesac\n`,
  );
  Deno.chmodSync(`${folder}/bin/resolvectl`, 0o755);
  Deno.copyFileSync(
    new URL("task_vm_stock_systemd.py", import.meta.url),
    `${folder}/library/stock_systemd.py`,
  );
  for (const entry of Deno.readDirSync(new URL("tasks/", root))) {
    if (entry.name.endsWith(".yml")) {
      if (
        [
          "gateway-dns.yml",
          "private-dns-listener.yml",
          "private-dns-withdraw.yml",
          "task-vm-fleet-operator.yml",
        ].includes(entry.name)
      ) {
        Deno.writeTextFileSync(
          `${folder}/tasks/${entry.name}`,
          JSON.stringify(
            read(`tasks/${entry.name}`).map((task) => sandbox(task, folder)),
          ),
        );
      } else {Deno.copyFileSync(
          new URL(`tasks/${entry.name}`, root),
          `${folder}/tasks/${entry.name}`,
        );}
    }
  }
}

export function cleanupStockRoles(folder: string) {
  try {
    Deno.kill(
      Number(Deno.readTextFileSync(`${folder}/listener.pid`)),
      "SIGTERM",
    );
  } catch { /* The fixture may fail before starting its listener. */ }
}

async function operation(
  folder: string,
  id: number,
  check = false,
  failAfterDns = false,
  toolPath?: string,
) {
  const recipe = JSON.parse(
    Deno.readTextFileSync(
      `${gateway}/resources/machine-software/recipe.json`,
    ),
  );
  const roles = id === 4
    ? ["app-dev"]
    : id === 5
    ? ["app-prod"]
    : ["gateway", "vpn"];
  const intent = {
    cli: null,
    selected_roles: roles,
    tools: [],
    agent: {
      url: "https://fixtures.invalid/agent",
      sha256: "a".repeat(64),
    },
  };
  const vars = {
    intent,
    recipe,
    secrets: { agent_verifier: "b".repeat(64) },
    ansible_facts: {
      distribution: "Ubuntu",
      distribution_version: recipe.ubuntu.release,
      architecture: "x86_64",
    },
  };
  const converge = (parse(
    Deno.readTextFileSync(new URL("operation-node-converge.yml", root)),
  ) as { tasks: Task[] }[])[1];
  let tasks: Task[] = [];
  let server: Deno.HttpServer | undefined;
  if (id === 1) {
    tasks = converge.tasks.filter((task) =>
      [
        "Require the pinned Linux platform",
        "Require the recorded CLI pin only for a CLI caller",
      ].includes(task.name as string)
    );
    tasks.push({
      name: "Select actual Gateway software",
      "ansible.builtin.import_tasks":
        `${folder}/tasks/node-converge-software-plan.yml`,
    }, {
      name: "Require the Gateway software contract",
      "ansible.builtin.assert": {
        that: ["php_versions == ['8.5']", "'caddy' in tool_by_name"],
      },
    });
  } else if (id === 2) {
    const dns = JSON.parse(
      Deno.readTextFileSync(`${folder}/dns-runtime.json`),
    );
    Object.assign(vars, {
      rt: { gateway_dns: dns },
      intent: { ...intent, runtime: { gateway_dns: dns } },
    });
    // The Gateway selects the wrapper only for an admitted local action; production runs the original task file alone.
    const wrapper = read("operation-node-role-add-local-dns.yml");
    tasks = [
      ...("listener" in dns || "withdraw" in dns
        ? (wrapper[0].tasks as Task[])
        : []),
      {
        name: "Apply the actual Gateway DNS runtime",
        "ansible.builtin.import_tasks": `${folder}/tasks/gateway-dns.yml`,
      },
      ...("listener" in dns || "withdraw" in dns
        ? (wrapper[2].tasks as Task[])
        : []),
    ];
  } else if (id === 3) {
    const { dnsPort, httpPort } = JSON.parse(
      Deno.readTextFileSync(`${folder}/ports.json`),
    );
    const answer = await new Deno.Command("/usr/bin/dig", {
      args: [
        "+time=1",
        "+tries=1",
        "+short",
        "@127.0.0.1",
        "-p",
        String(dnsPort),
        "gateway.orbit",
      ],
    }).output();
    if (
      answer.success &&
      new TextDecoder().decode(answer.stdout).trim() === "10.45.0.1"
    ) {
      // This is a registry-DNS dependency probe, not the external Reverb application. A missing listener leaves the port closed,
      // like Reverb waiting to load https://gateway.orbit/api/v1/websocket-registry before it opens its own listener.
      server = Deno.serve({
        hostname: "127.0.0.1",
        port: httpPort,
        onListen() {},
      }, () => new Response("registry DNS available"));
    }
    Object.assign(vars, {
      rt: { websocket: { health_url: `http://127.0.0.1:${httpPort}/` } },
    });
    tasks = read("tasks/node-role-runtime.yml").filter((task) =>
      task.name === "Wait for Reverb"
    );
    // One immediate retry is enough for this dependency fixture; production's bounded 60-second readiness wait stays unchanged.
    tasks[0].retries = 0;
    tasks[0].delay = 0;
  } else {
    tasks = [{
      name: "Select actual app software",
      "ansible.builtin.import_tasks":
        `${folder}/tasks/node-converge-software-plan.yml`,
    }, {
      name: "Require the app runtime contract",
      "ansible.builtin.assert": {
        that: id === 4
          ? [
            "php_versions | sort == ['8.4', '8.5']",
            "'vp' in tool_by_name",
            "'caddy' in tool_by_name",
          ]
          : [
            "php_versions == ['8.5']",
            "'chrome-headless-shell' in tool_by_name",
            "'caddy' in tool_by_name",
          ],
      },
    }];
  }
  if (failAfterDns) {
    tasks.push({
      name: "Simulate later machine task failure after DNS is proved",
      "ansible.builtin.fail": { msg: "fixture.php_fpm_invalid" },
    });
  }
  Deno.writeTextFileSync(
    `${folder}/role-${id}.json`,
    JSON.stringify([{
      hosts: "localhost",
      connection: "local",
      gather_facts: false,
      ...(toolPath ? { environment: { PATH: toolPath } } : {}),
      vars,
      tasks,
      handlers: [{
        name: "Reload units",
        stock_systemd: { daemon_reload: true },
      }],
    }]),
  );
  try {
    const done = await new Deno.Command("ansible-playbook", {
      args: [
        "-i",
        "localhost,",
        ...(check ? ["--check"] : []),
        "-e",
        "ansible_python_interpreter={{ ansible_playbook_python }}",
        `${folder}/role-${id}.json`,
      ],
      env: {
        PATH: `${folder}/bin:${Deno.env.get("PATH")}`,
        STOCK_FIXTURE: folder,
        ANSIBLE_NOCOLOR: "1",
        ANSIBLE_FORKS: "4",
        ANSIBLE_STDOUT_CALLBACK: "default",
        ANSIBLE_LIBRARY: `${folder}/library`,
      },
      stdout: "piped",
      stderr: "piped",
    }).output();
    Deno.writeTextFileSync(`${folder}/role-${id}.output`, text(done));
    Deno.writeTextFileSync(
      `${folder}/role-${id}.status`,
      done.success ? "succeeded" : "failed",
    );
  } finally {
    await server?.shutdown();
  }
}

export async function missingGatewayDnsTool(check = false) {
  const folder = Deno.makeTempDirSync();
  try {
    Deno.mkdirSync(`${folder}/bin`);
    prepareStockRoles(folder);
    // Give the whole play a real tool PATH without dig. No production task name or added guard is needed.
    const toolPath = `${folder}/without-dig`;
    Deno.mkdirSync(toolPath);
    for (const name of ["timeout", "cat"]) {
      Deno.symlinkSync(`/usr/bin/${name}`, `${toolPath}/${name}`);
    }
    Deno.symlinkSync(`${folder}/bin/resolvectl`, `${toolPath}/resolvectl`);
    await operation(folder, 2, check, false, toolPath);
    return {
      status: Deno.readTextFileSync(`${folder}/role-2.status`),
      output: Deno.readTextFileSync(`${folder}/role-2.output`),
      units: [...Deno.readDirSync(`${folder}/units`)].map((entry) => entry.name),
    };
  } finally {
    cleanupStockRoles(folder);
    Deno.removeSync(folder, { recursive: true });
  }
}

export async function existingGatewayDns(check = false) {
  const folder = Deno.makeTempDirSync();
  try {
    Deno.mkdirSync(`${folder}/bin`);
    prepareStockRoles(folder);
    // An isolated Gateway can retain a peer policy too; the sentinel still suppresses resolver policy reads and writes.
    Deno.writeTextFileSync(`${folder}/peer-dns-link`, "10.44.0.1\n");
    await operation(folder, 2);
    const first = Deno.readTextFileSync(`${folder}/role-2.output`);
    const firstStatus = Deno.readTextFileSync(`${folder}/role-2.status`);
    const pid = Deno.readTextFileSync(`${folder}/listener.pid`);
    await operation(folder, 2, check);
    return {
      first,
      firstStatus,
      second: Deno.readTextFileSync(`${folder}/role-2.output`),
      secondStatus: Deno.readTextFileSync(`${folder}/role-2.status`),
      samePid: pid === Deno.readTextFileSync(`${folder}/listener.pid`),
      calls: Deno.readTextFileSync(`${folder}/systemd.jsonl`).trim()
        .split("\n")
        .map((line) => JSON.parse(line)),
    };
  } finally {
    cleanupStockRoles(folder);
    Deno.removeSync(folder, { recursive: true });
  }
}

if (import.meta.main) await operation(Deno.args[0], Number(Deno.args[1]));

export async function productionGatewayDns() {
  const folder = Deno.makeTempDirSync();
  try {
    Deno.mkdirSync(`${folder}/bin`);
    prepareStockRoles(folder);
    const golden = JSON.parse(Deno.readTextFileSync(
      new URL(
        "../../../apps/gateway/tests/Fixtures/production-gateway-converge-main.json",
        import.meta.url,
      ),
    ));
    const runtime = golden.output.gateway_dns;
    Deno.writeTextFileSync(
      `${folder}/dns-runtime.json`,
      JSON.stringify(runtime),
    );
    Deno.writeTextFileSync(
      `${folder}/units/orbit-gateway-dns.service`,
      runtime.unit,
    );
    Deno.writeTextFileSync(`${folder}/peer-dns-link`, "10.44.0.1\n");
    Deno.removeSync(`${folder}/private-dns`, { recursive: true });
    await operation(folder, 2);
    const exists = (path: string) => {
      try {
        Deno.statSync(path);
        return true;
      } catch {
        return false;
      }
    };
    return {
      status: Deno.readTextFileSync(`${folder}/role-2.status`),
      output: Deno.readTextFileSync(`${folder}/role-2.output`),
      dnsDirectory: exists(`${folder}/private-dns`),
      listener: exists(`${folder}/units/orbit-private-dns.service`),
      socket: exists(`${folder}/units/orbit-private-dns.socket`),
      calls: Deno.readTextFileSync(`${folder}/systemd.jsonl`).trim()
        .split("\n")
        .map((line) => JSON.parse(line)),
    };
  } finally {
    cleanupStockRoles(folder);
    Deno.removeSync(folder, { recursive: true });
  }
}

export async function withdrawGatewayDns(
  staleLocalResolver = false,
  installation = "installed",
) {
  const folder = Deno.makeTempDirSync();
  try {
    Deno.mkdirSync(`${folder}/bin`);
    prepareStockRoles(folder);
    if (installation !== "not-started") {
      await operation(folder, 2, false, installation === "later-failure");
    }
    const first = installation === "not-started"
      ? ""
      : Deno.readTextFileSync(`${folder}/role-2.output`);
    const firstStatus = installation === "not-started"
      ? "not-started"
      : Deno.readTextFileSync(`${folder}/role-2.status`);
    const installed = (() => {
      try {
        Deno.statSync(`${folder}/listener.pid`);
        return true;
      } catch {
        return false;
      }
    })();
    const runtime = JSON.parse(
      Deno.readTextFileSync(`${folder}/dns-runtime.json`),
    );
    const resolver = staleLocalResolver ? "10.45.0.1" : "10.44.0.1";
    runtime.unit = runtime.unit.replaceAll("127.0.0.1", resolver);
    delete runtime.listener;
    runtime.withdraw = {
      resolver_address: resolver,
      local_address: "10.45.0.1",
      units: ["orbit-private-dns.socket", "orbit-private-dns.service"],
      files: [
        `${folder}/units/orbit-private-dns.socket`,
        `${folder}/units/orbit-private-dns.service`,
        `${folder}/private-dns/catalog.json`,
        `${folder}/private-dns/catalog.json.loaded`,
      ],
      releases: `${folder}/private-dns/releases`,
    };
    Deno.writeTextFileSync(
      `${folder}/dns-runtime.json`,
      JSON.stringify(runtime),
    );
    await operation(folder, 2);
    const exists = (path: string) => {
      try {
        Deno.statSync(path);
        return true;
      } catch {
        return false;
      }
    };
    return {
      first,
      firstStatus,
      installed,
      status: Deno.readTextFileSync(`${folder}/role-2.status`),
      output: Deno.readTextFileSync(`${folder}/role-2.output`),
      retained: runtime.withdraw.files.filter(exists),
      running: exists(`${folder}/listener.pid`),
      calls: Deno.readTextFileSync(`${folder}/systemd.jsonl`).trim()
        .split("\n")
        .map((line) => JSON.parse(line)),
    };
  } finally {
    cleanupStockRoles(folder);
    Deno.removeSync(folder, { recursive: true });
  }
}

export async function freshGatewayDnsCheck() {
  const folder = Deno.makeTempDirSync();
  try {
    Deno.mkdirSync(`${folder}/bin`);
    prepareStockRoles(folder);
    await operation(folder, 2, true);
    return {
      status: Deno.readTextFileSync(`${folder}/role-2.status`),
      output: Deno.readTextFileSync(`${folder}/role-2.output`),
    };
  } finally {
    cleanupStockRoles(folder);
    Deno.removeSync(folder, { recursive: true });
  }
}
