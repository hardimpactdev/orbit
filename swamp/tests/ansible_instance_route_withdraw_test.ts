import { assertEquals } from "jsr:@std/assert@1.0.19";
import { parse, stringify } from "jsr:@std/yaml@1.0.12";
import { composeWithdrawal as compose } from "../extensions/behaviors/operations/routes/publication-command/assets.ts";
const ROOT = new URL("../", import.meta.url).pathname.replace(/\/$/, "");
const planPath =
    "/tmp/orbit-ansible-00000000-0000-0000-0000-000000000000/plan.json";
const placement = {
    node_id: 9,
    user: "orbit",
    control_node_id: 209,
    host_keys: ["ssh-ed25519 fixture"],
    connection: {
        nodeHost: "10.44.0.9",
        nodePort: 22,
        nodeUser: "orbit",
        nodeIdentityFile: "/existing/key",
        become: true,
    },
};
const config = (...blocks: string[]) =>
    "# Managed by Orbit: Node Caddy build\n{ admin off }\n" +
    (blocks.length ? "\n" + blocks.join("\n") : "");
const block = (name: string, target: string) =>
    `# orbit: routes ${name}\nhttps://${name}.test { reverse_proxy ${target} }\n`;
const sha = async (value: string) =>
    Array.from(
        new Uint8Array(
            await crypto.subtle.digest(
                "SHA-256",
                new TextEncoder().encode(value),
            ),
        ),
        (b) => b.toString(16).padStart(2, "0"),
    ).join("");
