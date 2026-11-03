import { assertEquals } from "jsr:@std/assert@1.0.19";
import { roleAccountSteps, rolePackageSteps } from "./steps.ts";

Deno.test("role account reads passwd then primary group before decoding their outputs", () => {
    const execution = { name: "admitted-target" };
    const steps = roleAccountSteps("account", "inputs.user", execution) as {
        name: string;
        dependsOn?: unknown;
        task: { globalArgs?: unknown; inputs: unknown };
    }[];
    assertEquals(steps.map((step) => step.name), [
        "account_command",
        "account_group",
        "account",
    ]);
    assertEquals(steps[0].dependsOn, undefined);
    assertEquals(steps[1].dependsOn, [{
        step: "account_command",
        condition: { type: "succeeded" },
    }]);
    assertEquals(steps[2].dependsOn, [{
        step: "account_group",
        condition: { type: "succeeded" },
    }]);
    for (const step of steps.slice(0, 2)) {
        assertEquals(step.task.globalArgs, execution);
    }
    assertEquals(steps.slice(0, 2).map((step) => step.task.inputs), [
        {
            hosts: "name:target",
            sudo: false,
            argv: '${{ ["getent", "passwd", inputs.user] }}',
            timeoutSec: 30,
        },
        {
            hosts: "name:target",
            sudo: false,
            argv: '${{ ["id", "-gn", inputs.user] }}',
            timeoutSec: 30,
        },
    ]);
    assertEquals(steps[2].task.inputs, {
        passwd: '${{ steps["account_command"].outputs.stdout }}',
        group: '${{ steps["account_group"].outputs.stdout }}',
    });
});

Deno.test("role packages check the admitted releases before refreshing and installing only admitted packages", () => {
    const steps = rolePackageSteps("packages", "inputs.prerequisites", {
        name: "admitted-target",
    }, "account") as {
        name: string;
        dependsOn: unknown;
        writes: boolean;
        task: { inputs: { argv: string; sudo: boolean } };
    }[];
    assertEquals(
        steps.map((step) => [step.name, step.dependsOn, step.writes]),
        [
            ["packages_release", [{
                step: "account",
                condition: { type: "succeeded" },
            }], false],
            ["packages_lists", [{
                step: "packages_release",
                condition: { type: "succeeded" },
            }], true],
            ["packages", [{
                step: "packages_lists",
                condition: { type: "succeeded" },
            }], true],
        ],
    );
    assertEquals(steps.map((step) => step.task.inputs.argv), [
        '${{ ["/usr/bin/grep", "-qxE", "VERSION_CODENAME=(" + inputs.prerequisites.releases.join("|") + ")", "/etc/os-release"] }}',
        '${{ ["/usr/bin/env", "LC_ALL=C", "DEBIAN_FRONTEND=noninteractive", "NEEDRESTART_MODE=l", "/usr/bin/apt-get", "-o", "APT::Update::Error-Mode=any", "update"] }}',
        '${{ ["/usr/bin/env", "LC_ALL=C", "DEBIAN_FRONTEND=noninteractive", "NEEDRESTART_MODE=l", "/usr/bin/apt-get", "--no-remove", "install", "--yes", "--no-install-recommends", "--"] + inputs.prerequisites.packages }}',
    ]);
    assertEquals(steps.map((step) => step.task.inputs.sudo), [
        true,
        true,
        true,
    ]);
});
