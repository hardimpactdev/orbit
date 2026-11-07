import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";

/**
 * A step that changes an external system without writing Swamp data cannot be seen by the scheduler (Swamp, design/enablers/remote-execution.md,
 * "Write-bearing classification"). When a worker is lost, Swamp runs a step that has not written again and fails a step that has. A step that
 * cannot run twice (git clone, a mint, a revoke, useradd) must therefore declare `writes: true`, or the second run fails it or leaves a second token.
 *
 * Swamp default: the `writes: true` key, and the rule that a step which changes an external system declares it.
 * Ours: this test. Every step with an external effect declares `writes: true` or is listed in `writes-reviewed.json` with a reason, and no step that
 * matches a non-idempotent pattern may be listed.
 */

type Step = { name: string; writes?: boolean; task?: { type?: string; behaviorType?: string; methodName?: string; inputs?: Record<string, unknown> } };
type Job = { name: string; writes?: boolean; steps: Step[] };
type Workflow = { writes?: boolean; jobs: Job[] };
type Reason = "read-only" | "idempotent" | "unreviewed";
type Reviewed = { maxUnreviewed: number; steps: Record<string, Record<string, Reason>> };

const workflows = new URL("../workflows/", import.meta.url);

/** The behaviors that change something outside Swamp: SSH, shell, a configuration apply, the Gateway's certificate signing. */
export function hasExternalEffect(step: Step): boolean {
    const task = step.task;
    if (task?.type !== "behavior") return false;
    const behavior = task.behaviorType ?? "";
    if (behavior === "@swamp/ssh") return ["exec", "copy", "script"].includes(task.methodName ?? "");
    if (behavior === "command/shell") return true;
    if (behavior.startsWith("@swamp/cfgmgmt/")) return task.methodName !== "check";
    return behavior === "@nckrtl/certificate-sign";
}

/** The arguments of an `argv` list, each as one string (a CEL list written as `${{ [...] }}` gives its quoted literals), and the shell texts of the step. */
export function parts(step: Step): { args: string[]; scripts: string[] } {
    const inputs = step.task?.inputs ?? {};
    const argv = inputs.argv;
    const args = Array.isArray(argv)
        ? argv.map(String)
        : typeof argv === "string" ? [...argv.matchAll(/"([^"]*)"|'([^']*)'/g)].map((match) => match[1] ?? match[2]) : [];
    const run = typeof inputs.run === "string" ? [inputs.run] : [];
    return { args, scripts: [...run, ...args.filter((arg) => /\s/.test(arg))] };
}

/** HTTP methods whose second request does not leave the system as the first did. DELETE and PUT repeat harmlessly. */
const NOT_REPEATABLE = "POST|PATCH";
const INCUS_LIFECYCLE = ["launch", "init", "delete", "copy", "move", "rename", "restore", "publish", "snapshot"];
const INCUS_VALUE_FLAGS = ["--project", "-p", "--user", "--group", "--env", "--cwd", "--mode", "--target", "--timeout"];
const GIT_WRITING = "commit|push|tag|am|merge|rebase|cherry-pick";

/** The sub-command of `incus`: the first argument after it that is neither a flag nor the value of one. */
function incusVerb(args: string[]): string | undefined {
    const at = args.findIndex((arg) => arg.split("/").pop() === "incus");
    if (at < 0) return undefined;
    for (let i = at + 1; i < args.length; i++) {
        if (INCUS_VALUE_FLAGS.includes(args[i])) { i += 1; continue; }
        if (!args[i].startsWith("-")) return args[i];
    }
    return undefined;
}

