// Prototype: turn one case of a swamp/proof/<family>/<command>.cases.yaml into a Swamp workflow of native steps.
//
//   deno run -A swamp/bin/proof-case-workflow.ts --file swamp/proof/firewall/allow.cases.yaml --case new-restricted-allow --out proof-case.yaml
//
// The workflow is generated at proof time and is not committed. Every check is a step, so the status of the run is the verdict:
//   - `@swamp/ssh exec` with `okExitCodes` for traffic probes and CLI exit codes;
//   - a core `assert` step with a CEL expression for text (ufw rows and their order, before/after equality);
//   - `jq -e` in argv, with the earlier CLI output on `stdin`, where JSON must be read (CEL has no JSON parse).
// Vocabulary built: listener, ufw_rule, probe, cli, record, ufw, unchanged, doctor. Any other step stops the generator.
import { parse, stringify } from "jsr:@std/yaml@1.0.12";

type Obj = Record<string, unknown>;
/** A piece of a string value: a literal, or a CEL expression that reads the output of earlier steps. */
type Part = string | { cel: string; deps: string[] };
interface Step {
    name: string;
    job: string;
    deps: string[];
    task: Obj;
    writes: boolean;
}

const CLI_ENV = {
    PATH: "/usr/local/bin:/usr/bin:/bin",
    HOME: "/home/orbit",
    ORBIT_HOME: "/home/orbit/.orbit",
};
const REPORT_SECONDS = 65; // the agents report at a 60 s tick (swamp/proof/runner/steps.py)
const PROBE_SECONDS = 3;
const JOBS = ["discover", "setup", "act", "expect"] as const;
/**
 * Steps that share a behavior name run one after another: the model's data lock serializes them (measured: three 1 s steps
 * took 3.4 s). The steps of a job are spread over a few lanes, each its own `@swamp/ssh` name, so they run in parallel
 * while the names stay per target and few (docs/cli/README.md).
 */
const LANES = 4;
const sshName = (lane: number) =>
    `\${{ ("proof-verify-ssh-" + string(inputs.ssh_execution.target.node_id) + "-l${lane}").split(".").join("-") }}`;

export class NotBuilt extends Error {}

/** CEL reads `steps.NAME`, so a step name is an identifier: letters, digits and underscores. */
const id = (name: string) => name.replace(/[^A-Za-z0-9_]/g, "_");
const out = (step: string, expr = "$"): Part => ({
    cel: expr.replaceAll("$", `steps.${id(step)}.outputs.stdout`),
    deps: [id(step)],
});

/** Literal parts become a plain string, anything with a reference becomes one CEL expression. */
export function render(parts: Part[]): string {
    if (parts.every((part) => typeof part === "string")) return parts.join("");
    return "${{ " +
        parts.map((part) =>
            typeof part === "string" ? JSON.stringify(part) : `(${part.cel})`
        ).join(" + ") + " }}";
}
/** Several parts as one part, so a single argv word can hold a reference. */
const joined = (parts: Part[]): Part =>
    parts.length === 1 ? parts[0] : { cel: cel(parts), deps: depsOf(parts) };
const depsOf = (parts: Part[]) =>
    parts.flatMap((part) => typeof part === "string" ? [] : part.deps);
const cel = (parts: Part[]) =>
    parts.map((part) =>
        typeof part === "string" ? JSON.stringify(part) : `(${part.cel})`
    ).join(" + ") || '""';

export class CaseWorkflow {
    steps: Step[] = [];
    private names: Record<string, string> = {};
    private job = "discover";
    private barrier: string[] = [];
    /** Per provider of `unchanged`: the step that read it before `act` and the CEL value that compares. */
    private baseline: Record<string, { read: string; norm: string }> = {};
    private target: string;
    private sources: string[];
    private fixtures: Obj;
    private vars: Obj;
    private count: Record<string, number> = {};

    constructor(
        private file: Obj,
        private caseId: string,
        private skipAct = false,
    ) {
        this.target = String(file.target);
        this.sources = file.sources as string[];
        this.fixtures = (file.fixtures ?? {}) as Obj;
        this.vars = (file.vars ?? {}) as Obj;
    }

