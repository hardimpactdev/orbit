import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import {
    cleanupPlan,
    createdServer,
    createRequest,
    madeTemplate,
} from "./upcloud_image_api.ts";

const id = (n: number) =>
    `00000000-0000-4000-8000-${String(n).padStart(12, "0")}`;
const stamp = "20261031050000";
const identity = `sha256:${"b".repeat(64)}`;
const hostKey =
    "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG tvm-image";
const intent = (pinned: string[] = []) => ({
    build_id: 9,
    stamp,
    name: `tvm-image-${stamp}`,
    identity,
    recipe: { tools: {} },
    upcloud: { zone: "nl-ams1", plan: "STARTER-2xCPU-4GB", disk_gb: 30 },
    host_key: hostKey,
    ssh: {
        identity_file: "/etc/orbit/swamp-role-worker/ssh-identity",
        public_key: "ssh-ed25519 AAAAworker",
    },
    pinned_templates: pinned,
});
const plans = (tier: string | null = "standard") =>
    JSON.stringify({
        plans: {
            plan: [{ name: "STARTER-2xCPU-4GB", storage_tier: tier }, {
                name: "other",
                storage_tier: "maxiops",
            }],
        },
    });

Deno.test("the create body is public intent with the host key as its only curl expansion", () => {
    const { create_data } = createRequest(intent(), "builder", id(1), plans());
    const [before, after] = create_data.split(
        "{{ORBIT_HOST_PRIVATE_KEY:json:json}}",
    );
    assertEquals(create_data.split("{{").length, 2);
    const body = JSON.parse(before + "KEY" + after).server;
    assertEquals(body.title, `tvm-image-${stamp}`);
    assertEquals(body.labels.label, [{
        key: "orbit-task-image",
        value: stamp,
    }]);
    assertEquals(body.storage_devices.storage_device[0], {
        action: "clone",
        storage: id(1),
        title: `tvm-image-${stamp}-disk`,
        size: 30,
        tier: "standard",
    });
    const config = JSON.parse(body.user_data.slice("#cloud-config\n".length));
    assertEquals(config.ssh_keys, {
        ed25519_private: "KEY",
        ed25519_public: hostKey,
    });
    assertEquals(config.users[0].uid, 1000);
    assertEquals(config.users[0].ssh_authorized_keys, [
        "ssh-ed25519 AAAAworker",
    ]);
});

Deno.test("the smoke server keeps the template's account and omits a tier its plan lacks", () => {
    const { create_data } = createRequest(
        intent(),
        "smoke",
        id(2),
        plans(null),
    );
    const body = JSON.parse(
        create_data.replace("{{ORBIT_HOST_PRIVATE_KEY:json:json}}", "KEY"),
    ).server;
    assertEquals(body.title, `tvm-image-${stamp}-smoke`);
    assertEquals("tier" in body.storage_devices.storage_device[0], false);
    assertEquals("uid" in JSON.parse(body.user_data.slice(14)).users[0], false);
});

Deno.test("an unknown plan, a foreign expansion or a name that is not the stamp refuses before any request", () => {
    assertThrows(
        () =>
            createRequest(
                {
                    ...intent(),
                    upcloud: { ...intent().upcloud, plan: "missing" },
                },
                "builder",
                id(1),
                plans(),
            ),
        Error,
        "task_vm.upcloud_invalid",
    );
    assertThrows(
        () =>
            createRequest(
                {
                    ...intent(),
                    ssh: {
                        ...intent().ssh,
                        public_key: "ssh-ed25519 {{ORBIT_UPCLOUD_TOKEN}}",
                    },
                },
                "builder",
                id(1),
                plans(),
            ),
        Error,
        "task_vm.upcloud_invalid",
    );
    assertThrows(() =>
        createRequest(
            { ...intent(), name: "tvm-image-other" },
            "builder",
            id(1),
            plans(),
        )
    );
});

const answer = (
    title: string,
    labels = [{ key: "orbit-task-image", value: stamp }],
    ips = [{ access: "public", family: "IPv4", address: "94.237.0.207" }],
) => JSON.stringify({
    server: {
        uuid: id(3),
        title,
        hostname: title,
        state: "maintenance",
        labels: { label: labels },
        ip_addresses: {
            ip_address: [{
                access: "utility",
                family: "IPv4",
                address: "10.0.0.5",
            }, ...ips],
        },
        storage_devices: { storage_device: [{ storage: id(4), type: "disk" }] },
    },
});