/** Why a command cannot run twice, or null. A pattern list, not a parser: it names the verbs that fail or double on a second run. */
export function unsafeVerb(step: Step): string | null {
    const { args, scripts } = parts(step);
    const base = args.map((arg) => arg.split("/").pop()!);
    const has = (...names: string[]) => names.some((name) => args.includes(name));
    const text = scripts.join("\n");
    if (base.includes("git")) {
        if (has("clone")) return "git clone";
        if (has("switch") && has("-c", "--create")) return "git switch -c";
        if (has("checkout") && has("-b")) return "git checkout -b";
        if (has("commit", "push", "tag", "am", "merge", "rebase", "cherry-pick") && !has("-l", "--list", "--dry-run")) return "git history write";
        if (has("worktree") && has("add", "remove", "move", "prune")) return "git worktree change";
        if (has("remote") && has("add")) return "git remote add";
    }
    if (/\bgit\s+(?:-\S+\s+(?:\S+\s+)?)*clone(?![\w-])/.test(text)) return "git clone";
    if (new RegExp(`\\bgit\\s+(?:-\\S+\\s+(?:\\S+\\s+)?)*(${GIT_WRITING})(?![\\w-])(?![^\\n]*(--dry-run|\\s-l\\b))`).test(text)) return "git history write";
    if (/\bgit\b[^\n]*\b(switch\s+(-c|--create)|checkout\s+-b)\b/.test(text)) return "git branch create";
    // An API call that repeats badly: the method is a flag value (`--method POST`, `-X POST`, `--request POST`, `--method=POST`, `-XPOST`).
    const flag = args.findIndex((arg) => ["--method", "-X", "--request"].includes(arg));
    const inline = args.map((arg) => arg.match(/^(?:--method=|--request=|-X)(POST|PATCH)$/i)?.[1]).find(Boolean);
    const method = flag >= 0 ? args[flag + 1] : inline;
    if (method !== undefined && new RegExp(`^(${NOT_REPEATABLE})$`, "i").test(method)) return `API ${method.toUpperCase()}`;
    const shellMethod = text.match(new RegExp(`(?:--method|--request|-X)[=\\s]*(${NOT_REPEATABLE})\\b`, "i"))?.[1];
    if (shellMethod) return `API ${shellMethod.toUpperCase()}`;
    if (base.includes("gh") && has("api") && has("-f", "-F", "--field", "--raw-field", "--input") && !args.some((arg, i) => arg === "--method" && args[i + 1] === "GET")) return "gh api with fields (POST)";
    if (base.includes("curl") && has("--data", "--data-binary", "--data-raw", "--json", "-d", "-F", "--form")) return "curl with a body";
    if (/\bcurl\b[^\n]*(--data(-binary|-raw)?\b|--json\b|\s-d\s)/.test(text)) return "curl with a body";
    if (base.some((arg) => ["useradd", "usermod", "adduser", "userdel"].includes(arg)) || /\b(useradd|usermod|adduser|userdel)\b/.test(text)) return "account change";
    const verb = incusVerb(args);
    if (verb !== undefined && INCUS_LIFECYCLE.includes(verb)) return "incus lifecycle";
    if (new RegExp(`\\bincus\\s+(?:-\\S+\\s+\\S+\\s+)*(${INCUS_LIFECYCLE.join("|")})\\b`).test(text)) return "incus lifecycle";
    if (base.includes("mv") || /(?:^|[;&|]\s*|\n\s*)mv\s/.test(text)) return "mv";
    return null;
}

async function steps(): Promise<{ key: string; file: string; step: Step; declared: boolean }[]> {
    const found: { key: string; file: string; step: Step; declared: boolean }[] = [];
    for await (const entry of Deno.readDir(workflows)) {
        if (!entry.name.endsWith(".yaml")) continue;
        const workflow = parse(await Deno.readTextFile(new URL(entry.name, workflows))) as Workflow;
        for (const job of workflow.jobs) {
            for (const step of job.steps) {
                if (!hasExternalEffect(step)) continue;
                const declared = (step.writes ?? job.writes ?? workflow.writes) === true;
                found.push({ key: entry.name.replace(/^workflow-/, "").replace(/\.yaml$/, ""), file: entry.name, step, declared });
            }
        }
    }
    return found;
}

async function reviewed(): Promise<Reviewed> {
    return JSON.parse(await Deno.readTextFile(new URL("writes-reviewed.json", import.meta.url))) as Reviewed;
}

Deno.test("every step with an external effect declares writes: true or is reviewed", async () => {
    const list = await reviewed();
    const missing = (await steps()).filter(({ declared, key, step }) => !declared && list.steps[key]?.[step.name] === undefined)
        .map(({ key, step }) => `${key} / ${step.name}`);
    assertEquals(
        missing,
        [],
        "These steps change an external system and do not declare `writes: true`. If a second run is not harmless, declare it " +
            "(a lost dispatch then fails the step instead of running it again). If it is read-only or idempotent, add it to writes-reviewed.json with that reason.",
    );
});

Deno.test("a step that cannot run twice declares writes, and is not listed as reviewed", async () => {
    const list = await reviewed();
    const unsafe = (await steps()).filter(({ declared }) => !declared).flatMap(({ key, step }) => {
        const verb = unsafeVerb(step);
        return verb === null ? [] : [`${key} / ${step.name}: ${verb}`];
    });
    assertEquals(unsafe, [], "These steps run a command that fails or doubles on a second run; declare `writes: true`.");
    for (const [key, entries] of Object.entries(list.steps)) {
        for (const [name, reason] of Object.entries(entries)) assert(["read-only", "idempotent", "unreviewed"].includes(reason), `${key} / ${name}: reason ${reason}`);
    }
});

