/** Run the shipped Instance container host phase against a stateful Incus boundary: folders, image, reruns and updates. */
import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { stockImageDaemon, stockImageTask } from "./stock_image.ts";

export const hasAnsible =
    new Deno.Command("sh", { args: ["-c", "command -v ansible-playbook"] })
        .outputSync().success;
const root = new URL("../../ansible/", import.meta.url);
export const GiB = 1073741824;

type Task = Record<string, unknown>;

/**
 * The task files as an unprivileged user can run them here, written beside the play so each include finds its fixture copy.
 * Package, kernel-module, sysctl and firewall modules need Linux and root: they become files in the fixture folder.
 */
function transform(task: Task, folder: string): Task {
    stockImageTask(task, folder);
    for (const part of ["block", "rescue", "always"]) {
        if (Array.isArray(task[part])) {
            task[part] = (task[part] as Task[]).map((inner) =>
                transform(inner, folder)
            );
        }
    }
    if ("community.general.ufw" in task) {
        task.vars = {
            ...(task.vars as object ?? {}),
            fixture_rule: task["community.general.ufw"],
        };
        const remove = (task["community.general.ufw"] as Task).delete === true;
        delete task["community.general.ufw"];
        const dest =
            `${folder}/firewall-{{ fixture_rule.comment | regex_replace('[^a-z-]', '_') }}.json`;
        if (remove) {
            task["ansible.builtin.file"] = { path: dest, state: "absent" };
        } else {
            task["ansible.builtin.copy"] = {
                dest,
                content: "{{ fixture_rule | to_json }}",
                mode: "0600",
            };
        }
    }
    if ("ansible.posix.sysctl" in task) {
        task.vars = {
            ...(task.vars as object ?? {}),
            fixture_sysctl: task["ansible.posix.sysctl"],
        };
        delete task["ansible.posix.sysctl"];
        task["ansible.builtin.copy"] = {
            dest: `${folder}/sysctl/{{ fixture_sysctl.name }}`,
            content:
                "{{ fixture_sysctl.value }} {{ fixture_sysctl.sysctl_file }}",
            mode: "0600",
        };
    }
    for (
        const module of [
            "ansible.builtin.apt",
            "community.general.modprobe",
            "ansible.builtin.systemd_service",
        ]
    ) {
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
    // Ownership needs root; the modes stay.
    const command = task["ansible.builtin.command"] as Task | undefined;
    if (task.name === "Set the folder owners and modes" && command) {
        command.argv = "{{ item if item[0] != 'chown' else ['true'] }}";
    }
    return task;
}

const replacements = (folder: string): [string, string][] => [
    ["/usr/local/libexec", `${folder}/libexec`],
    ["/var/lib/orbit/instance-bridge", `${folder}/bridge-policy`],
    ["/var/lib/orbit/instance-container-libraries", `${folder}/container-libraries`],
    // The Node's own Orbit root CA, a real self-signed certificate of the fixture; the container path stays as it is.
    ['"instance_trust_source":"/etc/orbit/agent/ca.pem"', `"instance_trust_source":"${folder}/orbit-root-ca.crt"`],
    ["/var/lib/orbit/instance-nested-image.json", `${folder}/nested-image.json`],
    ["/etc/modprobe.d/orbit-instances-zfs.conf", `${folder}/arc.conf`],
    ["/etc/systemd/system/", `${folder}/systemd/`],
    ["/sys/module/zfs/parameters/zfs_arc_max", `${folder}/arc`],
    ["/etc/subuid", `${folder}/subuid`],
    ["/etc/subgid", `${folder}/subgid`],
    ["/proc/meminfo", `${folder}/meminfo`],
    ["/sys/module/sctp", `${folder}/sys/sctp`],
    ["/var/cache/orbit/debs", `${folder}/debs`],
    ["/var/cache/orbit/instance-folders", `${folder}/cache`],
    ["/etc/modules-load.d/", `${folder}/modules-load/`],
    ["/etc/orbit/instance-dev-ports.nft", `${folder}/dev-ports.nft`],
    ['"/etc/orbit"', `"${folder}/etc-orbit"`],
    ["cat /proc/[0-9]*/maps", `cat ${folder}/maps`],
];

function writeTasks(folder: string) {
    Deno.mkdirSync(`${folder}/tasks`, { recursive: true });
    Deno.copyFileSync(new URL("instance_bridge.py", root), `${folder}/instance_bridge.py`);
    Deno.copyFileSync(new URL("instance_network.py", root), `${folder}/instance_network.py`);
    Deno.copyFileSync(new URL("instance-nic-filters.json", root), `${folder}/instance-nic-filters.json`);
    Deno.copyFileSync(new URL("instance_nested.py", root), `${folder}/instance_nested.py`);
    Deno.copyFileSync(new URL("instance_folder_debs.py", root), `${folder}/instance_folder_debs.py`);
    Deno.copyFileSync(new URL("instance_folder_same.py", root), `${folder}/instance_folder_same.py`);
    Deno.copyFileSync(new URL("instance_container_files.py", root), `${folder}/instance_container_files.py`);
    Deno.mkdirSync(`${folder}/files`, { recursive: true });
    Deno.copyFileSync(new URL("files/instance-container-wake.sh", root), `${folder}/files/instance-container-wake.sh`);
    for (
        const name of [
            "instance-host.yml",
            "instance-network-check.yml",
            "instance-network-nic-check.yml",
            "instance-network-move.yml",
            "instance-network-move-nic.yml",
            "instance-bridge-rules.yml",
            "instance-bridge-refresh.yml",
            "instance-egress.yml",
            "instance-folder.yml",
            "instance-container-files.yml",
            "instance-vite-plus-home.yml",
            "instance-image.yml",
            "artifact-fetch.yml",
            "task-container-host-system.yml",
            "task-container-host-ids.yml",
            "task-container-host-prepare.yml",
        ]
    ) {
        const tasks = (parse(
            Deno.readTextFileSync(new URL(`tasks/${name}`, root)),
        ) as Task[]).map((task) => transform(structuredClone(task), folder));
        let text = JSON.stringify(tasks);
        for (const [from, to] of replacements(folder)) {
            text = text.replaceAll(from, to);
        }
        Deno.writeTextFileSync(
            `${folder}/tasks/${name}`,
            // JSON preserves literal "=" arguments without YAML 1.1 scalar tags.
            text,
        );
    }
    Deno.copyFileSync(
        new URL("transport-exit-codes.json", root),
        `${folder}/transport-exit-codes.json`,
    );
}

const sha256 = async (path: string) =>
    Array.from(
        new Uint8Array(
            await crypto.subtle.digest("SHA-256", Deno.readFileSync(path)),
        ),
    ).map((b) => b.toString(16).padStart(2, "0")).join("");

/** A tarball with one program that prints its version, and a single-file artifact. */
async function artifacts(folder: string, version: string) {
    const work = Deno.makeTempDirSync();
    Deno.mkdirSync(`${work}/tool-${version}`);
    Deno.writeTextFileSync(
        `${work}/tool-${version}/tool`,
        `#!/bin/sh\necho tool ${version}\n`,
    );
    Deno.chmodSync(`${work}/tool-${version}/tool`, 0o755);
    new Deno.Command("tar", {
        args: [
            "-czf",
            `${folder}/artifacts/tool-${version}.tar.gz`,
            "-C",
            work,
            `tool-${version}`,
        ],
    }).outputSync();
    Deno.writeTextFileSync(
        `${folder}/artifacts/single-1.0.phar`,
        "#!/bin/sh\necho single\n",
    );
    Deno.mkdirSync(`${work}/package`);
    Deno.writeTextFileSync(`${work}/package/claude`, "#!/bin/sh\necho 2.0\n");
    Deno.chmodSync(`${work}/package/claude`, 0o755);
    new Deno.Command("tar", {
        args: [
            "-czf",
            `${folder}/artifacts/claude-2.0.tgz`,
            "-C",
            work,
            "package",
        ],
    }).outputSync();
    // The trust module's package as Ubuntu ships it: the trust and client modules, and a proxy link to the library it needs. Built
    // once per fixture, so every version of the parts pins the same bytes.
    const deb = `${folder}/artifacts/p11-kit-modules_0.26.2-2_amd64.deb`;
    if (!new Deno.Command("test", { args: ["-f", deb] }).outputSync().success) {
        const p11 = `${work}/p11-kit-modules`;
        Deno.mkdirSync(`${p11}/DEBIAN`, { recursive: true });
        Deno.writeTextFileSync(`${p11}/DEBIAN/control`, "Package: p11-kit-modules\nVersion: 0.26.2-2\nArchitecture: amd64\nMaintainer: fixture\nDescription: fixture\n");
        Deno.mkdirSync(`${p11}/usr/lib/x86_64-linux-gnu/pkcs11`, { recursive: true });
        Deno.writeTextFileSync(`${p11}/usr/lib/x86_64-linux-gnu/pkcs11/p11-kit-trust.so`, "trust module");
        Deno.writeTextFileSync(`${p11}/usr/lib/x86_64-linux-gnu/pkcs11/p11-kit-client.so`, "client module");
        Deno.symlinkSync("libp11-kit.so.0.4.8", `${p11}/usr/lib/x86_64-linux-gnu/p11-kit-proxy.so`);
        const built = new Deno.Command("dpkg-deb", { args: ["--root-owner-group", "-b", p11, deb] }).outputSync();
        assert(built.success, new TextDecoder().decode(built.stderr));
    }
    Deno.removeSync(work, { recursive: true });
    return {
        runtime: [{
            name: "tool",
            version,
            url: `https://example.test/tool-${version}.tar.gz`,
            archive: "tar",
            sha256: await sha256(`${folder}/artifacts/tool-${version}.tar.gz`),
            bin: { tool: `tool-${version}/tool` },
        }, {
            name: "single",
            version: "1.0",
            url: "https://example.test/download/1.0/single-1.0.phar",
            archive: "file",
            sha256: await sha256(`${folder}/artifacts/single-1.0.phar`),
            bin: { single: "single-1.0.phar" },
        }, {
            name: "browser-p11-kit-modules",
            version: "0.26.2-2",
            url: "https://example.test/pool/p11-kit-modules_0.26.2-2_amd64.deb",
            archive: "deb",
            sha256: await sha256(deb),
            bin: {},
            trust_module: { source: "usr/lib/x86_64-linux-gnu/pkcs11/p11-kit-trust.so", path: "pkcs11/p11-kit-trust.so", library: "libp11-kit0" },
        }],
        agent: [{
            name: "claude-code",
            version: "2.0",
            url: "https://example.test/claude-2.0.tgz",
            archive: "tar",
            sha256: await sha256(`${folder}/artifacts/claude-2.0.tgz`),
            bin: { claude: "package/claude" },
        }],
    };
}

/**
 * The image's setup script and finish commands, read from the Gateway's source, so the build container of the fixture runs the
 * real ones against the accounts and the machine-id of a booted stock image.
 */
function gatewayImage() {
    const source = Deno.readTextFileSync(
        new URL(
            "../../../apps/gateway/app/Domain/Instances/InstanceImage.php",
            import.meta.url,
        ),
    );
    const values: Record<string, string> = {
        agent: "2750",
        orbit: "1000",
        work: "2760",
        workspace: "/srv/workspace",
        vite: "/var/lib/orbit/vite-plus-home",
        sharedVite: "/opt/orbit-runtime/current/vite-plus-home",
    };
    const prepare = source.match(/return <<<SH\n([\s\S]*?\n)\s*SH;/)![1]
        .split("\n").map((line) => line.trim()).join("\n")
        .replace(/\{\$(\w+)\}/g, (_, name) => values[name]);
    const finish = source.match(
        /'finish' => \[\n([\s\S]*?)\n\s*\],\n\s*\];/,
    )![1]
        .split("\n").map((line) => line.trim()).filter((line) =>
            line.startsWith("[")
        ).map((line) =>
            [...line.matchAll(/'((?:[^'\\]|\\.)*)'/g)].map((match) => match[1])
        );
    assert(!prepare.includes("$agent") && finish.length > 0);
    return { prepare, finish };
}

