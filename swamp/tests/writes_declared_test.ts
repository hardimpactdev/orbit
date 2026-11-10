import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";

/**
 * A step that changes an external system without writing Swamp data cannot be seen by the scheduler (Swamp, design/enablers/remote-execution.md,
 * "Write-bearing classification"). When a worker is lost, Swamp runs a step that has not written again and fails a step that has. A step that
 * cannot run twice (git clone, a mint, useradd) must therefore declare `writes: true`, or the second run fails it or leaves a second token. A token revoke repeats
 * harmlessly (the second call answers 401), so it stays re-runnable.
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

/** HTTP methods whose second request does not leave the system as the first did. DELETE and PUT repeat (a second token revoke answers 401). */
const NOT_REPEATABLE = /^(POST|PATCH)$/i;
const INCUS_LIFECYCLE = ["launch", "init", "delete", "copy", "move", "rename", "restore", "publish", "snapshot", "create"];
const INCUS_FAMILIES = ["network", "storage", "profile", "image", "project"];
const INCUS_FAMILY_ACTIONS = ["create", "delete", "add", "remove", "import", "attach", "detach", "set", "unset", "edit"];
const INCUS_VALUE_FLAGS = ["--project", "-p", "--user", "--group", "--env", "--cwd", "--mode", "--target", "--timeout"];
const GIT_WRITING = ["commit", "push", "tag", "am", "merge", "rebase", "cherry-pick"];
const WRAPPERS = ["then", "do", "else", "{", "(", "!", "exec", "nohup", "command", "sudo", "timeout", "env"];

const basename = (word: string) => word.split("/").pop()!;

/** The words of one simple command of a shell text: quotes keep a quoted string as one word. */
function shellWords(segment: string): string[] {
    return (segment.match(/"[^"]*"|'[^']*'|\S+/g) ?? []).map((word) => word.replace(/^["']|["']$/g, ""));
}

/** The command that runs: the words after `then`, `do`, `timeout 30`, `sudo -n`, `env A=b` and the like. */
function head(words: string[]): string | undefined {
    let i = 0;
    while (i < words.length && WRAPPERS.includes(basename(words[i]))) {
        const wrapper = basename(words[i++]);
        if (wrapper === "timeout") while (i < words.length && /^(-\S+|\d+[smhd]?)$/.test(words[i])) i++;
        if (wrapper === "sudo") while (i < words.length && words[i].startsWith("-")) i++;
        if (wrapper === "env") while (i < words.length && (words[i].startsWith("-") || /^\w+=/.test(words[i]))) i++;
    }
    return words[i] === undefined ? undefined : basename(words[i]);
}

/** The sub-command of `incus`: the first argument after it that is neither a flag nor the value of one. */
function incusVerb(words: string[]): string | undefined {
    const at = words.findIndex((word) => basename(word) === "incus");
    if (at < 0) return undefined;
    const rest: string[] = [];
    for (let i = at + 1; i < words.length; i++) {
        if (INCUS_VALUE_FLAGS.includes(words[i])) { i += 1; continue; }
        if (!words[i].startsWith("-")) rest.push(words[i]);
        if (rest.length === 2) break;
    }
    if (rest[0] !== undefined && INCUS_FAMILIES.includes(rest[0])) return INCUS_FAMILY_ACTIONS.includes(rest[1]) ? `${rest[0]} ${rest[1]}` : undefined;
    return rest[0];
}