Deno.test("the reviewed list holds no stale entry and its unreviewed count only shrinks", async () => {
    const list = await reviewed();
    const lacking = new Set((await steps()).filter(({ declared }) => !declared).map(({ key, step }) => `${key} / ${step.name}`));
    const entries = Object.entries(list.steps).flatMap(([key, steps]) => Object.keys(steps).map((name) => `${key} / ${name}`));
    assertEquals(entries.filter((entry) => !lacking.has(entry)), [], "Remove the entries of steps that now declare writes or no longer exist.");
    const unreviewed = Object.values(list.steps).flatMap((steps) => Object.values(steps)).filter((reason) => reason === "unreviewed").length;
    assertEquals(unreviewed, list.maxUnreviewed, "maxUnreviewed must equal the count: lower it when a step is reviewed, never raise it.");
});

Deno.test("the pattern finds the commands that cannot run twice", () => {
    const step = (argv: string[]): Step => ({ name: "x", task: { type: "behavior", behaviorType: "@swamp/ssh", methodName: "exec", inputs: { argv } } });
    assertEquals(unsafeVerb(step(["/usr/bin/git", "clone", "--", "a.bundle", "dir"])), "git clone");
    assertEquals(unsafeVerb(step(["git", "-C", "dir", "switch", "-c", "task-1"])), "git switch -c");
    assertEquals(unsafeVerb(step(["curl", "-X", "POST", "https://x"])), "API POST");
    assertEquals(unsafeVerb(step(["curl", "--request", "PATCH", "https://x"])), "API PATCH");
    assertEquals(unsafeVerb(step(["gh", "api", "--method=POST", "repos/x/y/issues"])), "API POST");
    assertEquals(unsafeVerb(step(["gh", "api", "repos/x/y/issues", "-f", "title=a"])), "gh api with fields (POST)");
    assertEquals(unsafeVerb(step(["/usr/sbin/useradd", "--create-home", "orbit"])), "account change");
    assertEquals(unsafeVerb(step(["incus", "--project", "p", "delete", "--force", "vm"])), "incus lifecycle");
    const shell = (run: string): Step => ({ name: "x", task: { type: "behavior", behaviorType: "command/shell", methodName: "execute", inputs: { run } } });
    assertEquals(unsafeVerb(shell("gh api --method POST repos/x/y/issues")), "API POST");
    assertEquals(unsafeVerb(shell("git clone --branch main -- \"$URL\" \"$DIR\"")), "git clone");
    assertEquals(unsafeVerb(shell("curl --fail --data \"$BODY\" \"$URL\"")), "curl with a body");
    // Commands that read, or that a second run leaves as they were. DELETE and PUT repeat harmlessly (a second token revoke answers 401).
    assertEquals(unsafeVerb(step(["gh", "api", "--method", "DELETE", "installation/token"])), null);
    assertEquals(unsafeVerb(shell("timeout 30 gh api --method DELETE installation/token")), null);
    assertEquals(unsafeVerb(step(["incus", "exec", "vm", "--", "git", "init"])), null);
    assertEquals(unsafeVerb(step(["incus", "--project", "p", "exec", "vm", "--", "cloud-init", "status"])), null);
    assertEquals(unsafeVerb(shell("git -C \"$D\" commit-tree HEAD^{tree} -m x")), null);
    assertEquals(unsafeVerb(shell("git -C \"$D\" merge-base HEAD origin/main")), null);
    assertEquals(unsafeVerb(shell("echo \"waiting for mv to finish\"")), null);
    assertEquals(unsafeVerb(step(["incus", "--project=p", "delete", "vm"])), "incus lifecycle");
    assertEquals(unsafeVerb(step(["git", "-C", "dir", "rev-parse", "refs/heads/candidate^{commit}"])), null);
    assertEquals(unsafeVerb(shell("git -C \"$DIR\" rev-parse HEAD")), null);
    assertEquals(unsafeVerb(step(["git", "-C", "dir", "rev-parse", "HEAD"])), null);
    assertEquals(unsafeVerb(step(["incus", "exec", "vm", "--", "cloud-init", "status", "--wait"])), null);
    assertEquals(unsafeVerb(step(["git", "checkout", "-B", "task-1", "abc"])), null);
    assertEquals(unsafeVerb(step(["gh", "api", "--method", "GET", "repos/x/y"])), null);
});

Deno.test("the external-effect steps are the ones whose second run is outside Swamp's sight", () => {
    const behavior = (behaviorType: string, methodName: string): Step => ({ name: "x", task: { type: "behavior", behaviorType, methodName } });
    assert(hasExternalEffect(behavior("@swamp/ssh", "exec")));
    assert(hasExternalEffect(behavior("command/shell", "execute")));
    assert(hasExternalEffect(behavior("@swamp/cfgmgmt/file", "apply")));
    assert(!hasExternalEffect(behavior("@swamp/cfgmgmt/file", "check")));
    assert(!hasExternalEffect(behavior("@nckrtl/task-provision-data", "pin")));
    assert(!hasExternalEffect({ name: "x", task: { type: "workflow" } }));
});
