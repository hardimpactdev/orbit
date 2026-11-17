/** Real Gateway DNS tasks with isolated paths and a service-manager boundary; no host units or routes change. */
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
            task[part] = (task[part] as Task[]).map((item) =>
                sandbox(item, folder)
            );
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

function prepareStockRoles(folder: string) {
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
            ...files.map((file) =>
                file.path.slice(0, file.path.lastIndexOf("/"))
            ),
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
        new URL("gateway_dns_systemd.py", import.meta.url),
        `${folder}/library/stock_systemd.py`,
    );
    for (
        const name of [
            "gateway-dns.yml",
            "private-dns-listener.yml",
            "private-dns-withdraw.yml",
        ]
    ) {
        Deno.writeTextFileSync(
            `${folder}/tasks/${name}`,
            JSON.stringify(
                read(`tasks/${name}`).map((task) => sandbox(task, folder)),
            ),
        );
    }
}

function cleanupStockRoles(folder: string) {
    try {
        Deno.kill(
            Number(Deno.readTextFileSync(`${folder}/listener.pid`)),
            "SIGTERM",
        );
    } catch { /* The fixture may fail before starting its listener. */ }
}

async function operation(
    folder: string,
    check = false,
    failAfterDns = false,
    toolPath?: string,
) {
    const dns = JSON.parse(Deno.readTextFileSync(`${folder}/dns-runtime.json`));
    const vars = {
        rt: { gateway_dns: dns },
        intent: { runtime: { gateway_dns: dns } },
    };
    // The Gateway selects the wrapper only for an admitted local action; production runs the original task file alone.
    const wrapper = read("operation-node-role-add-local-dns.yml");
    const tasks: Task[] = [
        ...("listener" in dns || "withdraw" in dns
            ? wrapper[0].tasks as Task[]
            : []),
        {
            name: "Apply the actual Gateway DNS runtime",
            "ansible.builtin.import_tasks": `${folder}/tasks/gateway-dns.yml`,
        },
        ...("listener" in dns || "withdraw" in dns
            ? wrapper[2].tasks as Task[]
            : []),
    ];
    if (failAfterDns) {
        tasks.push({
            name: "Simulate later machine task failure after DNS is proved",
            "ansible.builtin.fail": { msg: "fixture.php_fpm_invalid" },
        });
    }
    Deno.writeTextFileSync(
        `${folder}/role-2.json`,
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
    const done = await new Deno.Command("ansible-playbook", {
        args: [
            "-i",
            "localhost,",
            ...(check ? ["--check"] : []),
            "-e",
            "ansible_python_interpreter={{ ansible_playbook_python }}",
            `${folder}/role-2.json`,
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
    Deno.writeTextFileSync(`${folder}/role-2.output`, text(done));
    Deno.writeTextFileSync(
        `${folder}/role-2.status`,
        done.success ? "succeeded" : "failed",
    );
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
        await operation(folder, check, false, toolPath);
        return {
            status: Deno.readTextFileSync(`${folder}/role-2.status`),
            output: Deno.readTextFileSync(`${folder}/role-2.output`),
            units: [...Deno.readDirSync(`${folder}/units`)].map((entry) =>
                entry.name
            ),
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
        await operation(folder);
        const first = Deno.readTextFileSync(`${folder}/role-2.output`);
        const firstStatus = Deno.readTextFileSync(`${folder}/role-2.status`);
        const pid = Deno.readTextFileSync(`${folder}/listener.pid`);
        await operation(folder, check);
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
        await operation(folder);
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
            await operation(folder, false, installation === "later-failure");
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
        await operation(folder);
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
        await operation(folder, true);
        return {
            status: Deno.readTextFileSync(`${folder}/role-2.status`),
            output: Deno.readTextFileSync(`${folder}/role-2.output`),
        };
    } finally {
        cleanupStockRoles(folder);
        Deno.removeSync(folder, { recursive: true });
    }
}
