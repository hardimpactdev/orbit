import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { workflow } from "./workflows.ts";

const step = (name: string) =>
    (workflow(false).jobs[0].steps as { name: string; task: { globalArgs: Record<string, string> } }[])
        .find((s) => s.name === name)!;

Deno.test("peer DNS quotes each routing domain, so the shell never expands ~orbit to a home directory", async () => {
    const command = step("Set the DNS policy").task.globalArgs.command;
    assert(command.includes(`.map(d,"'~"+d+"'")`));
    // The same rendering for one domain, run by sh with resolvectl replaced by an argument printer.
    const rendered = "resolvectl domain orbit " + ["orbit"].map((d) => "'~" + d + "'").join(" ");
    const out = await new Deno.Command("sh", { args: ["-c", 'resolvectl() { printf "%s\\n" "$@"; }; ' + rendered] }).output();
    assertEquals(new TextDecoder().decode(out.stdout), "domain\norbit\n~orbit\n");
});

Deno.test("peer DNS ends with the resolution check the Gateway reads as its native end step", () => {
    const steps = workflow(false).jobs[0].steps as { name: string }[];
    assertEquals(steps.map((s) => s.name), ["Keep the DNS hook", "Write the DNS marker", "Set the DNS policy", "Check resolution"]);
});