    // ---- steps ---------------------------------------------------------------------------------------------------
    private add(
        name: string,
        task: Obj,
        deps: string[],
        writes = false,
    ): string {
        const base = id(name);
        const unique = (this.count[base] = (this.count[base] ?? 0) + 1) > 1
            ? `${base}_${this.count[base]}`
            : base;
        this.steps.push({
            name: unique,
            job: this.job,
            deps: [...new Set(deps)],
            task,
            writes,
        });
        return unique;
    }
    /** One `@swamp/ssh exec` of an argv on the task VM; the exit code is the check. */
    private exec(
        name: string,
        argv: Part[],
        ok: number[] | "any" = [0],
        more: {
            stdin?: Part[];
            env?: Obj;
            seconds?: number;
            writes?: boolean;
            after?: string[];
        } = {},
    ): string {
        const stdin = more.stdin ?? [];
        return this.add(
            name,
            {
                type: "behavior",
                behaviorType: "@swamp/ssh",
                behaviorName: "LANE",
                methodName: "exec",
                globalArgs: "SSH",
                inputs: {
                    hosts: "name:target",
                    okExitCodes: ok,
                    timeoutSec: more.seconds ?? 60,
                    argv: argv.map((part) => render([part])),
                    ...(stdin.length ? { stdin: render(stdin) } : {}),
                    ...(more.env ? { env: more.env } : {}),
                },
            },
            [...depsOf(argv), ...depsOf(stdin), ...(more.after ?? [])],
            more.writes,
        );
    }
    private assert(
        name: string,
        expr: string,
        message: string,
        deps: string[],
    ): string {
        return this.add(name, { type: "assert", expr, message }, deps);
    }
    /** Run `jq` on the stdout of an earlier step. With `-e` a false or null result fails the step. */
    private jq(
        name: string,
        from: string,
        filter: string,
        args: Array<[string, Part[]]> = [],
        flags = ["-e"],
    ): string {
        const argv: Part[] = [
            "jq",
            ...flags,
            ...args.flatMap(([key, value]) => ["--arg", key, joined(value)]),
            filter,
        ];
        return this.exec(name, argv, [0], { stdin: [out(from)] });
    }
    private incus(node: string, command: Part[]): Part[] {
        return ["incus", "exec", `nested-${node}`, "--", ...command];
    }
    private orbit(args: Part[]): Part[] {
        return [
            "php",
            { cel: 'inputs.checkout_path + "/apps/cli/orbit"', deps: [] },
            ...args,
            "--json",
        ];
    }
    private cliExec(
        name: string,
        args: Part[],
        ok: number[] | "any" = [0],
        after: string[] = [],
    ): string {
        return this.exec(name, this.orbit(args), ok, {
            env: CLI_ENV,
            seconds: 240,
            writes: true,
            after,
        });
    }

