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
export { resolveConstantName as resolveWorkflowTarget } from "./constant-name.ts";
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
    "constant-name.ts",
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
import ipaddress
import json
import subprocess
import sys
import tempfile
import time
from contextlib import contextmanager
from pathlib import Path

REFRESH_BUDGET_SECONDS = 30
LIFECYCLE_BUDGET_SECONDS = 300
CURL_TIMEOUT_SECONDS = 5


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
            state_file(root, 'health', {'error_code': code})
            raise


def apply_locked(root, incoming, reconcile, withdraw, deadline):
    records = json.loads((root / 'endpoints.json').read_text())
    api('orbitinst0-egress', records, 'etag', deadline)
    state_file(root, 'config', incoming)
    state_file(root, 'health', {'error_code': None})
    return 1


def state_file(root, kind, value=None, host=None, port=None, capture=None, remove=False):
    root = Path(root)
    if kind in ('config', 'health', 'pending'):
        path = root / {'config': 'config.json', 'health': 'health.json', 'pending': 'reconcile.pending'}[kind]
    elif kind in ('endpoint', 'staged'):
        host = str(ipaddress.IPv4Address(host))
        if type(port) is not int or not 1 <= port <= 65535 or type(capture) is not int or capture < 0:
            raise PolicyError('instance.container_bridge_write_failed')
        directory = 'endpoints' if kind == 'endpoint' else '.staged'
        path = root / directory / (host + '-' + str(port) + '-' + str(capture) + '.json')
    else:
        raise PolicyError('instance.container_bridge_write_failed')
    if remove:
        path.unlink(missing_ok=True)
        return 1
    content = json.dumps(value, sort_keys=True) + '\n'
    if path.exists() and path.read_text() == content:
        return 0
    path.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.NamedTemporaryFile(mode='w', dir=path.parent, delete=False) as stream:
        stream.write(content)
        temporary = Path(stream.name)
    temporary.chmod(0o644)
    temporary.replace(path)
    return 1


def api(name, value=None, etag=None, deadline=None):
    """Use the Incus API's ETag, so a concurrent write cannot replace another endpoint."""
    put = []
    if value is not None:
        if not isinstance(etag, str):
            raise PolicyError('instance.container_bridge_write_failed')
        put = ['--request', 'PUT', '--header', 'Content-Type: application/json',
               '--header', 'If-Match: ' + etag, '--data-binary', '@-']
    timeout = CURL_TIMEOUT_SECONDS if deadline is None else min(CURL_TIMEOUT_SECONDS, remaining(deadline))
    result = subprocess.run(['curl', '--silent', '--show-error', '--fail-with-body', '--max-time', str(CURL_TIMEOUT_SECONDS),
                             '--include', '--unix-socket', '/var/lib/incus/unix.socket',
                             'http://localhost/1.0/network-acls/' + name + '?project=default', *put],
                            input=None if value is None else json.dumps(value).encode(),
                            capture_output=True, check=False, timeout=timeout)
    if result.returncode == 28:
        raise PolicyError('instance.bridge_command_timeout')
    if result.returncode:
        detail = result.stderr.decode().strip()
        if b'\r\n\r\n' in result.stdout:
            detail += ' ' + json.loads(result.stdout.split(b'\r\n\r\n', 1)[1]).get('error', '')
        raise PolicyError(detail or 'instance.container_bridge_write_failed')
    headers, body = result.stdout.split(b'\r\n\r\n', 1)
    tags = [line.split(b':', 1)[1].strip().decode() for line in headers.splitlines()
            if line.lower().startswith(b'etag:')]
    response = json.loads(body)
    if response.get('error_code', 0):
        raise PolicyError(response.get('error', 'instance.container_bridge_write_failed'))
    return response['metadata'], tags[0] if tags else None


def remaining(deadline):
    seconds = deadline - time.monotonic()
    if seconds <= 0:
        raise PolicyError('instance.bridge_command_timeout')
    return seconds


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

