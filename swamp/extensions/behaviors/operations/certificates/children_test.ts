/** The certificate children change Nodes only through official steps; 209 signs through the typed signer. */
import { assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";

const Workflows = new URL("../../../../workflows/", import.meta.url);
const Official = [
    "@swamp/ssh",
    "@swamp/cfgmgmt/directory",
    "@swamp/cfgmgmt/file",
    "@swamp/cfgmgmt/link",
];

type Step = {
    name: string;
    labels?: unknown;
    task: {
        behaviorType: string;
        methodName: string;
        globalArgs?: Record<string, unknown>;
    };
};

function steps(name: string): Step[] {
    const workflow = parse(
        Deno.readTextFileSync(new URL(`workflow-${name}.yaml`, Workflows)),
    ) as {
        jobs: { steps: Step[] }[];
    };
    return workflow.jobs.flatMap((job) => job.steps);
}

function assertOfficial(name: string, labels: string[]) {
    const rows = steps(name);
    assertEquals(rows.map((step) => step.name), labels);
    for (const step of rows) {
        assertEquals(step.labels, { role: "swamp" }, step.name);
        const task = step.task;
        if (task.behaviorType === "@swamp/ssh") {
            assertEquals(task.methodName, "exec", step.name);
        } else if (task.behaviorType === "@nckrtl/certificate-sign") {
            assertEquals([step.name, task.methodName], [
                "Sign the CSR",
                "sign",
            ]);
        } else {assertEquals(
                Official.includes(task.behaviorType),
                true,
                `${step.name}: ${task.behaviorType}`,
            );}
    }
}

Deno.test("route publish installs each leaf and the account copy", () => {
    assertOfficial("route-certificate-publish", [
        "Read the account",
        "Make the leaf folder",
        "Make a CSR on the Node",
        "Protect the key",
        "Sign the CSR",
        "Install the certificate",
        "Select the certificate",
        "Copy the account pair",
        "Select the account pair",
        "Trust the Orbit root",
        "Refresh the trust store",
    ]);
});

Deno.test("route remove deletes both folders", () => {
    assertOfficial("route-certificate-remove", [
        "Read the account",
        "Remove the Caddy pair",
        "Remove the account pair",
    ]);
    assertEquals(
        steps("route-certificate-remove").slice(1).map((step) =>
            step.task.globalArgs?.ensure
        ),
        ["absent", "absent"],
    );
});

Deno.test("role publish reloads Caddy before it removes the pending key", () => {
    assertOfficial("node-role-certificate-publish", [
        "Make the key folder",
        "Make a CSR on the Node",
        "Sign the CSR",
        "Make the certificate folder",
        "Install the key",
        "Install the certificate",
        "Install the Orbit root",
        "Reload Caddy",
        "Remove the pending key",
    ]);
});