/** `agent`'s NSS store as InstanceImage::browserTrust gives it. */
export const browserTrust = {
    home: "/home/agent",
    directories: ["/home/agent/.pki", "/home/agent/.pki/nssdb"],
    path: "/home/agent/.pki/nssdb/pkcs11.txt",
    entry: "library=/opt/orbit-runtime/current/pkcs11/p11-kit-trust.so\nname=Orbit system trust\n\n",
    uid: 2750,
    gid: 2750,
    mode: "0600",
    directory_mode: "0700",
    library: "libp11-kit0",
    version: "0.26.2-2",
};

type Rule = {
    action: string;
    state: string;
    protocol?: string;
    source?: string;
    destination_port?: string;
};

/**
 * Whether a new TCP connection that the Node sends or forwards to a container passes the bridge ACL. Incus checks that ACL before
 * the container's own: an enabled allow rule of the ACL's ingress admits it, and anything else gets the bridge's default action.
 */
export function bridgeAdmits(
    state: Record<string, any>,
    packet: { source: string; port: number },
) {
    const bridge = state.networks.find((n: { name: string }) =>
        n.name === "orbitinst0"
    );
    const acl = state["network-acls"].find((a: { name: string }) =>
        a.name === bridge.config["security.acls"]
    );
    const rule = (acl.ingress as Rule[]).find((rule) => {
        const [first, last = first] = (rule.destination_port ?? "0-65535")
            .split(
                "-",
            ).map(Number);
        return rule.state === "enabled" && (rule.protocol ?? "tcp") === "tcp" &&
            (rule.source === undefined ||
                rule.source === `${packet.source}/32`) &&
            first <= packet.port && packet.port <= last;
    });
    return (rule?.action ??
        bridge.config["security.acls.default.ingress.action"]) === "allow";
}