    // ---- values --------------------------------------------------------------------------------------------------
    private at<T>(job: string, make: () => T): T {
        const was = this.job;
        this.job = job;
        try {
            return make();
        } finally {
            this.job = was;
        }
    }
    private cached(name: string, make: () => string): string {
        return this.steps.find((step) => step.name === id(name))?.name ??
            this.at("discover", make);
    }
    private nodeId(node: string): Part {
        const list = this.cached(
            "nodes",
            () => this.cliExec("nodes", ["node:list"]),
        );
        return out(
            this.cached(`id-${node}`, () =>
                this.jq(
                    `id-${node}`,
                    list,
                    `.nodes[]|select(.name==${JSON.stringify(node)})|.id`,
                    [],
                    ["-r"],
                )),
            "$.trim()",
        );
    }
    private bridge(node: string): Part {
        // The address of the first interface that is not the Node's own tunnel or Docker, as the runner's bridge_address picks it.
        const read = this.cached(
            `bridge-${node}`,
            () =>
                this.exec(
                    `bridge-${node}`,
                    this.incus(node, [
                        "ip",
                        "-4",
                        "-o",
                        "addr",
                        "show",
                        "scope",
                        "global",
                    ]),
                ),
        );
        return out(
            read,
            '$.split("\\n").filter(l, l.contains("inet ") && !l.contains(" orbit ") && !l.contains(" docker0 "))[0].split("inet ")[1].split("/")[0]',
        );
    }
    private source(node: string): Part {
        const read = this.cached(
            `src-${node}`,
            () =>
                this.exec(
                    `src-${node}`,
                    this.incus(node, [
                        "ip",
                        "-4",
                        "route",
                        "get",
                        this.bridge(this.target),
                    ]),
                ),
        );
        return out(read, '$.split(" src ")[1].split(" ")[0]');
    }
    private lookup = (name: string): Part[] => {
        if (name in this.names) return [this.names[name]];
        const found = name.match(/^(gateway|app-dev|app-prod)\.(id|src)$/);
        if (!found) throw new NotBuilt(`placeholder \${${name}}`);
        return [
            found[2] === "id" ? this.nodeId(found[1]) : this.source(found[1]),
        ];
    };
    private value(text: unknown): Part[] {
        const parts: Part[] = [];
        const source = String(text);
        let last = 0;
        for (const match of source.matchAll(/\$\{([^}]+)\}/g)) {
            if (match.index! > last) {
                parts.push(source.slice(last, match.index));
            }
            parts.push(...this.lookup(match[1]));
            last = match.index! + match[0].length;
        }
        if (last < source.length) parts.push(source.slice(last));
        return parts;
    }
    /** A value that holds no reference to another step, such as a port. */
    private plain(text: unknown): string {
        const parts = this.value(text);
        if (parts.some((part) => typeof part !== "string")) {
            throw new NotBuilt(`a literal is needed here, not ${text}`);
        }
        return parts.join("");
    }

    // ---- the case ------------------------------------------------------------------------------------------------
    private splice(items: unknown[]): unknown[] {
        return items.flatMap((item) =>
            typeof item === "string" && item.startsWith("@")
                ? this.splice(this.vars[item.slice(1)] as unknown[])
                : [item]
        );
    }
    private fixture(use: unknown): Obj[] {
        const given = typeof use === "string" ? { fixture: use } : (use as Obj);
        const fixture = this.fixtures[String(given.fixture)] as Obj | undefined;
        if (!fixture) throw new NotBuilt(`fixture ${given.fixture}`);
        const params = { ...(fixture.params as Obj), ...given };
        const swap = (value: unknown): unknown =>
            typeof value === "string"
                ? (/^\$\{\w+\}$/.test(value) && value.slice(2, -1) in params
                    ? params[value.slice(2, -1)]
                    : value.replace(
                        /\$\{(\w+)\}/g,
                        (all, key) => key in params ? String(params[key]) : all,
                    ))
                : Array.isArray(value)
                ? value.map(swap)
                : value && typeof value === "object"
                ? Object.fromEntries(
                    Object.entries(value).map(([k, v]) => [k, swap(v)]),
                )
                : value;
        return (fixture.steps as Obj[]).map((step) => swap(step) as Obj);
    }
    private flatten(section: unknown): Obj[] {
        return ((section ?? []) as Obj[]).flatMap((step) =>
            "use" in step ? this.fixture(step.use) : [step]
        );
    }

    generate(): Obj {
        const found = (this.file.cases as Obj[]).find((item) =>
            item.id === this.caseId
        );
        if (!found) throw new Error(`no case ${this.caseId}`);
        if (found.stages || found.variants) {
            throw new NotBuilt("stages and variants");
        }
        // The two test ports are fixed per case: CEL has no random choice.
        const seed = [...this.caseId].reduce(
            (sum, ch) => (sum * 31 + ch.charCodeAt(0)) % 400,
            7,
        );
        this.names = {
            port: String(47000 + seed * 2),
            control: String(47001 + seed * 2),
        };
        const sections: Array<[string, Obj[]]> = [
            ["setup", this.flatten(found.setup)],
            ["act", this.flatten(found.act)],
            ["expect", this.flatten(found.expect)],
        ];
        const unchanged = sections[2][1].find((step) => "unchanged" in step);
        for (const [job, items] of sections) {
            this.job = job;
            this.barrier = [];
            if (job === "act" && unchanged) {
                this.takeBaseline(unchanged.unchanged as Obj);
            }
            if (job === "act" && this.skipAct) continue;
            for (const item of items) this.step(item);
        }
        return this.document();
    }

    private step(item: Obj) {
        const [kind, raw] = Object.entries(item)[0];
        const args = (raw ?? {}) as Obj;
        switch (kind) {
            case "listener":
                return this.listener(args);
            case "ufw_rule":
                return this.ufwRule(args);
            case "probe":
                return this.probe(args);
            case "cli":
                return this.cli(args);
            case "record":
                return this.record(args);
            case "ufw":
                return this.ufw(args);
            case "unchanged":
                return this.unchanged(args);
            case "doctor":
                return this.doctor(args);
            default:
                throw new NotBuilt(`step ${kind}`);
        }
    }

    private listener(args: Obj) {
        if (
            Number(args.family ?? 4) !== 4 ||
            [args.proto ?? "tcp"].flat().some((proto) => proto !== "tcp")
        ) throw new NotBuilt("listener beyond tcp over IPv4");
        for (const name of args.ports as string[]) {
            const port = this.names[name];
            this.barrier.push(
                this.exec(
                    `listen-${name}`,
                    this.incus(String(args.on), [
                        "systemd-run",
                        `--unit=proof-listen-${port}`,
                        "--collect",
                        "--",
                        "python3",
                        "-m",
                        "http.server",
                        port,
                        "--bind",
                        "0.0.0.0",
                    ]),
                    [0],
                    { writes: true },
                ),
            );
        }
    }
    private ufwRule(args: Obj) {
        if (
            (args.from ?? "any") !== "any" || !args.above ||
            Number(args.family ?? 4) !== 4
        ) {
            throw new NotBuilt(
                "ufw_rule beyond a rule for anyone above an anchor",
            );
        }
        // IPv4 only (from 0.0.0.0/0), inserted first, so it sits above the allowance for the proof network.
        const name = String(args.as ?? "rule");
        this.barrier.push(
            this.exec(
                `ufw-${name}`,
                this.incus(String(args.on), [
                    "ufw",
                    "insert",
                    "1",
                    String(args.action),
                    "proto",
                    String(args.proto ?? "tcp"),
                    "from",
                    "0.0.0.0/0",
                    "to",
                    "any",
                    "port",
                    this.plain(args.port),
                    "comment",
                    `proof:${name}`,
                ]),
                [0],
                { writes: true, after: [...this.barrier] },
            ),
        );
    }
    private probe(args: Obj) {
        const from = [args.from].flat().flatMap((node) =>
            node === "${sources}" ? this.sources : [String(node)]
        );
        for (const node of from) {
            // `bash -c SCRIPT NAME ADDRESS PORT`: the values are positional arguments, not spliced into the script.
            const argv = this.incus(node, [
                "timeout",
                String(PROBE_SECONDS),
                "bash",
                "-c",
                'echo > "/dev/tcp/$1/$2"',
                "probe",
                this.bridge(String(args.to)),
                ...this.value(args.port),
            ]);
            this.exec(
                `probe-${args.id ?? `${this.plain(args.port)}`}-${node}`,
                argv,
                args.expect === "connect" ? [0] : [124],
                { after: [...this.barrier], seconds: PROBE_SECONDS + 20 },
            );
        }
    }
    private nodeFlag(node: string): Part {
        return joined(["--node=", this.nodeId(node)]);
    }
    private cliArgs(args: Obj): Part[] {
        return this.splice(args.run as unknown[]).map((word) =>
            joined(this.value(word))
        );
    }
    private cli(args: Obj) {
        const expect = (args.expect ?? {}) as Obj;
        if (args.forms || args.tty || args.as) {
            throw new NotBuilt("cli forms, tty or as");
        }
        const unknown = Object.keys(expect).filter((key) =>
            !["exit", "status", "code"].includes(key)
        );
        if (unknown.length) throw new NotBuilt(`cli expect ${unknown}`);
        const label = String((args.run as unknown[])[0]);
        const run = this.cliExec(
            `cli-${label.replace(":", "-")}`,
            this.cliArgs(args),
            expect.exit === undefined ? "any" : [Number(expect.exit)],
            this.baselineSteps(),
        );
        if (expect.status) {
            this.jq(
                `check-status-${label.replace(":", "-")}`,
                run,
                ".status==$s",
                [["s", [String(expect.status)]]],
            );
        }
        if (expect.code) {
            this.jq(
                `check-code-${label.replace(":", "-")}`,
                run,
                "(.error_code // .error.code)==$c",
                [["c", [String(expect.code)]]],
            );
        }
    }
    private record(args: Obj) {
        if (
            args.list !== "firewall:list" ||
            (args.section ?? "rules") !== "rules" || args.order ||
            args.min !== undefined
        ) throw new NotBuilt("record beyond count of firewall:list rules");
        const where = (args.where ?? {}) as Obj;
        if (
            Object.values(where).some((value) =>
                typeof value === "object" || value === "present"
            )
        ) throw new NotBuilt("record where with a list or present");
        const read = this.cliExec("list-records", [
            "firewall:list",
            ...(args.node ? [this.nodeFlag(String(args.node))] : []),
        ]);
        const keys = Object.keys(where);
        const filter = `[.nodes[].rules[]|select(${
            keys.map((_, i) => `(.${keys[i]}|tostring)==$k${i}`).join(
                " and ",
            ) || "true"
        })]|length==${Number(args.count)}`;
        this.jq(
            "check-record",
            read,
            filter,
            keys.map((
                key,
                i,
            ): [string, Part[]] => [`k${i}`, this.value(where[key])]),
        );
    }
    private ufwRead(name: string): string {
        return this.exec(
            name,
            this.incus(this.target, ["ufw", "status", "numbered"]),
        );
    }
    private ufw(args: Obj) {
        if (args.absent || Number(args.family ?? 4) !== 4) {
            throw new NotBuilt("ufw absent or family");
        }
        const read = this.ufwRead("read-ufw");
        const lines =
            `steps.${read}.outputs.stdout.split("\\n").filter(l, l.contains("]") && !l.contains("(v6)"))`;
        const rows = (args.rows as Obj[]).map((row) =>
            row.ref ? this.rule(String(row.ref)) : {
                to: this.plain(row.to),
                action: String(row.action),
                from: row.from ? this.value(row.from) : undefined,
                comment: undefined as string | undefined,
            }
        );
        const match = (
            row: {
                to: string;
                action: string;
                from?: Part[];
                comment?: string;
            },
        ) => [
            `l.contains(${JSON.stringify(row.to)})`,
            `l.contains(${JSON.stringify(row.action)})`,
            ...(row.from ? [`l.contains(${cel(row.from)})`] : []),
            ...(row.comment
                ? [`l.contains(${JSON.stringify("# " + row.comment)})`]
                : []),
        ].join(" && ");
        const numbers = rows.map((row) =>
            `${lines}.filter(l, ${
                match(row)
            }).map(l, int(l.substring(l.indexOf("[") + 1, l.indexOf("]")).trim()))`
        );
        const checks = [
            ...numbers.map((n) => `${n}.size() == 1`),
            ...numbers.slice(1).map((n, i) => `${numbers[i]}[0] < ${n}[0]`),
        ];
        this.assert(
            "check-ufw",
            checks.join(" && "),
            "ufw rows are missing or out of order",
            [read],
        );
    }
    private rule(name: string) {
        const rule = this.ruleOf(name);
        return {
            to: `${this.plain(rule.port)}/${rule.proto ?? "tcp"}`,
            action: String(rule.action).toUpperCase(),
            from: undefined as Part[] | undefined,
            comment: `proof:${name}`,
        };
    }
    private ruleOf(name: string): Obj {
        const found = this.flatten(
            ((this.file.cases as Obj[]).find((item) =>
                item.id === this.caseId
            ) as Obj).setup,
        ).map((step) => step.ufw_rule as Obj).find((rule) =>
            rule && (rule.as ?? "rule") === name
        );
        if (!found) throw new NotBuilt(`ufw row ref ${name}`);
        return found;
    }
    private providers(args: Obj): string[] {
        return (args.what as string[] | undefined) ??
            ((this.file.baseline as string[]) ?? []).filter((name) =>
                name !== "operations"
            );
    }
    private snapshot(
        provider: string,
        stage: "before" | "after",
    ): { read: string; norm: string } {
        const port = this.names.port;
        if (provider === "ufw") {
            const read = this.ufwRead(`${stage}-ufw`);
            return {
                read,
                norm:
                    `steps.${read}.outputs.stdout.split("\\n").filter(l, !l.contains(${
                        JSON.stringify(port)
                    })).map(l, l.contains("]") ? l.substring(l.indexOf("]") + 1).trim() : l.trim())`,
            };
        }
        if (provider === "listeners") {
            const read = this.exec(
                `${stage}-listeners`,
                this.incus(this.target, ["ss", "-Htlun"]),
            );
            return {
                read,
                norm:
                    `steps.${read}.outputs.stdout.split("\\n").filter(l, !l.contains(${
                        JSON.stringify(":" + port)
                    }))`,
            };
        }
        if (provider === "firewall_records") {
            const raw = this.cliExec(`${stage}-records-raw`, [
                "firewall:list",
                this.nodeFlag(this.target),
            ]);
            const read = this.jq(
                `${stage}-records`,
                raw,
                "[.nodes[].rules[]|select((.port|tostring)!=$p)]",
                [["p", [port]]],
                ["-S", "-c"],
            );
            return { read, norm: `steps.${read}.outputs.stdout` };
        }
        throw new NotBuilt(`baseline provider ${provider}`);
    }
    private takeBaseline(args: Obj) {
        if ((args.since ?? "act") !== "act") {
            throw new NotBuilt("unchanged since");
        }
        for (const provider of this.providers(args)) {
            this.baseline[provider] = this.snapshot(provider, "before");
        }
    }
    private baselineSteps(): string[] {
        return Object.values(this.baseline).map((snapshot) => snapshot.read);
    }
    private unchanged(args: Obj) {
        const except = (args.except ?? {}) as Obj;
        if (Object.keys(except).some((key) => key !== "port")) {
            throw new NotBuilt("unchanged except beyond port");
        }
        for (const provider of this.providers(args)) {
            const before = this.baseline[provider];
            const after = this.snapshot(provider, "after");
            this.assert(
                `check-unchanged-${provider}`,
                `${before.norm} == ${after.norm}`,
                `${provider} changed beyond the case's port`,
                [before.read, after.read],
            );
        }
    }
    private doctor(args: Obj) {
        // The agents report at a 60 s tick, in a phase the case cannot know. The expect job starts after act, so a
        // fixed wait under a literal `timeout` (swamp-shape rule 6) is enough. Budget: 70 s.
        const wait = this.exec(
            "wait-report",
            [
                "timeout",
                String(REPORT_SECONDS + 5),
                "sleep",
                String(REPORT_SECONDS),
            ],
            [0],
            { seconds: REPORT_SECONDS + 30 },
        );
        const run = this.cliExec(
            "doctor",
            ["doctor", this.nodeFlag(String(args.node))],
            [0],
            [wait],
        );
        this.jq("check-doctor", run, ".summary.drift==($d|tonumber)", [["d", [
            String(args.drift),
        ]]]);
    }

    // ---- the document --------------------------------------------------------------------------------------------
    document(): Obj {
        const ssh = (lane: number) => ({
            name: sshName(lane),
            transport: {
                kind: "ssh",
                user: "${{ inputs.ssh_execution.target.user }}",
                port: "${{ inputs.ssh_execution.target.port }}",
                identityFile: "${{ inputs.ssh_execution.identity_file }}",
            },
            hosts: [{
                name: "target",
                address: "${{ inputs.ssh_execution.target.host }}",
                transport: {
                    hostKeys: "${{ inputs.ssh_execution.target.host_keys }}",
                },
            }],
        });
        const lanes = Array.from({ length: LANES }, (_, lane) => ssh(lane));
        const used: Record<string, number> = {};
        const names = new Set(this.steps.map((step) => step.name));
        for (const step of this.steps) {
            for (const dep of step.deps) {
                if (!names.has(dep)) {
                    throw new Error(`${step.name} depends on unknown ${dep}`);
                }
            }
        }
        const jobs = JOBS.map((job, index) => ({
            name: job,
            labels: { role: "swamp" },
            ...(index
                ? {
                    dependsOn: [{
                        job: JOBS[index - 1],
                        condition: { type: "succeeded" },
                    }],
                }
                : {}),
            steps: this.steps.filter((step) => step.job === job).map((step) => {
                const inside = step.deps.filter((dep) =>
                    names.has(dep) &&
                    this.steps.find((other) => other.name === dep)!.job === job
                );
                const task = { ...step.task };
                if (task.globalArgs === "SSH") {
                    const lane = (used[job] = (used[job] ?? -1) + 1) %
                        LANES;
                    task.behaviorName = sshName(lane);
                    task.globalArgs = lanes[lane]; // one shared object per lane: the YAML writer turns it into an anchor
                }
                return {
                    name: step.name,
                    labels: { role: "swamp" },
                    ...(step.writes ? { writes: true } : {}),
                    ...(inside.length
                        ? {
                            dependsOn: inside.map((dep) => ({
                                step: dep,
                                condition: { type: "succeeded" },
                            })),
                        }
                        : {}),
                    task,
                };
            }),
        })).filter((job) => job.steps.length);
        return {
            id: uuid(`proof-case-${this.caseId}`),
            name: `proof-case-${this.caseId}`,
            description:
                `Generated from ${this.file.command} case ${this.caseId}. Not committed.`,
            vaults: [],
            queueTimeout: 30,
            inputs: {
                type: "object",
                properties: {
                    ssh_execution: { type: "object" },
                    checkout_path: { type: "string" },
                },
                required: ["ssh_execution", "checkout_path"],
                additionalProperties: false,
            },
            jobs,
        };
    }
}

