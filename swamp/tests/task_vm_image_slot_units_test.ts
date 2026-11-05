/** The slot units that the task image build bakes start only from the slot identity tree, and match what the Gateway renders today. */
import { assert, assertEquals, assertFalse } from "jsr:@std/assert@1.0.14";
import { parse } from "jsr:@std/yaml@1.0.11";

type Step = {
    name: string;
    dependsOn?: { step: string }[];
    task: { inputs: { argv?: string[]; stdin?: string } };
};
const read = (path: string) =>
    Deno.readTextFile(new URL(path, import.meta.url));
const workflow = parse(
    await read("../workflows/workflow-operation-task-vm-image-build.yaml"),
) as { jobs: { steps: Step[] }[] };
const steps = new Map(
    workflow.jobs.flatMap((job) => job.steps).map((step) => [step.name, step]),
);
const stepNames = [...steps.keys()];

const units = {
    agent: ["slot-units-agent", "/etc/systemd/system/orbit-agent.service"],
    agentDropin: [
        "slot-units-agent-dropin",
        "/etc/systemd/system/orbit-agent.service.d/10-slot.conf",
    ],
    wireguardDropin: [
        "slot-units-wireguard-dropin",
        "/etc/systemd/system/wg-quick@orbit.service.d/10-slot.conf",
    ],
    target: ["slot-units-target", "/etc/systemd/system/orbit-slot.target"],
} as const;
const text = (key: keyof typeof units) =>
    steps.get(units[key][0])!.task.inputs.stdin!;
const lines = (key: keyof typeof units) => text(key).split("\n");
const values = (key: keyof typeof units, name: string) =>
    lines(key).filter((line) => line.startsWith(`${name}=`)).map((line) =>
        line.slice(name.length + 1)
    );

Deno.test("the build pushes each unit to its path in order, enables only the target, and cleans after", () => {
    const order = [
        "slot-identity-enable",
        ...Object.values(units).map(([step]) => step),
        "slot-units-enable",
        "clean-marker",
    ];
    for (let i = 1; i < order.length; i++) {
        assertEquals(
            steps.get(order[i])!.dependsOn?.map((d) => d.step),
            [order[i - 1]],
            `${order[i]} does not follow ${order[i - 1]}`,
        );
    }
    for (const [step, path] of Object.values(units)) {
        const argv = steps.get(step)!.task.inputs.argv!;
        assert(argv.includes("push") && argv.includes("--create-dirs"), step);
        assertEquals(argv.at(-1), `\${{ inputs.intent.name + "${path}" }}`);
    }
    // Only the target is enabled. The other units have no enable symlink, so a VM that is not a slot starts none.
    const enable = steps.get("slot-units-enable")!.task.inputs.argv!;
    assertEquals(enable.slice(-2), ["enable", "orbit-slot.target"]);
    assertEquals(stepNames.filter((name) => name.includes("enable")), [
        "ssh-enable",
        "slot-identity-enable",
        "slot-units-enable",
    ]);
});

Deno.test("each baked unit starts only when its identity files exist", () => {
    assertEquals(values("agentDropin", "ConditionPathExists"), [
        "/etc/orbit/agent/secret",
        "/etc/orbit/agent/config.toml",
        "/etc/orbit/agent/ca.pem",
    ]);
    assertEquals(values("wireguardDropin", "ConditionPathExists"), [
        "/etc/wireguard/orbit.conf",
    ]);
    // The slot's own drop-in of the target, which the identity tree carries, is the marker of a slot.
    assertEquals(values("target", "ConditionPathExists"), [
        "/etc/systemd/system/orbit-slot.target.d/10-pi.conf",
    ]);
    assertEquals(values("target", "Wants"), [
        "wg-quick@orbit.service orbit-agent.service",
    ]);
    assertEquals(values("target", "WantedBy"), ["multi-user.target"]);
    // Everything the target pulls in is ordered before it, so an active target means the slot is up.
    const after = values("target", "After").join(" ").split(" ");
    for (
        const name of [
            "orbit-slot-identity.service",
            "wg-quick@orbit.service",
            "orbit-agent.service",
        ]
    ) assert(after.includes(name), `After= lacks ${name}`);
    assertFalse(text("target").includes("ssh.socket"));
    assertFalse(text("agentDropin").includes("[Install]"));
    assertFalse(text("wireguardDropin").includes("[Install]"));
});