/**
 * Whether a container's DNS query to the bridge address gets an answer. Incus admits DNS there ahead of the ACL, and the bridge's
 * dnsmasq answers every name the Node resolves, unless `port=0` turns its DNS off.
 */
export const bridgeAnswersDns = (state: Record<string, any>) =>
    !(state.networks.find((n: { name: string }) => n.name === "orbitinst0")
        .config["raw.dnsmasq"] ?? "").split("\n").includes("port=0");

/** The Node's Caddy, which connects from the bridge address, and a WireGuard peer whose connection to a published T3 the Node forwards. */
export const caddy = "10.253.0.1";
export const peer = "10.44.0.20";

// The gate supplies one wall-clock deadline across Deno's separate file isolates.
// A developer running one file gets twelve minutes from this import.
const fixtureDeadline = Number(
    Deno.env.get("ORBIT_INSTANCE_HOST_DEADLINE_MS") ?? Date.now() + 720_000,
);

/** `ip -4 -o addr show` of a Node with a CI bridge in the next /24 and the Instance bridge itself. */
export const nodeAddresses = [
    "1: lo    inet 127.0.0.1/8 scope host lo\\       valid_lft forever preferred_lft forever",
    "3: enp10s0    inet 192.168.6.20/24 brd 192.168.6.255 scope global enp10s0\\       valid_lft forever preferred_lft forever",
    "6: orbitci0    inet 10.253.1.1/24 scope global orbitci0\\       valid_lft forever preferred_lft forever",
    "8: tun0    inet 10.99.0.2 peer 10.99.0.1/32 scope global tun0\\       valid_lft forever preferred_lft forever",
    "7: orbitinst0    inet 10.253.0.1/24 scope global orbitinst0\\       valid_lft forever preferred_lft forever",
];

