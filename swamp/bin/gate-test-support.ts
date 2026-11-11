import { assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { dirname, fromFileUrl } from "jsr:@std/path@1.1.4";
export const root = fromFileUrl(new URL("../", import.meta.url));
export type Task = {
    type?: string;
    behaviorType?: string;
    behaviorName?: string;
    methodName?: string;
    globalArgs?: Record<string, unknown>;
    inputs?: Record<string, unknown>;
    workflowIdOrName?: string;
};
export type Step = {
    name: string;
    task: Task;
    labels?: unknown;
    writes?: unknown;
    [key: string]: unknown;
};
export type Flow = {
    id?: string;
    name?: string;
    inputs?: unknown;
    labels?: unknown;
    jobs: {
        name: string;
        dependsOn?: unknown;
        labels?: unknown;
        steps: Step[];
    }[];
};
export const ROOT = root.replace(/\/$/, "");
/** Resolve the constant CEL workflow target recognized by check-swamp-shape. */
export function resolveWorkflowTarget(target: string): string {
    return /^\$\{\{\s*"([\w.-]+)"\s*\}\}$/.exec(target)?.[1] ?? target;
}
/** Inspect a tracked workflow without starting the Swamp runtime. */
export function trackedStep(workflow: string, name: string) {
    const value = parse(
        Deno.readTextFileSync(`${ROOT}/workflows/workflow-${workflow}.yaml`),
    ) as {
        jobs: {
            steps: {
                name: string;
                task: { inputs: Record<string, unknown> };
            }[];
        }[];
    };
    const step = value.jobs.flatMap((job) => job.steps).find((step) =>
        step.name === name
    );
    if (!step) throw new Error(`${workflow} has no step ${name}`);
    return step;
}
export const script = (name: string) => root + "bin/" + name;
export async function run(
    command: string,
    args: string[],
    options: {
        cwd?: string;
        env?: Record<string, string>;
        input?: string;
        signal?: AbortSignal;
    } = {},
) {
    const child = new Deno.Command(command, {
        args,
        cwd: options.cwd,
        env: options.env,
        signal: options.signal,
        stdin: options.input === undefined ? "null" : "piped",
        stdout: "piped",
        stderr: "piped",
    }).spawn();
    const output = child.output();
    if (options.input !== undefined) {
        const writer = child.stdin.getWriter();
        await writer.write(new TextEncoder().encode(options.input));
        await writer.close();
    }
    const result = await output;
    return {
        code: result.code,
        stdout: new TextDecoder().decode(result.stdout),
        stderr: new TextDecoder().decode(result.stderr),
    };
}
export async function command(
    argv: string[],
    cwd?: string,
    env?: Record<string, string>,
    success = true,
) {
    const result = await run(argv[0], argv.slice(1), { cwd, env });
    assertEquals(result.code === 0, success, result.stdout + result.stderr);
    return result;
}
export async function temporary(body: (path: string) => Promise<void>) {
    const path = await Deno.makeTempDir({ prefix: "shape-fixture-" });
    try {
        await body(path);
    } finally {
        await Deno.remove(path, { recursive: true });
    }
}
export async function write(path: string, source: string) {
    await Deno.mkdir(dirname(path), { recursive: true });
    await Deno.writeTextFile(path, source);
}
export async function git(cwd: string, ...args: string[]) {
    const result = await run("git", args, { cwd });
    assertEquals(result.code, 0, result.stderr);
    return result.stdout.trim();
}
export async function init(path: string) {
    await git(path, "init", "--quiet");
    await git(path, "config", "user.name", "Shape fixture");
    await git(path, "config", "user.email", "shape@example.invalid");
}
export async function commit(path: string, message: string) {
    await git(path, "add", ".");
    await git(path, "commit", "--quiet", "-m", message);
    return await git(path, "rev-parse", "HEAD");
}
export const policyFiles = [
    "check-swamp-shape.ts",
    "bounded-phase-scripts.ts",
    "swamp-shape-relocations.ts",
    "workflow-placement.ts",
    "swamp-shape-python.py",
    "swamp_shape_host_tools.py",
    "swamp_shape_polling.py",
];
export async function copyPolicy(path: string) {
    for (const name of policyFiles) {
        await write(
            path + "/bin/" + name,
            await Deno.readTextFile(script(name)),
        );
    }
}
export const PHP_RUNTIME = String.raw`import contextlib
import fcntl
import os
import pathlib
import re
import stat

P = pathlib.Path
class Refused(Exception):
    pass
def check(value, reason):
    if not value:
        raise Refused(reason)
def directory(path):
    return path.is_dir()
class Host:
    def __init__(self):
        self.locks = P('/run/lock')
        self.root_uid = self.root_gid = 0
class Runtime:
    def __init__(self, host, data, home=False):
        self.user = data['user']
        check(re.fullmatch(r'[a-z_][a-z0-9_-]{0,31}', self.user), 'valid account')
@contextlib.contextmanager
def runtime_lock(h, user):
    check(directory(h.locks), "lock directory exists")
    fd = os.open(h.locks / ("orbit-php-" + user + ".lock"),
                 os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        m = os.fstat(fd)
        check(stat.S_ISREG(m.st_mode) and m.st_uid == h.root_uid
              and m.st_gid == h.root_gid
              and stat.S_IMODE(m.st_mode) == 0o600, "trusted runtime lock")
        fcntl.flock(fd, fcntl.LOCK_EX)
        yield
    finally:
        os.close(fd)
`;
export const PHP_ACTIONS = String
    .raw`from runtime_common import Host, Runtime, runtime_lock


def context():
    data = {'user': 'orbit_app'}
    host = Host()
    runtime = Runtime(host, data)
    return host, runtime, data
def stage(host, runtime, data):
    runtime = Runtime(host, data, True)
    with runtime_lock(host, runtime.user):
        pass
def execute(action):
    return action(*context())
`;
export const PHP_ENTRY = String.raw`from monitor_common import execute, stage

execute(stage)
`;

// Complete, inert source fixture for the bridge's host-tool custody contract.
// It is analyzed, never executed. Keep it independent of the recognizer's ASTs.
export const BRIDGE_WRITER = String.raw`import argparse
import fcntl
import json
import subprocess
import sys
import time
from contextlib import contextmanager
from pathlib import Path

REFRESH_BUDGET_SECONDS = 30
LIFECYCLE_BUDGET_SECONDS = 300


class PolicyError(Exception):
    pass


@contextmanager
def writer_lock(root):
    root.mkdir(parents=True, exist_ok=True)
    with (root / 'writer.lock').open('a') as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise PolicyError('instance.bridge_busy') from None
        yield


def apply(root, incoming, reconcile=False, withdraw=False):
    deadline = time.monotonic() + (REFRESH_BUDGET_SECONDS if incoming is None else LIFECYCLE_BUDGET_SECONDS)
    with writer_lock(root):
        try:
            return apply_locked(root, incoming, reconcile, withdraw, deadline)
        except (PolicyError, ValueError, OSError, subprocess.SubprocessError) as error:
            code = 'instance.bridge_state_unresolved' if unresolved_health(root) else failure_code(error)
            write_file(root / 'health.json', {'error_code': code})
            raise


def apply_locked(root, incoming, reconcile, withdraw, deadline):
    records = json.loads((root / 'endpoints.json').read_text())
    subprocess.run(['curl', '--max-time', '5', 'http://localhost/1.0/network-acls/orbitinst0-egress'],
                   input=json.dumps(records), timeout=deadline - time.monotonic(), check=True)
    write_file(root / 'endpoints.json', incoming)
    write_file(root / 'health.json', {'error_code': None})
    return 1


def write_file(path, value):
    path.write_text(json.dumps(value))


def unresolved_health(root):
    return json.loads((root / 'health.json').read_text()).get('error_code') == 'instance.bridge_state_unresolved'


def failure_code(error):
    if isinstance(error, subprocess.TimeoutExpired):
        return 'instance.bridge_command_timeout'
    return str(error) if str(error).startswith('instance.') else 'instance.container_bridge_write_failed'


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--state-dir', type=Path, default=Path('/var/lib/orbit/instance-bridge'))
    parser.add_argument('--intent', action='store_true')
    parser.add_argument('--reconcile', action='store_true')
    parser.add_argument('--withdraw', action='store_true')
    args = parser.parse_args()
    try:
        changed = apply(args.state_dir, json.load(sys.stdin) if args.intent else None, args.reconcile, args.withdraw)
        print(json.dumps({'changed': changed}))
    except (PolicyError, ValueError, OSError, subprocess.SubprocessError) as error:
        code = failure_code(error)
        print(json.dumps({'error_code': code}))
        print(str(error), file=sys.stderr)
        raise SystemExit(1) from error


if __name__ == '__main__':
    main()
`;
export const BRIDGE_ACQUIRE =
    "fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)";
export const bridgeContractViolations = [
    ["missing-lock", BRIDGE_WRITER.replace(BRIDGE_ACQUIRE, "pass")],
    [
        "early-unlock",
        BRIDGE_WRITER.replace(
            "        yield",
            "        lock.close()\n        yield",
        ),
    ],
    [
        "mutation-on-busy",
        BRIDGE_WRITER.replace(
            "        except BlockingIOError:\n",
            "        except BlockingIOError:\n            write_file(root / 'health.json', {'error_code': 'instance.bridge_busy'})\n",
        ),
    ],
    [
        "wait-loop",
        BRIDGE_WRITER.replace(
            "        try:\n            " + BRIDGE_ACQUIRE,
            "        while True:\n            time.sleep(0.05)\n        try:\n            " +
                BRIDGE_ACQUIRE,
        ),
    ],
] as const;