Deno.test("the image bakes no Pi unit: the Gateway's own unit comes with the identity tree", () => {
    // `journalctl --unit` does not follow a systemd alias, so the Pi unit must carry the name the Gateway records.
    for (const step of steps.values()) {
        const stdin = step.task?.inputs?.stdin ?? "";
        assertFalse(stdin.includes("pi-server serve"), step.name);
        assertFalse(stdin.includes("pi-server.service"), step.name);
    }
});

Deno.test("the identity unit installs the tree's unit files, reloads systemd and starts the target", () => {
    const unit = steps.get("slot-identity-unit")!.task.inputs.stdin!;
    const exec = unit.split("\n").filter((line) =>
        line.startsWith("ExecStart=")
    )
        .map((line) => line.slice("ExecStart=".length));
    const copy = exec.indexOf(
        "/usr/bin/cp -rT --preserve=mode /run/orbit-slot/etc/systemd/system /etc/systemd/system",
    );
    const umount = exec.indexOf("/usr/bin/umount /run/orbit-slot");
    const reload = exec.indexOf("/usr/bin/systemctl daemon-reload");
    const start = exec.indexOf(
        "/usr/bin/systemctl --no-block start orbit-slot.target",
    );
    // The new unit names are unknown to systemd until it reloads, and the boot transaction has already expanded the
    // target, so the target is started again after the reload to pull in the Pi unit. --no-block avoids waiting on itself.
    assert(copy >= 0 && copy < umount && umount < reload && reload < start);
    assert(exec.includes("/usr/sbin/groupadd -f --system orbit-env"));
    assertFalse(unit.includes("pi.env"));
});

Deno.test("the baked observer unit is the text the Gateway renders for a new Node", async () => {
    const executor = await read(
        "../../apps/gateway/app/Infrastructure/Nodes/NodeAgentSshExecutor.php",
    );
    const footprint = await read(
        "../../apps/gateway/app/Infrastructure/Nodes/NodeAgentFootprint.php",
    );
    const marker = footprint.match(/Marker = '([^']+)'/)![1];
    const binary = footprint.match(/BinaryPath = '([^']+)'/)![1];
    const group = executor.match(/EnvGroup = '([^']+)'/)![1];
    const block = executor.slice(
        executor.indexOf("$unit = implode"),
        executor.indexOf("return ['configuration'"),
    );
    const expected: string[] = [];
    for (const line of block.split("\n").map((l) => l.trim())) {
        const literal = line.match(/^'([^'$]+)',$/);
        if (literal) expected.push(literal[1]);
        else if (line === "NodeAgentFootprint::Marker,") expected.push(marker);
        else if (line === "'ExecStart='.NodeAgentFootprint::BinaryPath,") {
            expected.push(`ExecStart=${binary}`);
        } else if (line === "'SupplementaryGroups='.self::EnvGroup,") {
            expected.push(`SupplementaryGroups=${group}`);
        } else if (line.startsWith("...$this->checkoutAccess(")) {
            // A new Node has no Instance root and no tool path, which renders `ProtectHome=yes`.
            expected.push("ProtectHome=yes");
        }
    }
    assert(expected.length > 15, "the Gateway template was not read");
    assertEquals(lines("agent").filter((line) => line !== ""), expected);
    assertEquals(values("agent", "SupplementaryGroups"), ["orbit-env"]);
});

Deno.test("the smoke VM has no identity, so the target is enabled and nothing starts", () => {
    const smoke = steps.get("smoke-slot-units")!.task.inputs.argv!;
    const script = smoke.at(-1)!;
    assertEquals(smoke.at(-3), "/bin/sh");
    assert(script.startsWith("systemctl is-enabled --quiet orbit-slot.target"));
    assert(script.includes("! systemctl is-active --quiet"));
    for (
        const name of [
            "orbit-slot.target",
            "orbit-agent.service",
            "wg-quick@orbit.service",
        ]
    ) assert(script.includes(name), `the smoke does not check ${name}`);
    assert(
        smoke.some((arg) => arg.includes('inputs.intent.name + "-smoke"')),
        "the check must run in the smoke VM, not the builder",
    );
});
