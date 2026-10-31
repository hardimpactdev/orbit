import {
    assertEquals,
    assertRejects,
    assertThrows,
} from "jsr:@std/assert@1.0.19";
import {
    enrollmentCommandInputs,
    EnrollmentCommandIntent,
} from "./enrollment_command_input.ts";

export function commandIntent(): zIntent {
    return {
        schema_version: 1,
        task_vm_id: 184,
        guest_node_id: 212,
        execution_node_id: 13,
        gateway_node_id: 14,
        issuer_node_id: 209,
        model_router_node_id: 3,
        participant_node_ids: [13, 14, 209, 3, 212, 1],
        provider: "incus",
        name: "tvm-184",
        user: "orbit",
        agent_sha256: "a".repeat(64),
        agent_artifact_url: "https://example.test/observer",
        pi_url: "http://10.44.0.212:7331",
        model_origin: "https://models.example.test",
        vm: { project: "orbit-tasks", name: "tvm-184" },
        node: {
            node_id: 212,
            name: "tvm-184",
            user: "orbit",
            wireguard_ip: "10.44.0.212",
        },
        bootstrap: {
            user: "orbit",
            public_key: "ssh-ed25519 PUBLIC",
            releases: ["resolute"],
            packages: ["curl"],
            dns_policy: "preserve",
        },
        agent: {
            configuration: "public-config",
            certificate: "PUBLIC CA",
            unit: "public-unit",
            checkouts: [],
        },
        runtime: {
            guest_node_id: 212,
            user: "orbit",
            agent_sha256: "a".repeat(64),
            pi_sha256: "b".repeat(64),
            origin: "https://models.example.test",
            models: ["one"],
            process: {
                process_id: 55,
                name: "orbit-process-55-pi-server.service",
                runtime: "systemd",
                content_hash: "c".repeat(64),
                desired: "running",
                on_demand: false,
                activation_pending: true,
            },
        },
        network: {
            server_public_key: "a".repeat(43) + "=",
            endpoint: "vpn.example.test:51820",
            address: "10.44.0.212/32",
            subnet: "10.44.0.0/16",
            dns_server: "10.44.0.1",
            domains: ["orbit"],
        },
        _wireguard: {
            schema_version: 1,
            executor_node_id: 14,
            hub_id: 1,
            context_node_id: 212,
            excluded_node_id: null,
            agent_sha256: "a".repeat(64),
        },
        connection: { address: null, public_key: null },
    };
}
type zIntent = import("./enrollment_command_input.ts").EnrollmentCommandIntent;

Deno.test("command intent retains complete public placement and artifact identity", () => {
    const input = commandIntent();
    assertEquals(EnrollmentCommandIntent.parse(input), input);
});

Deno.test("public intent refuses transport fields and secret descriptors at every boundary", () => {
    for (
        const field of [
            "private_bindings",
            "model_key",
            "pi_token",
            "execution_secrets",
            "configuration",
        ]
    ) {
        assertThrows(() =>
            EnrollmentCommandIntent.parse({
                ...commandIntent(),
                [field]: "private-sentinel",
            })
        );
    }
    const input = commandIntent();
    assertThrows(() =>
        EnrollmentCommandIntent.parse({
            ...input,
            runtime: { ...input.runtime, pi_token: "private-sentinel" },
        })
    );
    assertThrows(() =>
        EnrollmentCommandIntent.parse({
            ...input,
            agent: { ...input.agent, worker_credentials: "private-sentinel" },
        })
    );
});

Deno.test("public command intent refuses cross-VM and cross-Node placements", () => {
    const mutations: ((input: zIntent) => void)[] = [
        (v) => v.name = "tvm-185",
        (v) => v.node.node_id = 213,
        (v) => v.runtime.guest_node_id = 213,
        (v) => v.runtime.agent_sha256 = "b".repeat(64),
        (v) => v.runtime.process.name = "orbit-process-56-pi-server.service",
        (v) => v.model_origin = "https://foreign.example.test",
        (v) => v._wireguard.executor_node_id = 13,
        (v) => v._wireguard.context_node_id = 213,
        (v) => v.execution_node_id = 212,
        (v) => v.participant_node_ids = [13, 14, 209, 212, 1],
        (v) => v.vm!.name = "tvm-185",
    ];
    for (const mutate of mutations) {
        const input = commandIntent();
        mutate(input);
        assertThrows(() => EnrollmentCommandIntent.parse(input));
    }
});

Deno.test("metadata serializes public input and returns the native resource handle", async () => {
    const writes: unknown[] = [];
    const result = await enrollmentCommandInputs().methods.read.execute(
        { intent: commandIntent() },
        {
            writeResource: (spec, name, value) => {
                writes.push({ spec, name, value });
                return Promise.resolve({ name });
            },
        },
    );
    assertEquals(result, { dataHandles: [{ name: "intent" }] });
    const value = (writes[0] as {
        value: { intent_json: string; project: string; name: string };
    }).value;
    assertEquals(JSON.parse(value.intent_json), commandIntent());
    assertEquals(value.project, "orbit-tasks");
    assertEquals(value.name, "tvm-184");
});

Deno.test("invalid metadata never writes a native result and reports a fixed error", async () => {
    const input = commandIntent();
    input.node.node_id = 213;
    let writes = 0;
    await assertRejects(
        () =>
            enrollmentCommandInputs().methods.read.execute({ intent: input }, {
                writeResource: () => {
                    writes++;
                    return Promise.resolve({});
                },
            }),
        Error,
        "task_vm.enrollment_invalid",
    );
    assertEquals(writes, 0);
});

Deno.test("Incus metadata refuses the UpCloud transport rather than selecting another host", async () => {
    const input = commandIntent();
    input.provider = "upcloud";
    input.execution_node_id = input.gateway_node_id;
    input.vm = null;
    input.connection = {
        address: "192.0.2.184",
        public_key: "ssh-ed25519 PIN",
    };
    EnrollmentCommandIntent.parse(input);
    await assertRejects(
        () =>
            enrollmentCommandInputs().methods.read.execute({ intent: input }, {
                writeResource: () =>
                    Promise.reject(new Error("must not write")),
            }),
        Error,
        "task_vm.enrollment_invalid",
    );
});

Deno.test("enrollment metadata consumes its public subset of the complete provision intent", () => {
    const enrollment = commandIntent();
    assertEquals(
        EnrollmentCommandIntent.parse({
            ...enrollment,
            node_id: enrollment.execution_node_id,
            host_node_id: enrollment.execution_node_id,
            wireguard_ip: enrollment.node.wireguard_ip,
            recipe_identity: "recipe-278",
            image_fingerprint: "a".repeat(64),
            create: { kind: "create" },
            upcloud: null,
            provider_host_public_key: "ssh-ed25519 PUBLIC",
        }),
        enrollment,
    );
});