Deno.test("the create answer gives the server, its disk and its one public address", () => {
    assertEquals(
        createdServer(intent(), "builder", answer(`tvm-image-${stamp}`)),
        {
            uuid: id(3),
            storage: id(4),
            address: "94.237.0.207",
        },
    );
    for (
        const stdout of [
            answer(`tvm-image-${stamp}-smoke`),
            answer(`tvm-image-${stamp}`, [{
                key: "orbit-task-image",
                value: "20260101000000",
            }]),
            answer(`tvm-image-${stamp}`, undefined, []),
            "not json",
        ]
    ) {
        assertThrows(
            () => createdServer(intent(), "builder", stdout),
            Error,
            "task_vm.upcloud_invalid",
        );
    }
});

Deno.test("templatize answers with this attempt's unverified template only", () => {
    assertEquals(
        madeTemplate(
            intent(),
            JSON.stringify({
                storage: {
                    uuid: id(5),
                    title: `orbit-task-base-${stamp}-unverified`,
                },
            }),
        ),
        { template_uuid: id(5) },
    );
    assertThrows(
        () =>
            madeTemplate(
                intent(),
                JSON.stringify({
                    storage: {
                        uuid: id(5),
                        title: "orbit-task-base-20260101000000-unverified",
                    },
                }),
            ),
        Error,
        "task_vm.upcloud_invalid",
    );
});

const listing = (templates: unknown[], servers: unknown[] = []) =>
    JSON.stringify({
        servers: { servers: { server: servers } },
        templates: { storages: { storage: templates } },
    });
const template = (
    n: number,
    title: string,
    labels: { key: string; value: string }[] = [],
) => ({
    uuid: id(n),
    title,
    access: "private",
    state: "online",
    labels,
});
const verified = (n: number, at: string, recipe = identity) =>
    template(n, `orbit-task-base-${at}`, [{
        key: "orbit.recipe",
        value: recipe,
    }, { key: "orbit-task-image", value: at }]);
const server = (n: number, title: string, state = "started", at = stamp) => ({
    uuid: id(n),
    title,
    state,
    labels: { label: [{ key: "orbit-task-image", value: at }] },
});

Deno.test("after promotion the cleanup removes both servers and prunes beyond the two newest unpinned templates", () => {
    const plan = cleanupPlan(
        intent([id(12)]),
        listing([
            verified(10, stamp),
            verified(11, "20261030050000"),
            verified(12, "20261029050000"),
            verified(13, "20261028050000"),
            template(14, "ubuntu-26.04"),
            template(15, "orbit-task-base-20261027050000"),
        ], [
            server(20, `tvm-image-${stamp}`),
            server(21, `tvm-image-${stamp}-smoke`, "stopped"),
            server(22, "tvm-image-20261030050000", "started", "20261030050000"),
            server(23, `tvm-image-${stamp}`, "started", "20261030050000"),
        ]),
        id(10),
    );
    assertEquals(plan.promoted, true);
    assertEquals(plan.template_uuid, id(10));
    assertEquals(plan.deletes, [
        {
            uuid: id(20),
            path: `server/${id(20)}?storages=1&backups=delete`,
            stop: true,
        },
        {
            uuid: id(21),
            path: `server/${id(21)}?storages=1&backups=delete`,
            stop: false,
        },
        { uuid: id(13), path: `storage/${id(13)}`, stop: false },
    ]);
});

Deno.test("a build that never promoted deletes its unverified template and keeps every verified one", () => {
    const unverified = template(30, `orbit-task-base-${stamp}-unverified`);
    for (const uuid of [id(30), ""]) {
        const plan = cleanupPlan(
            intent(),
            listing([
                unverified,
                verified(31, "20261030050000"),
                verified(32, "20261029050000"),
                verified(33, "20261028050000"),
            ]),
            uuid,
        );
        assertEquals(plan.promoted, false);
        assertEquals(plan.template_uuid, "");
        assertEquals(plan.deletes, [{
            uuid: id(30),
            path: `storage/${id(30)}`,
            stop: false,
        }]);
    }
});

Deno.test("a template without the recipe label is not promoted, so the end check fails and nothing verified goes", () => {
    const plan = cleanupPlan(
        intent(),
        listing([
            template(40, `orbit-task-base-${stamp}`, [{
                key: "orbit-task-image",
                value: stamp,
            }]),
            verified(41, "20261030050000"),
            verified(42, "20261029050000"),
        ]),
        id(40),
    );
    assertEquals(plan.promoted, false);
    assertEquals(plan.deletes, []);
});