export async function fixture(
    { pool = "zfs", addresses = nodeAddresses, nested = false }: {
        pool?: string | null;
        addresses?: string[];
        /** A switched-on Node: the nested-fleet member of the host intent, with its project and image. */
        nested?: boolean;
    } = {},
) {
    const folder = Deno.makeTempDirSync();
    for (
        const dir of [
            "bin",
            "sysctl",
            "debs",
            "sys",
            "artifacts",
            "cache",
            "modules-load",
            "systemd",
        ]
    ) {
        Deno.mkdirSync(`${folder}/${dir}`);
    }
    Deno.writeTextFileSync(`${folder}/arc`, "0\n");
    const nodeRoot = new Deno.Command("openssl", { args: ["req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "2", "-subj", "/CN=Orbit Fixture Root",
        "-keyout", `${folder}/orbit-root-ca.key`, "-out", `${folder}/orbit-root-ca.crt`] }).outputSync();
    assert(nodeRoot.success, new TextDecoder().decode(nodeRoot.stderr));
    Deno.writeTextFileSync(`${folder}/ip-addr`, addresses.join("\n") + "\n");
    Deno.writeTextFileSync(
        `${folder}/meminfo`,
        `MemTotal:       ${32 * GiB / 1024} kB\n`,
    );
    for (const ids of ["subuid", "subgid"]) {
        Deno.writeTextFileSync(`${folder}/${ids}`, "root:1000000:1000000000\n");
    }
    Deno.writeTextFileSync(
        `${folder}/state.json`,
        JSON.stringify({
            "storage-pools": pool === null
                ? []
                : [{ name: "orbit-instances", driver: pool, config: {} }],
            projects: [],
            networks: [],
            "network-acls": [],
            profiles: {},
            images: [],
            instances: [],
            server_version: "7.0.1",
            zfs_pool_name: "fast/orbit-instances",
        }),
    );
    const stopDaemon = stockImageDaemon(folder);
    const scripts: Record<string, string> = {
        incus: `exec '${Deno.execPath()}' run -A '${
            new URL("../fixtures/task-vm-host-incus.ts", import.meta.url)
                .pathname
        }' "$@"`,
        zfs: 'printf "%s\\n" 307200',
        ip: `cat '${folder}/ip-addr'`,
        "dpkg-query": 'printf "%s" "installed"',
        modprobe: 'printf "install /bin/false \\n"',
        sysctl: 'printf "16384\\n128\\n65536\\n200\\n20000\\n1048576\\n"',
        // No Node group holds gid 2750.
        getent: "exit 2",
        // The egress table and Squid configuration validate; the real-kernel check and the live check prove them.
        // It also records each call, so the live check of the development ports table shows.
        nft: `echo "$*" >> '${folder}/nft-calls'; exit 0`,
        // A staged configuration that holds BAD does not parse.
        squid: '! grep -qs BAD "$4"',
        systemctl: 'for unit; do [ "$unit" = is-active ] || echo active; done',
        ss: 'printf "LISTEN 0 256 10.253.0.1:3128 0.0.0.0:*\\n"',
        // The pinned artifacts come from the fixture folder, by the URL's file name.
        curl:
            `for a; do if [ "$a" = --unix-socket ]; then exec '${Deno.execPath()}' run -A '${new URL("../fixtures/instance-bridge-curl.ts", import.meta.url).pathname}' "$@"; fi; done
out=; for a; do case "$prev" in --output) out=$a;; esac; prev=$a; done
cp "${folder}/artifacts/$(basename "$prev")" "$out"`,
    };
    for (const [name, body] of Object.entries(scripts)) {
        Deno.writeTextFileSync(`${folder}/bin/${name}`, `#!/bin/sh\n${body}\n`);
        Deno.chmodSync(`${folder}/bin/${name}`, 0o755);
    }
    writeTasks(folder);
    Deno.writeTextFileSync(
        `${folder}/play.yml`,
        stringify([{
            name: "Prepare the Instance container host",
            hosts: "localhost",
            gather_facts: false,
            tasks: [{
                name: "Prepare the Instance container host",
                "ansible.builtin.include_tasks": "tasks/instance-host.yml",
            }],
        }]),
    );
    const parts = await artifacts(folder, "1.0");
    const instances = {
        project: "orbit-instances",
        network: "orbitinst0",
    nic_acl: "orbitinst0-proxy",
        pool: "orbit-instances",
        cidr: "10.253.0.0/24",
        bridge_admitted_at: 1800000000000000,
        bridge_endpoints: [] as {host: string; port: number; docker: boolean}[],
        address: "10.253.0.1/24",
        build_address: "10.253.0.254",
        dhcp_range: "10.253.0.2-10.253.0.253",
        stock: "ubuntu-26.04-instance-stock",
        forwarder_port: 8319,
        ingress: [{
            action: "allow",
            source: "10.253.0.1/32",
            protocol: "tcp",
            destination_port: "1024-65535",
            state: "enabled",
        }, {
            action: "allow",
            protocol: "tcp",
            destination_port: "3773",
            state: "enabled",
        }],
        egress: {
            port: 3128,
            user: "proxy",
            directory: `${folder}/egress`,
            config: `${folder}/egress/orbit-instance-egress.conf`,
            unit: "orbit-instance-egress.service",
            table_unit: "orbit-instance-egress-table.service",
            table: "orbit_instance_egress",
            origin: "http://10.253.0.1:3128",
            squid: "http_port 10.253.0.1:3128\n",
            ruleset: "table inet orbit_instance_egress\n",
            rule: {
                comment: "orbit:instances-egress",
                action: "allow",
                direction: "in",
                source: "any",
                destination: "10.253.0.1",
                port: "3128",
                protocol: "tcp",
                in_interface: "orbitinst0",
                out_interface: null,
                family: "v4",
            },
        },
        dev_ports: {
            table: "orbit_instance_dev_ports",
            unit: "orbit-instance-dev-ports.service",
            ruleset:
                "table inet orbit_instance_dev_ports\ndelete table inet orbit_instance_dev_ports\ntable inet orbit_instance_dev_ports {}\n",
        },
        own_site: {
            acl: ["443", "444"].map((port) => ({
                action: "allow",
                destination: "10.253.0.1/32",
                protocol: "tcp",
                destination_port: port,
                state: "enabled",
            })),
            rules: [["443", "orbit:instances-own-site"], ["444", "orbit:instances-own-dev"]].map(([port, comment]) => ({
                comment,
                action: "allow",
                direction: "in",
                source: "any",
                destination: "10.253.0.1",
                port,
                protocol: "tcp",
                in_interface: "orbitinst0",
                out_interface: null,
                family: "v4",
            })),
        },
        ids: 201 * 65536,
        profile_config: {
            "environment.VP_HOME": "/var/lib/orbit/vite-plus-home",
            "environment.VP_NO_UPDATE_CHECK": "1",
            "environment.HTTPS_PROXY": "http://10.253.0.1:3128",
        },
        incus: { line: "7.0", packages: [] },
        image: {
            alias: "orbit-instance-aaaaaaaaaaaa",
            packages: ["acl", "git", "nftables"],
            ...gatewayImage(),
            files: [{
                path: "/etc/nftables.conf",
                content: "flush ruleset\n",
                uid: 0,
                gid: 0,
                mode: "0640",
            }],
            browser_trust: browserTrust,
        },
        folders: {
            runtime: {
                path: `${folder}/opt/orbit-runtime`,
                version: "111111111111",
                parts: parts.runtime,
            },
            agent: {
                path: `${folder}/opt/orbit-agent`,
                version: "222222222222",
                parts: parts.agent,
                notes: { "web-app": "# This environment\n" },
            },
        },
    };
    const nestedPackages = [];
    for (const name of ["incus-base", "incus-client"]) {
        Deno.writeTextFileSync(`${folder}/artifacts/${name}.deb`, `fixture ${name}`);
        nestedPackages.push({ name, sha256: await sha256(`${folder}/artifacts/${name}.deb`), urls: [`https://example.test/${name}.deb`] });
    }
    const hosted = {
        ...instances,
        ...(nested ? { nested: { project: "orbit-instances-nested", image: {
            ...instances.image,
            alias: "orbit-instance-nested-aaaaaaaaaaaa",
            packages: [...instances.image.packages, "wireguard-tools", "sqlite3", "sudo"],
            incus: nestedPackages,
            prepare: instances.image.prepare + "usermod --append --groups incus-admin agent\n",
            files: [...instances.image.files, { path: "/etc/sudoers.d/orbit-agent", content: "agent ALL=(ALL:ALL) NOPASSWD: ALL\n", uid: 0, gid: 0, mode: "0440" }],
        } } } : {}),
    };
    const write = (value: unknown) =>
        Deno.writeTextFileSync(
            `${folder}/intent.json`,
            JSON.stringify({ instances: value }),
        );
    write(hosted);
    return {
        folder,
        instances: hosted as typeof instances & { nested?: { project: string; image: typeof instances.image & { incus: typeof nestedPackages } } },
        write,
        artifacts: (version: string) => artifacts(folder, version),
        async run(seconds = 240) {
            const remaining = (fixtureDeadline - Date.now()) / 1000;
            if (remaining <= 0) {
                throw new Error(
                    "Instance host fixture command exhausted its 720s budget",
                );
            }
            seconds = Math.min(seconds, remaining);
            const receipt = `${folder}/supervisor.json`;
            Deno.writeTextFileSync(receipt, "{}");
            const child = new Deno.Command("python3", {
                args: [
                    new URL("./fixture_supervisor.py", import.meta.url)
                        .pathname,
                    String(seconds),
                    receipt,
                    "ansible-playbook",
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
                    PATH: `${folder}/bin:${Deno.env.get("PATH")}`,
                    ANSIBLE_NOCOLOR: "1",
                    ANSIBLE_HOME: `${folder}/home`,
                    ANSIBLE_LOCAL_TEMP: `${folder}/tmp`,
                    ANSIBLE_LIBRARY: new URL("library", root).pathname,
                },
                stdout: "piped",
                stderr: "piped",
            }).spawn();
            const controller = new AbortController();
            const chunks: string[] = [];
            const collect = (stream: ReadableStream<Uint8Array>) => {
                const decoder = new TextDecoder();
                return stream.pipeTo(
                    new WritableStream<Uint8Array>({
                        write(chunk) {
                            chunks.push(
                                decoder.decode(chunk, { stream: true }),
                            );
                        },
                        close() {
                            chunks.push(decoder.decode());
                        },
                    }),
                    { signal: controller.signal },
                ).catch((error) => {
                    if (!controller.signal.aborted) throw error;
                });
            };
            const outputPromise = Promise.all([
                child.status,
                collect(child.stdout),
                collect(child.stderr),
            ]);
            let deadline: ReturnType<typeof setTimeout> | undefined;
            try {
                const done = await Promise.race([
                    outputPromise,
                    new Promise<undefined>((resolve) => {
                        deadline = setTimeout(
                            () => resolve(undefined),
                            (seconds + 5) * 1000,
                        );
                    }),
                ]);
                const output = chunks.join("");
                const supervision = JSON.parse(Deno.readTextFileSync(receipt));
                if (!done || supervision.timedOut) {
                    throw new Error(
                        `Instance host playbook timed out after ${seconds}s.\n${output}`,
                    );
                }
                if (supervision.timedOut !== false) {
                    throw new Error(
                        `Instance host supervisor failed.\n${output}`,
                    );
                }
                return { ok: done[0].success, output };
            } finally {
                clearTimeout(deadline);
                controller.abort();
            }
        },
        state: () => JSON.parse(Deno.readTextFileSync(`${folder}/state.json`)),
        calls: () =>
            Deno.readTextFileSync(`${folder}/calls`).trim().split("\n").map((
                line,
            ) => JSON.parse(line) as string[]),
        has: (name: string) => {
            try {
                Deno.lstatSync(`${folder}/${name}`);
                return true;
            } catch {
                return false;
            }
        },
        cleanup: () => { stopDaemon(); Deno.removeSync(folder, { recursive: true }); },
    };
}

/** A tarball at `artifacts/<name>` of shell programs, each printing what its body prints. */
export async function program(
    folder: string,
    name: string,
    files: Record<string, string>,
) {
    const work = Deno.makeTempDirSync();
    for (const [path, body] of Object.entries(files)) {
        Deno.mkdirSync(`${work}/${path}`.replace(/\/[^/]+$/, ""), {
            recursive: true,
        });
        Deno.writeTextFileSync(`${work}/${path}`, `#!/bin/sh\n${body}\n`);
        Deno.chmodSync(`${work}/${path}`, 0o755);
    }
    new Deno.Command("tar", {
        args: ["-czf", `${folder}/artifacts/${name}`, "-C", work, "."],
    }).outputSync();
    Deno.removeSync(work, { recursive: true });
    return await sha256(`${folder}/artifacts/${name}`);
}

/** What PHP prints for the check's probe: its version, then its extensions. */
export const probe = (version: string, extensions: string[]) =>
    `printf '%s\\n' ${[version, ...extensions].join(" ")}`;