/** Why one command cannot run twice, or null. `shell` is true for the words of a shell text, where only the first command word counts for `mv`. */
function unsafeCommand(words: string[], shell: boolean): string | null {
    const base = words.map(basename);
    const has = (...names: string[]) => names.some((name) => words.includes(name));
    if (base.includes("git")) {
        if (has("clone")) return "git clone";
        if (has("switch") && has("-c", "--create")) return "git switch -c";
        if (has("checkout") && has("-b")) return "git checkout -b";
        if (GIT_WRITING.some((verb) => words.includes(verb)) && !has("-l", "--list", "--dry-run")) return "git history write";
        if (has("worktree") && has("add", "remove", "move", "prune")) return "git worktree change";
        if (has("remote") && has("add")) return "git remote add";
    }
    // An API call that repeats badly: the method is a flag value (`--method POST`, `-X POST`, `--request POST`, `--method=POST`, `-XPOST`).
    const flag = words.findIndex((word) => ["--method", "-X", "--request"].includes(word));
    const method = flag >= 0 ? words[flag + 1] : words.map((word) => word.match(/^(?:--method=|--request=|-X)(\w+)$/i)?.[1]).find(Boolean);
    if (method !== undefined && NOT_REPEATABLE.test(method)) return `API ${method.toUpperCase()}`;
    // `gh api` with fields and no method sends a POST.
    if (base.includes("gh") && has("api") && method === undefined && has("-f", "-F", "--field", "--raw-field", "--input")) return "gh api with fields (POST)";
    if (base.includes("curl") && has("--data", "--data-binary", "--data-raw", "--json", "-d", "-F", "--form")) return "curl with a body";
    if (base.some((word) => ["useradd", "usermod", "adduser", "userdel"].includes(word))) return "account change";
    if (base.includes("incus") && has("device") && has("add", "remove")) return "incus device change";
    const verb = incusVerb(words);
    if (verb !== undefined && (INCUS_LIFECYCLE.includes(verb) || verb.includes(" "))) return "incus lifecycle";
    if (shell ? head(words) === "mv" : base.includes("mv")) return "mv";
    return null;
}