function uuid(seed: string): string {
    const hex = [...seed].reduce(
        (sum, ch) => (sum * 131 + ch.charCodeAt(0)) >>> 0,
        7,
    ).toString(16).padStart(8, "0");
    return `${hex}-0000-4000-8000-${hex.padEnd(12, "0")}`;
}

export function generate(
    source: string,
    caseId: string,
    skipAct = false,
): string {
    const workflow = new CaseWorkflow(parse(source) as Obj, caseId, skipAct);
    return stringify(workflow.generate(), { lineWidth: 200 });
}

if (import.meta.main) {
    const flags = new Map<string, string>();
    const argv = Deno.args;
    for (let i = 0; i < argv.length; i++) {
        if (argv[i].startsWith("--")) {
            flags.set(
                argv[i],
                argv[i + 1]?.startsWith("--") || argv[i + 1] === undefined
                    ? ""
                    : argv[++i],
            );
        }
    }
    const file = flags.get("--file"), id = flags.get("--case");
    if (!file || !id) {
        console.error(
            "usage: proof-case-workflow.ts --file CASES.yaml --case ID [--out FILE] [--skip-act]",
        );
        Deno.exit(2);
    }
    const yaml = generate(
        await Deno.readTextFile(file),
        id,
        flags.has("--skip-act"),
    );
    if (flags.get("--out")) await Deno.writeTextFile(flags.get("--out")!, yaml);
    else console.log(yaml);
}
