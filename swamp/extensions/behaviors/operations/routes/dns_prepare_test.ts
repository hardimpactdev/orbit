import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { ROOT } from "../../../../bin/gate-test-support.ts";

/** The text of one step of the forward workflow, from its name to the next step. */
async function step(name: string): Promise<string> {
    const text = await Deno.readTextFile(
        `${ROOT}/workflows/workflow-route-mutation-forward.yaml`,
    );
    const start = text.indexOf(`  - name: ${name}\n`);
    assert(start >= 0, `${name} is missing`);
    const next = text.indexOf("\n  - name: ", start + 1);

    return text.slice(start, next < 0 ? undefined : next);
}

/** The three fields of a private DNS state that count as a change; the revision never does. */
const FIELDS = ["listen", "tlds", "records"];

Deno.test("forward_prepare_dns publishes only a prepared state that differs from the one before it", async () => {
    const prepare = await step("forward_prepare_dns");
    const grace = await step("forward_dns_grace");
    for (const field of FIELDS) {
        // The same revision-free compare as the grace wait: the field of the prepared state against the state before.
        assert(
            prepare.includes(
                `artifacts.dns[p.prepared.dns].${field} != inputs.intent.route_mutations.artifacts.dns[p.before.dns].${field}`,
            ),
            `forward_prepare_dns does not compare ${field} of the prepared and the before state`,
        );
        assert(
            grace.includes(
                `artifacts.dns[p.before.dns].${field} != inputs.intent.route_mutations.artifacts.dns[p.after.dns].${field}`,
            ),
            `forward_dns_grace does not compare ${field}`,
        );
    }
    assertEquals(prepare.includes(".revision"), false);
    // No state before it means nothing to compare with: publish.
    assert(prepare.includes("p.before.dns == null"));
});

Deno.test("the required count of forward_prepare_dns uses the same filter as its forEach", async () => {
    const text = await Deno.readTextFile(
        `${ROOT}/workflows/workflow-route-mutation-forward.yaml`,
    );
    const filter = (source: string, opener: RegExp) => {
        const match = source.match(opener);
        assert(match, `no match for ${opener}`);
        const start = match.index! + match[0].length;
        let depth = 1;
        let index = start;
        while (depth > 0 && index < source.length) {
            if (source[index] === "(") depth++;
            if (source[index] === ")") depth--;
            index++;
        }

        return source.slice(start, index - 1).replace(/\s+/g, " ");
    };
    const forEach = filter(
        await step("forward_prepare_dns"),
        /publications\.filter\(/,
    );
    const required = filter(
        text.slice(text.indexOf("          forward_prepare_dns:")),
        /publications\.filter\(/,
    );
    assertEquals(required, forEach);
});
