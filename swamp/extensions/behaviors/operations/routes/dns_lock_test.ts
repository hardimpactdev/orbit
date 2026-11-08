import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { ROOT } from "../../../../bin/gate-test-support.ts";

/**
 * The times of one private DNS publication come from swamp/ansible/vars/dns.yml. The lock holder must outlive the Swamp step that runs
 * the phase, plus the cutover, or a killed play could let a second publication in while the first one's recovery still runs.
 */
async function times(): Promise<Record<string, number>> {
    const text = await Deno.readTextFile(`${ROOT}/ansible/vars/dns.yml`);
    const values: Record<string, number> = {};
    for (const match of text.matchAll(/^(dns_[a-z_]+): (.+)$/gm)) {
        const [, name, raw] = match;
        if (!/^(?:\d+|"\{\{.*\}\}")$/.test(raw.trim())) continue;
        // `(name | int)` is the value of another variable; the rest is arithmetic.
        const expression = raw.replace(/^"\{\{|\}\}"$/g, "").replace(
            /\(?(dns_[a-z_]+) \| int\)?/g,
            (_all, other: string) => String(values[other]),
        );
        assert(
            /^[\d\s+\-()]+$/.test(expression),
            `${name}: unsupported expression ${expression}`,
        );
        values[name] = Function(`return (${expression})`)();
    }

    return values;
}

Deno.test("the lock holder outlives the phase timeout and the cutover, with a margin", async () => {
    const t = await times();
    for (
        const name of [
            "dns_phase_timeout",
            "dns_cutover_budget",
            "dns_lock_margin",
            "dns_lock_hold",
            "dns_lock_wait",
            "dns_lock_job_timeout",
        ]
    ) assert(Number.isInteger(t[name]), `${name} is missing`);
    assert(
        t.dns_lock_hold > t.dns_phase_timeout + t.dns_cutover_budget,
        "the holder expires before the step timeout plus the cutover budget",
    );
    // A holder of a play that the step timeout stopped has this long left at most; the next publication waits longer than that.
    assert(
        t.dns_lock_wait >= t.dns_lock_hold - t.dns_phase_timeout,
        "the next publication gives up before a dead holder has expired",
    );
    // The waiter must still have time to work inside its own step timeout.
    assert(t.dns_lock_wait < t.dns_phase_timeout, "the wait is longer than the step timeout");
    assert(
        t.dns_lock_job_timeout > t.dns_lock_wait + t.dns_lock_hold,
        "the async job ends before the holder",
    );
});

Deno.test("the workflow stops the phase at the phase timeout of the variables", async () => {
    const t = await times();
    const workflow = await Deno.readTextFile(`${ROOT}/workflows/workflow-dns-publish.yaml`);
    const match = workflow.match(/run: timeout (\d+) \/opt\/orbit\/ansible\/venv\/bin\/ansible-playbook/);
    assert(match, "the phase command has no timeout");
    assertEquals(Number(match[1]), t.dns_phase_timeout);
});

Deno.test("the playbook takes its lock, cutover and wait times from the variables", async () => {
    const playbook = await Deno.readTextFile(`${ROOT}/ansible/dns-publish.yml`);
    for (
        const use of [
            '- "{{ dns_lock_wait }}"',
            '- "{{ dns_lock_hold }}"',
            'async: "{{ dns_lock_job_timeout }}"',
            'async: "{{ dns_cutover_budget }}"',
            'retries: "{{ dns_cutover_budget }}"',
        ]
    ) assert(playbook.includes(use), `the playbook does not use ${use}`);
});