const section = async (content: string, served: boolean) => ({
    content,
    version: (await sha(content)).slice(0, 32),
    listen_addresses: ["10.44.0.9"],
    unserved: !served,
    had_served_groups: served,
});
function read(
    fields: {
        account: string;
        caddy: string;
        records: string;
        catalog: string;
    },
) {
    return JSON.stringify({
        account: fields.account,
        pools: {},
        caddy: btoa(fields.caddy),
        records: btoa(fields.records),
        catalog: btoa(fields.catalog),
    });
}
const dnsState = async (names: string[]) => {
    const records = "# Managed by Orbit.\n" +
        names.map((n) => `host-record=${n},10.44.0.7\n`).join("");
    const catalog = JSON.stringify({
        records: Object.fromEntries(names.map((n) => [n, "10.44.0.7"])),
    }) + "\n";
    return {
        node_id: 1,
        records,
        catalog,
        digest: await sha(records + catalog),
    };
};
function composeWithdrawal(args: Record<string, unknown> & { read: string }) {
    const { read, ...rest } = args;
    return compose({
        ...rest,
        read_path:
            "/tmp/orbit-ansible-00000000-0000-0000-0000-000000000000/read.json",
    }, read);
}
Deno.test({
    name:
        "pending Route withdrawal removes partial publication and CSR residue or accepts an absent site with real Ansible",
    // CI's registered fixture installs pinned Ansible; ordinary behavior-only unit runs may omit it.
    ignore: !(Deno.env.get("PATH") ?? "").split(":").some((path) => {
        try {
            return Deno.statSync(path + "/ansible-playbook").isFile;
        } catch {
            return false;
        }
    }),
    async fn() {
        const root = await Deno.makeTempDir({
            prefix: "orbit-partial-withdraw-",
        });
        const uid = String(Deno.uid()), gid = String(Deno.gid());
        const binary = root + "/bin";
        const mapped = (text: string) =>
            text.replaceAll("/etc/caddy", root + "/etc/caddy")
                .replaceAll("/etc/dnsmasq.d", root + "/etc/dnsmasq.d")
                .replaceAll(
                    "/var/lib/orbit/private-dns",
                    root + "/var/lib/orbit/private-dns",
                );
        const adapt = (value: unknown): unknown => {
            if (typeof value === "string") return mapped(value);
            if (Array.isArray(value)) return value.map(adapt);
            if (value && typeof value === "object") {
                return Object.fromEntries(
                    Object.entries(value).map(([key, item]) => [
                        mapped(key),
                        key === "owner" && item === "root"
                            ? uid
                            : key === "group" && item === "root"
                            ? gid
                            : adapt(item),
                    ]),
                );
            }
            return value;
        };
        try {
            for (
                const folder of [
                    binary,
                    root +
                    "/etc/caddy/orbit-certificates/route-7-router/versions/v1",
                    root + "/etc/dnsmasq.d",
                    root + "/var/lib/orbit/private-dns",
                    root + "/home/.orbit/certificates/route-7-router",
                    root + "/tasks",
                ]
            ) {
                await Deno.mkdir(folder, { recursive: true });
            }
            const scripts: Record<string, string> = {
                runuser: 'shift 3; exec "$@"',
                caddy: 'test "$1" = validate && test -f "$3"',
                systemctl: `printf '%s\\n' "$*" >> "${root}/service-calls"`,
                dnsmasq: 'test "$1" = --test',
                getent:
                    `printf 'orbit:x:${uid}:${gid}::${root}/home:/bin/sh\\n'`,
            };
            for (const [name, body] of Object.entries(scripts)) {
                await Deno.writeTextFile(
                    `${binary}/${name}`,
                    `#!/bin/sh\n${body}\n`.replaceAll("\\n", "\n"),
                );
                await Deno.chmod(`${binary}/${name}`, 0o755);
            }
            const apply = (parse(
                await Deno.readTextFile(
                    `${ROOT}/ansible/route-withdraw.yml`,
                ),
            ) as Record<string, unknown>[])[2];
            apply.hosts = "localhost";
            apply.connection = "local";
            apply.become = false;
            (apply.vars as Record<string, unknown>).ansible_python_interpreter =
                "/usr/bin/python3";
            (apply.vars as Record<string, unknown>).caddy_group = gid;
            const caddyTasks = adapt(
                parse(
                    await Deno.readTextFile(
                        `${ROOT}/ansible/tasks/caddy-publish.yml`,
                    ),
                ),
            );
            await Deno.writeTextFile(
                `${root}/tasks/caddy-publish.yml`,
                stringify(caddyTasks),
            );
            await Deno.writeTextFile(
                `${root}/apply.yml`,
                stringify([adapt(apply)]),
            );
            const without = await section(config(block("kept", "a")), true);
            const possible = await section(
                config(block("kept", "a"), block("made", "a")),
                true,
            );
            const dnsWithout = await dnsState(["kept.test"]),
                dnsPossible = await dnsState(["kept.test", "made.test"]);
            for (const siteSucceeded of [true, false]) {
                const live = siteSucceeded ? possible.content : without.content;
                // DNS may have finished independently even when access failed before site publication.
                const plan = await composeWithdrawal({
                    ...placement,
                    plan_path: planPath,
                    read: read({
                        account: `orbit:x:${uid}:${gid}::${root}/home:/bin/sh`,
                        caddy: live,
                        records: dnsPossible.records,
                        catalog: dnsPossible.catalog,
                    }),
                    php: {},
                    caddy: {
                        before: possible,
                        prepared: without,
                        withdrawn: without,
                        after: without,
                    },
                    dns: {
                        before: dnsPossible,
                        prepared: dnsWithout,
                        withdrawn: dnsWithout,
                        after: dnsWithout,
                    },
                    cleanup: [{ scope: "route-7-router", user: "orbit" }],
                });
                await Deno.writeTextFile(`${root}/etc/caddy/Caddyfile`, live);
                await Deno.writeTextFile(
                    `${root}/etc/dnsmasq.d/orbit-records.conf`,
                    dnsPossible.records,
                );
                await Deno.writeTextFile(
                    `${root}/var/lib/orbit/private-dns/catalog.json`,
                    dnsPossible.catalog,
                );
                for (
                    const directory of [
                        `${root}/etc/caddy/orbit-certificates/route-7-router`,
                        `${root}/home/.orbit/certificates/route-7-router`,
                    ]
                ) {
                    await Deno.mkdir(directory, { recursive: true });
                    await Deno.writeTextFile(
                        directory + "/fixture.pem",
                        "fixture residue",
                    );
                }
                const run = root + "/run";
                await Deno.mkdir(run, { recursive: true });
                await Deno.writeTextFile(
                    run + "/plan.json",
                    JSON.stringify(adapt(plan)),
                );
                const command = new Deno.Command("ansible-playbook", {
                    args: ["-i", "localhost,", root + "/apply.yml"],
                    env: {
                        PATH: binary + ":" + Deno.env.get("PATH"),
                        ORBIT_RUN: run,
                        ANSIBLE_STDOUT_CALLBACK: "default",
                        ANSIBLE_DISPLAY_FAILED_STDERR: "1",
                        ANSIBLE_COLLECTIONS_PATH: root + "/empty",
                    },
                });
                const result = await command.output();
                assertEquals(
                    result.success,
                    true,
                    new TextDecoder().decode(result.stdout) +
                        new TextDecoder().decode(result.stderr),
                );
                assertEquals(
                    (await Deno.readTextFile(`${root}/etc/caddy/Caddyfile`))
                        .includes("made.test"),
                    false,
                );
                assertEquals(
                    (await Deno.readTextFile(`${root}/etc/caddy/Caddyfile`))
                        .includes("kept.test"),
                    true,
                );
                assertEquals(
                    (await Deno.readTextFile(
                        `${root}/etc/dnsmasq.d/orbit-records.conf`,
                    )).includes("made.test"),
                    false,
                );
                for (
                    const path of [
                        `${root}/etc/caddy/orbit-certificates/route-7-router`,
                        `${root}/home/.orbit/certificates/route-7-router`,
                    ]
                ) {
                    let present = true;
                    try {
                        await Deno.stat(path);
                    } catch (error) {
                        if (error instanceof Deno.errors.NotFound) {
                            present = false;
                        } else throw error;
                    }
                    assertEquals(present, false);
                }
            }
            assertEquals(
                (await Deno.readTextFile(root + "/service-calls")).includes(
                    "reload caddy",
                ),
                true,
            );
        } finally {
            await Deno.remove(root, { recursive: true });
        }
    },
});