// Analyze these sources only. Never execute a malicious writer fixture.
export const bridgeInodeViolations = [
    ["direct-lock-unlink", "    (root / 'writer.lock').unlink()\n"],
    ["computed-lock-unlink", "    (root / ('writer' + '.lock')).unlink()\n"],
    [
        "state-directory-cleanup",
        "    for path in root.iterdir():\n        path.unlink()\n",
    ],
    [
        "helper-lock-unlink",
        "    remove(root / ('writer' + '.lock'))\n",
        "def remove(path):\n    path.unlink()\n\n\n",
    ],
    [
        "helper-state-cleanup",
        "    clean(root)\n",
        "def clean(directory):\n    for path in directory.iterdir():\n        path.unlink()\n\n\n",
    ],
    [
        "lock-method-alias",
        "    remove_lock = (root / ('writer' + '.lock')).unlink\n    remove_lock()\n",
    ],
    ["directory-replacement", "    root.replace(root / 'other.json')\n"],
    [
        "old-arbitrary-path-helper",
        "    write_file(root / ('writer' + '.lock'), {})\n",
    ],
    [
        "helper-atomic-lock-replacement",
        "    state_file(root, ('writer' + '.lock'), {})\n",
    ],
    [
        "loop-carried-lock-unlink",
        "    path = root / 'health.json'\n    for record in (1, 2):\n        path.unlink(missing_ok=True)\n        path = root / ('writer' + '.lock')\n",
    ],
    [
        "match-lock-unlink",
        "    path = root / 'health.json'\n    match incoming:\n        case _:\n            path = root / ('writer' + '.lock')\n            path.unlink()\n",
    ],
    [
        "unused-destructive-helper",
        "",
        "def unused(path):\n    path.unlink()\n\n\n",
    ],
    ["direct-remove-call", "    remove(root)\n"],
    ["rmtree-style-call", "    root.rmtree()\n"],
    [
        "custody-helper-alias",
        "    mutate = state_file\n    mutate(root, 'health', {})\n",
    ],
    ["custody-helper-callback", "    list(map(state_file, []))\n"],
    [
        "unowned-temporary-cleanup",
        "    with tempfile.TemporaryDirectory(dir=root) as directory:\n        pass\n",
    ],
    [
        "external-command-escape",
        "    subprocess.run(['rm', '-rf', str(root)])\n",
    ],
].map(([name, body, helper = ""]) =>
    [
        name,
        BRIDGE_WRITER.replace("    records =", body + "    records =")
            .replace("def unresolved_health", helper + "def unresolved_health"),
    ] as const
);

// Mutating the custody helper itself cannot extend its destructive authority.
bridgeInodeViolations.push(...[
    [
        "custody-helper-destination",
        BRIDGE_WRITER.replace(
            "temporary.replace(path)",
            "temporary.replace(root / 'writer.lock')",
        ),
    ],
    [
        "custody-helper-kind-escape",
        BRIDGE_WRITER.replace(
            "'health': 'health.json'",
            "'health': 'writer.lock'",
        ),
    ],
    [
        "custody-helper-endpoint-validation",
        BRIDGE_WRITER.replace(
            "host = str(ipaddress.IPv4Address(host))",
            "host = host",
        ),
    ],
].map(([name, source]) => [name, source] as const));

export const bridgeImportViolations = [
    [
        "parent-import-alias",
        "caller.py",
        "from package import renamed_writer as writer",
    ],
    [
        "relative-import",
        "package/caller.py",
        "from . import renamed_writer as writer",
    ],
    [
        "relative-import-no-alias",
        "package/caller.py",
        "from . import renamed_writer",
    ],
    [
        "relative-parent-import",
        "package/sub/caller.py",
        "from .. import renamed_writer as writer",
    ],
    [
        "qualified-import",
        "caller.py",
        "import package.renamed_writer as writer",
    ],
].map(([name, path, statement]) =>
    [
        name,
        path,
        `from pathlib import Path
${statement}

${
            statement.includes(" as writer")
                ? "writer"
                : "renamed_writer"
        }.apply_locked(Path('/var/lib/orbit/instance-bridge'), {}, False, False, 1000000000)
`,
    ] as const
);

export const BRIDGE_RECORD_MUTATIONS = BRIDGE_WRITER.replace(
    "    records =",
    `    retiring = json.loads((root / 'endpoints.json').read_text())
    staged = [{'host': '10.44.0.7', 'port': 3306, 'admitted_at': 2}]
    for endpoint in staged:
        state_file(root, 'staged', endpoint, host=endpoint['host'], port=endpoint['port'], capture=endpoint['admitted_at'])

    def commit_confirmed(current):
        for endpoint in staged:
            state_file(root, 'endpoint', endpoint, host=endpoint['host'], port=endpoint['port'], capture=endpoint['admitted_at'])
            state_file(root, 'staged', host=endpoint['host'], port=endpoint['port'], capture=endpoint['admitted_at'], remove=True)
        for endpoint in retiring:
            state_file(root, 'endpoint', host=endpoint['host'], port=endpoint['port'], capture=endpoint['admitted_at'], remove=True)

    commit_confirmed({})
    state_file(root, 'pending', remove=True)
    records =`,
);