/** Why a step cannot run twice, or null. A pattern list, not a parser: it names the verbs that fail or double on a second run. */
export function unsafeVerb(step: Step): string | null {
    const { args, scripts } = parts(step);
    const commands: { words: string[]; shell: boolean }[] = args.length > 0 ? [{ words: args, shell: false }] : [];
    for (const text of scripts) {
        for (const segment of text.replace(/\\\r?\n/g, "").split(/;|&&|\|\||\||\n/)) {
            const words = shellWords(segment);
            if (words.length > 0) commands.push({ words, shell: true });
        }
    }
    for (const { words, shell } of commands) {
        const why = unsafeCommand(words, shell);
        if (why !== null) return why;
    }
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

Deno.test("the reviewed list holds no stale entry and its unreviewed count is pinned", async () => {
    const list = await reviewed();
    const lacking = new Set((await steps()).filter(({ declared }) => !declared).map(({ key, step }) => `${key} / ${step.name}`));
    const entries = Object.entries(list.steps).flatMap(([key, steps]) => Object.keys(steps).map((name) => `${key} / ${name}`));
    assertEquals(entries.filter((entry) => !lacking.has(entry)), [], "Remove the entries of steps that now declare writes or no longer exist.");
    const unreviewed = Object.values(list.steps).flatMap((steps) => Object.values(steps)).filter((reason) => reason === "unreviewed").length;
    assertEquals(unreviewed, list.maxUnreviewed, "maxUnreviewed must equal the count. Lower it when a step is reviewed; a rise shows in the diff of the file and needs a reason.");
});

Deno.test("the pattern finds the commands that cannot run twice", () => {
    const step = (argv: string[]): Step => ({ name: "x", task: { type: "behavior", behaviorType: "@swamp/ssh", methodName: "exec", inputs: { argv } } });
    const shell = (run: string): Step => ({ name: "x", task: { type: "behavior", behaviorType: "command/shell", methodName: "execute", inputs: { run } } });
    // Commands that cannot run twice, in argv form and in shell text.
    assertEquals(unsafeVerb(step(["/usr/bin/git", "clone", "--", "a.bundle", "dir"])), "git clone");
    assertEquals(unsafeVerb(shell("git clone --branch main -- \"$URL\" \"$DIR\"")), "git clone");
    assertEquals(unsafeVerb(shell(["curl --fail \\", "  --header 'Content-Type: application/json' \\", "  --data \"$BODY\" \"$URL\""].join("\n"))), "curl with a body");
    assertEquals(unsafeVerb(step(["git", "-C", "dir", "switch", "-c", "task-1"])), "git switch -c");
    assertEquals(unsafeVerb(step(["git", "-C", "dir", "worktree", "add", "--detach", "d", "HEAD"])), "git worktree change");
    assertEquals(unsafeVerb(shell("git -C \"$D\" worktree add \"$P\" HEAD")), "git worktree change");
    assertEquals(unsafeVerb(shell("git -C \"$D\" remote add origin \"$U\"")), "git remote add");
    assertEquals(unsafeVerb(step(["curl", "-X", "POST", "https://x"])), "API POST");
    assertEquals(unsafeVerb(step(["curl", "-XPOST", "https://x"])), "API POST");
    assertEquals(unsafeVerb(step(["curl", "--request", "PATCH", "https://x"])), "API PATCH");
    assertEquals(unsafeVerb(step(["gh", "api", "--method=POST", "repos/x/y/issues"])), "API POST");
    assertEquals(unsafeVerb(shell("gh api --method POST repos/x/y/issues")), "API POST");
    assertEquals(unsafeVerb(step(["gh", "api", "repos/x/y/issues", "-f", "title=a"])), "gh api with fields (POST)");
    assertEquals(unsafeVerb(step(["curl", "--data-raw", "{}", "https://x"])), "curl with a body");
    assertEquals(unsafeVerb(shell("curl --fail --data \"$BODY\" \"$URL\"")), "curl with a body");
    assertEquals(unsafeVerb(step(["/usr/sbin/useradd", "--create-home", "orbit"])), "account change");
    assertEquals(unsafeVerb(step(["incus", "--project", "p", "delete", "--force", "vm"])), "incus lifecycle");
    assertEquals(unsafeVerb(step(["incus", "--project=p", "delete", "vm"])), "incus lifecycle");
    assertEquals(unsafeVerb(shell("incus --project=p delete vm")), "incus lifecycle");
    assertEquals(unsafeVerb(shell("incus --force-local delete vm")), "incus lifecycle");
    assertEquals(unsafeVerb(step(["incus", "network", "create", "br0"])), "incus lifecycle");
    assertEquals(unsafeVerb(step(["incus", "config", "device", "add", "vm", "d", "disk"])), "incus device change");
    assertEquals(unsafeVerb(shell("if [ -e a ]; then mv a b; fi")), "mv");
    assertEquals(unsafeVerb(shell("git push origin x; ls -l")), "git history write");
    assertEquals(unsafeVerb(shell("git commit -m 'drop -l flag'")), "git history write");
    // Commands that read, or that a second run leaves as they were. DELETE and PUT repeat (a second token revoke answers 401).
    assertEquals(unsafeVerb(step(["gh", "api", "--method", "DELETE", "installation/token"])), null);
    assertEquals(unsafeVerb(shell("timeout 30 gh api --method DELETE installation/token")), null);
    assertEquals(unsafeVerb(step(["gh", "api", "--method", "GET", "repos/x/y", "-f", "state=open"])), null);
    assertEquals(unsafeVerb(step(["gh", "api", "-X", "GET", "repos/x/y", "-f", "state=open"])), null);
    assertEquals(unsafeVerb(step(["gh", "api", "--method=get", "repos/x/y", "-f", "state=open"])), null);
    assertEquals(unsafeVerb(step(["git", "-C", "dir", "rev-parse", "refs/heads/candidate^{commit}"])), null);
    assertEquals(unsafeVerb(shell("git -C \"$DIR\" rev-parse HEAD")), null);
    assertEquals(unsafeVerb(shell("git -C \"$D\" commit-tree HEAD^{tree} -m x")), null);
    assertEquals(unsafeVerb(shell("git -C \"$D\" merge-base HEAD origin/main")), null);
    assertEquals(unsafeVerb(step(["git", "tag", "-l"])), null);
    assertEquals(unsafeVerb(step(["git", "push", "--dry-run", "origin", "x"])), null);
    assertEquals(unsafeVerb(step(["git", "checkout", "-B", "task-1", "abc"])), null);
    assertEquals(unsafeVerb(step(["git", "-C", "d", "worktree", "list", "--porcelain"])), null);
    assertEquals(unsafeVerb(step(["incus", "exec", "vm", "--", "git", "init"])), null);
    assertEquals(unsafeVerb(step(["incus", "--project", "p", "exec", "vm", "--", "cloud-init", "status"])), null);
    assertEquals(unsafeVerb(step(["incus", "config", "set", "vm", "user.x=1"])), null);
    assertEquals(unsafeVerb(shell("echo \"waiting for mv to finish\"")), null);
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
