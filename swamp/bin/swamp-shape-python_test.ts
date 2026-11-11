import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import {
    agentPythonFindings,
    GATEWAY_PRIMITIVES,
} from "./check-swamp-shape.ts";
import {
    BRIDGE_ACQUIRE,
    BRIDGE_WRITER,
    bridgeContractViolations,
    run,
    script,
} from "./gate-test-support.ts";
type Finding = { rule: string };
async function scan(
    source: string,
    primitives: readonly unknown[] = [],
): Promise<Finding[]> {
    const result = await run("python3", [
        script("swamp-shape-python.py"),
        JSON.stringify(primitives),
    ], { input: source });
    assertEquals(result.code, 0, result.stderr);
    return JSON.parse(result.stdout);
}

Deno.test("python_secret_flow_is_from_environment_not_internal_argument_names", async () => {
    assertEquals(
        await scan(
            "def install(WorkerCredentials):\n    subprocess.run(['tool', WorkerCredentials])\n",
        ),
        [],
    );
    assertEquals(
        (await scan(
            "import os, subprocess\nvalue=os.environ['ORBIT_SECRET']\nsubprocess.run(['tool',value])\n",
        ))[0]["rule"],
        "secret-argv",
    );
    assertEquals(
        await scan(
            "import os, subprocess\nvalue=os.environ['ORBIT_SECRET']\nsubprocess.run(['tool'], input=value)\n",
        ),
        [],
    );
});

Deno.test("fixed_external_origin_with_dynamic_path_is_not_gateway_http", async () => {
    const source =
        "import urllib.request\nORIGIN='https://api.provider.example/v1/'\ndef request(method,path):\n    gateway_node_id=1\n    urllib.request.Request(ORIGIN+path,method=method)\n";
    assertEquals(await scan(source), []);
    for (
        const variant of [
            source.replaceAll("api.provider.example/v1/", "gateway.orbit/api/v1/"),
            source.replaceAll(
                "https://api.provider.example/v1/",
                "https://api.provider.example",
            ),
            source.replaceAll(
                "def request(method,path):",
                "def request(method,path,ORIGIN):",
            ),
            source.replaceAll(
                "    gateway_node_id=1",
                "    ORIGIN=path\n    gateway_node_id=1",
            ),
            source.replaceAll("ORIGIN+path", "path+ORIGIN"),
            source.replaceAll(
                "ORIGIN+path",
                "urllib.parse.urljoin(ORIGIN,path)",
            ),
            source +
            "ORIGIN='https://gateway.orbit/api/v1/'\nrequest('GET','private-material')\n",
            source +
            "def change():\n    global ORIGIN\n    ORIGIN='https://gateway.orbit/api/v1/'\nchange()\nrequest('GET','private-material')\n",
        ]
    ) {
        assert(
            (await scan(variant)).some((row) => row["rule"] === "gateway-http"),
            variant,
        );
    }
});

Deno.test("httpx_gateway_reads_share_the_external_origin_boundary", async () => {
    for (const client of ["requests", "httpx"]) {
        for (
            const host of [
                "gw.orbit",
                "gateway.example",
                "gateway-prod.example",
                "10.7.0.14",
                "127.1",
                "localhost",
            ]
        ) {
            const source = `import ${client}
${client}.get('https://${host}/api/v1/operations/5')
`;
            assert(
                (await scan(source)).some((row) =>
                    row["rule"] === "gateway-http"
                ),
                source,
            );
        }
        const source = `import ${client} as client
client.get('https://api.provider.example/gateway-state')
`;
        assertEquals(await scan(source), []);
        assertEquals(
            await scan(source.replaceAll("client.get('", "client.get(url='")),
            [],
        );
    }
});

Deno.test("only_proven_named_host_tool_flocks_are_not_workflow_mutexes", async () => {
    const source =
        "import os,fcntl\nfd=os.open('/run/lock/orbit-firewall.lock',os.O_RDWR)\nfcntl.flock(fd,fcntl.LOCK_EX)\n";
    assertEquals(await scan(source), []);
    assertEquals(
        await scan(
            source.replaceAll("fcntl.LOCK_EX)", "fcntl.LOCK_EX|fcntl.LOCK_NB)"),
        ),
        [],
    );
    assertEquals(
        await scan(source.replaceAll("orbit-firewall", "orbit-self-update")),
        [],
    );
    assertEquals(
        await scan(source.replaceAll("orbit-firewall", "orbit-wireguard-peer")),
        [],
    );
    assertEquals(
        await scan(
            "from pathlib import Path\nimport fcntl\nROOT=Path('/run/orbit')\nlock=ROOT/'project-documents'/'execution.lock'\nwith lock.open('r+b') as held:\n    fcntl.flock(held,fcntl.LOCK_EX|fcntl.LOCK_NB)\n",
        ),
        [],
    );
    assertEquals(
        await scan(
            "import os,fcntl\nlock='/run/lock/orbit-self-update.lock'\nfd=os.open(lock,os.O_RDWR)\nwith os.fdopen(fd,'r+b') as held:\n    fcntl.flock(held,fcntl.LOCK_EX)\n",
        ),
        [],
    );
    for (
        const variant of [
            source.replaceAll("orbit-firewall", "workflow"),
            source.replaceAll(
                "'/run/lock/orbit-firewall.lock'",
                "os.environ['LOCK']",
            ),
            source.replaceAll("fcntl.LOCK_EX)", "fcntl.LOCK_SH)"),
            source.replaceAll("fcntl.flock(fd", "fcntl.flock(other_fd"),
            source.replaceAll(
                "fcntl.flock(fd",
                "fd=os.open('/tmp/other',os.O_RDWR)\nfcntl.flock(fd",
            ),
            source.replaceAll(
                "fcntl.flock(fd",
                "if condition:\n    fd=os.open('/tmp/other',os.O_RDWR)\nfcntl.flock(fd",
            ),
            source.replaceAll(
                "fcntl.flock(fd",
                "while True:\n    fcntl.flock(fd",
            ),
            source + "import time\ntime.sleep(1)\n",
            source + "for attempt in range(3):\n    pass\n",
        ]
    ) {
        assert(
            (await scan(variant)).some((row) =>
                row["rule"] === "custom-control"
            ),
            variant,
        );
    }
});

Deno.test("late_outer_fd_rebinding_is_not_a_proven_tool_lock", async () => {
    const source =
        "import os,fcntl\nfd=os.open('/run/lock/orbit-firewall.lock',os.O_RDWR)\ndef acquire():\n    fcntl.flock(fd,fcntl.LOCK_EX)\nfd=os.open('/tmp/workflow.lock',os.O_RDWR)\nacquire()\n";
    assert(
        (await scan(source)).some((row) => row["rule"] === "custom-control"),
    );
});

Deno.test("outer_lock_and_origin_bindings_include_loop_and_destructuring_writes", async () => {
    const lock =
        "import os,fcntl\nfd=os.open('/run/lock/orbit-firewall.lock',os.O_RDWR)\ndef acquire():\n    fcntl.flock(fd,fcntl.LOCK_EX)\n";
    const origin =
        "import urllib.request\nORIGIN='https://api.provider.example/v1/'\ndef request(path):\n    urllib.request.Request(ORIGIN+path)\n";
    for (
        const write of [
            "for fd in [os.open('/tmp/workflow.lock',os.O_RDWR)]:\n    pass\n",
            "fd,other=os.open('/tmp/workflow.lock',os.O_RDWR),None\n",
            "with open('/tmp/workflow.lock') as fd:\n    pass\n",
        ]
    ) {
        assert(
            (await scan(lock + write + "acquire()\n")).some((row) =>
                row["rule"] === "custom-control"
            ),
            write,
        );
    }
    for (
        const write of [
            "for ORIGIN in ['https://gateway.orbit/api/v1/']:\n    pass\n",
            "ORIGIN,other='https://gateway.orbit/api/v1/',None\n",
        ]
    ) {
        assert(
            (await scan(origin + write + "request('private-material')\n")).some(
                (row) => row["rule"] === "gateway-http",
            ),
            write,
        );
    }
    const asynchronous =
        "import fcntl,os\nasync def outer(items):\n    fd=os.open('/run/lock/orbit-firewall.lock',os.O_RDWR)\n    def acquire():\n        fcntl.flock(fd,fcntl.LOCK_EX)\n    async for fd in items:\n        pass\n    acquire()\n";
    assert(
        (await scan(asynchronous)).some((row) =>
            row["rule"] === "custom-control"
        ),
    );
});

Deno.test("agent_executable_context_in_python_subprocesses_and_remote_commands", async () => {
    const scan = (source: string) =>
        agentPythonFindings("fixture.py", source, {
            "agent.status": "observer",
            "cli.install": "action",
        });
    for (
        const source of [
            "from pathlib import Path\nPath('/usr/local/bin/orbit-agent').read_bytes()\n",
            "import subprocess\nsubprocess.run(['sha256sum','/usr/local/bin/orbit-agent'])\n",
            "import subprocess\nsubprocess.run(['systemctl','restart','orbit-agent.service'])\n",
            "import subprocess\nsubprocess.run(['ssh','host','sudo systemctl is-active orbit-agent.service'])\n",
            "import subprocess\nsubprocess.run(['/usr/local/bin/orbit-agent','command','agent.status'])\n",
        ]
    ) {
        assert(
            !(await scan(source)).some((row) =>
                row["rule"].startsWith("agent-")
            ),
            source,
        );
    }
    for (
        const source of [
            "import subprocess\nsubprocess.run(['/usr/local/bin/orbit-agent','command','cli.install'])\n",
            "import subprocess\ncmd=['sudo','-n','/usr/local/bin/orbit-agent','command','cli.install']\nsubprocess.run(cmd)\n",
            "from subprocess import run as execute\nexecute(['ssh','-o','StrictHostKeyChecking=yes','--','host','sudo orbit-agent command cli.install'])\n",
            "import os\nos.system('orbit-agent command cli.install')\n",
        ]
    ) {
        assert(
            (await scan(source)).some((row) => row["rule"] === "agent-action"),
            source,
        );
    }
});

import { PHP_ACTIONS, PHP_ENTRY, PHP_RUNTIME } from "./gate-test-support.ts";
async function hostScan(
    runtime = PHP_RUNTIME,
    actions = PHP_ACTIONS,
    entry = PHP_ENTRY,
    context = true,
) {
    const result = await run("python3", [
        script("swamp-shape-python.py"),
        "[]",
        ...(context ? ["--source-context"] : []),
    ], {
        input: context
            ? JSON.stringify({
                source: runtime,
                sources: {
                    "runtime_common.py": runtime,
                    "monitor_common.py": actions,
                    "stage.py": entry,
                },
            })
            : runtime,
    });
    assertEquals(result.code, 0, result.stderr);
    return JSON.parse(result.stdout) as Finding[];
}
Deno.test("validated_parameterized_host_tool_transaction", async () => {
    assertEquals(await hostScan(), []);
    assert((await hostScan(undefined, undefined, undefined, false)).length > 0);
    assert((await hostScan(undefined, undefined, "")).length > 0);
});
Deno.test("path_guard_custody_or_acquisition_changes_are_not_proven", async () => {
    for (
        const runtime of [
            PHP_RUNTIME.replaceAll("P('/run/lock')", "P('/tmp')"),
            PHP_RUNTIME.replaceAll("orbit-php-", "workflow-"),
            PHP_RUNTIME.replaceAll("[a-z_][a-z0-9_-]{0,31}", ".*"),
            PHP_RUNTIME.replaceAll("if not value:", "if False:"),
            PHP_RUNTIME.replaceAll("os.O_NOFOLLOW", "os.O_TRUNC"),
            PHP_RUNTIME.replaceAll("0o600", "0o666"),
            PHP_RUNTIME.replaceAll("m.st_uid == h.root_uid", "True"),
            PHP_RUNTIME.replaceAll("fcntl.flock(fd,", "fcntl.flock(other_fd,"),
            PHP_RUNTIME.replaceAll(
                "fcntl.flock(fd,",
                "fd = os.open('/tmp/workflow.lock', os.O_RDWR)\n        fcntl.flock(fd,",
            ),
            PHP_RUNTIME.replaceAll(
                "    try:\n        m =",
                '    try:\n        user = "unchecked"\n        m =',
            ),
            PHP_RUNTIME + "\nHost.__init__ = lambda self: None\n",
            PHP_RUNTIME + "\nP = lambda value: pathlib.Path('/tmp')\n",
            PHP_RUNTIME +
            "\nfor P in [lambda value: pathlib.Path('/tmp')]:\n    pass\n",
            PHP_RUNTIME +
            "\nmodule = pathlib\nmodule.Path = lambda value: '/tmp'\n",
            PHP_RUNTIME.replaceAll("import re\n", "import fake as re\n"),
            PHP_RUNTIME.replaceAll(
                "self.user = data['user']",
                "return\n        self.user = data['user']",
            ),
            PHP_RUNTIME.replaceAll("class Host:", "@replace\nclass Host:"),
        ]
    ) {
        assert(
            (await hostScan(runtime)).some((row) =>
                row.rule === "custom-control"
            ),
            runtime,
        );
    }
});
Deno.test("mutated_or_untyped_callers_cannot_claim_constructor_provenance", async () => {
    for (
        const actions of [
            PHP_ACTIONS.replaceAll("runtime.user):", "'unvalidated'):"),
            PHP_ACTIONS.replaceAll(
                "runtime = Runtime(host, data, True)",
                "runtime = data['runtime']",
            ),
            PHP_ACTIONS.replaceAll(
                "runtime = Runtime(host, data, True)",
                "for runtime in [data['runtime']]:\n        pass",
            ),
            PHP_ACTIONS.replaceAll(
                "runtime = Runtime(host, data, True)",
                "if (runtime := data['runtime']):\n        pass",
            ),
            PHP_ACTIONS.replaceAll(
                "runtime = Runtime(host, data, True)",
                "host = data['host']",
            ),
            PHP_ACTIONS.replaceAll(
                "runtime = Runtime(host, data, True)",
                "host.locks = '/tmp'",
            ),
            PHP_ACTIONS.replaceAll(
                "runtime = Runtime(host, data, True)",
                "runtime.user += '/unchecked'",
            ),
            PHP_ACTIONS.replaceAll(
                "runtime = Runtime(host, data, True)",
                "setattr(runtime, 'user', 'unchecked')",
            ),
            PHP_ACTIONS.replaceAll(
                "return host, runtime, data",
                "host = data['host']\n    return host, runtime, data",
            ),
            PHP_ACTIONS.replaceAll(
                "return host, runtime, data",
                "for runtime in [data['runtime']]:\n        pass\n    return host, runtime, data",
            ),
            PHP_ACTIONS.replaceAll(
                "def context():",
                "@replace\ndef context():",
            ),
            PHP_ACTIONS.replaceAll(
                "def execute(action):",
                "@replace\ndef execute(action):",
            ),
            PHP_ACTIONS + "\nstage(None, None, {})\n",
            PHP_ACTIONS + "\nalias = execute\n",
            PHP_ACTIONS + "\nalias = stage\n",
            PHP_ACTIONS + "\nalias = context\n",
        ]
    ) {
        assert(
            (await hostScan(undefined, actions)).some((row) =>
                row.rule === "custom-control"
            ),
            actions,
        );
    }
});
Deno.test("native_certificate_signer_has_only_the_scoped_post_and_checked_numeric_operation", async () => {
    const source = await Deno.readTextFile(
        new URL(
            "../tests/fixtures/shape/certificate-signing-http.yaml",
            import.meta.url,
        ),
    );
    const block = source.match(/<<'PYTHON'\n([\s\S]*?)\n {4}PYTHON/)![1];
    const script = block.split("\n").map((line) => line.replace(/^ {4}/, ""))
        .join("\n");
    assertEquals(await scan(script, GATEWAY_PRIMITIVES), []);
    for (
        const variant of [
            script.replace(/method=(["'])POST\1/g, 'method="GET"'),
            script.replaceAll("/certificates/sign", "/primitive"),
            script.replace(/if not re\.fullmatch\([^\n]+\):/g, "if False:"),
            script.replaceAll(
                "    url = ",
                "    operation = os.environ['UNCHECKED_PATH']\n    url = ",
            ),
            script +
            "\nurllib.request.Request('https://gateway.orbit/api/v1/operations/19/result',data=b'{}',method='POST')\n",
        ]
    ) {
        assert(
            variant !== script,
            "Mutation must change the actual formatted source",
        );
        assert(
            (await scan(variant, GATEWAY_PRIMITIVES)).some((row) =>
                row.rule === "gateway-http"
            ),
        );
    }
});

async function bridgeScan(
    source = BRIDGE_WRITER,
    peers: Record<string, string> = {},
) {
    const result = await run("python3", [
        script("swamp-shape-python.py"),
        "[]",
        "--source-context",
    ], {
        input: JSON.stringify({
            source,
            sources: { "arbitrary_name.py": source, ...peers },
        }),
    });
    assertEquals(result.code, 0, result.stderr);
    return JSON.parse(result.stdout) as Finding[];
}

Deno.test("bridge transaction proves one nonblocking acquisition and complete custody", async () => {
    assertEquals(await bridgeScan(), []);
    assert(
        (await scan(BRIDGE_WRITER)).some((row) =>
            row.rule === "custom-control"
        ),
    );
    for (const [name, source] of bridgeContractViolations) {
        assert(
            (await bridgeScan(source)).some((row) =>
                row.rule === "custom-control"
            ),
            name,
        );
    }
});

Deno.test("bridge custody cannot be bypassed by a caller, descriptor or binding change", async () => {
    for (
        const source of [
            BRIDGE_WRITER.replace(
                BRIDGE_ACQUIRE,
                BRIDGE_ACQUIRE.replace("lock,", "other,"),
            ),
            BRIDGE_WRITER.replace(
                BRIDGE_ACQUIRE,
                BRIDGE_ACQUIRE.replace(" | fcntl.LOCK_NB", ""),
            ),
            BRIDGE_WRITER.replace(
                "        yield",
                "        fcntl.flock(lock, fcntl.LOCK_UN)\n        yield",
            ),
            BRIDGE_WRITER.replace("        yield", "    yield"),
            BRIDGE_WRITER.replace(
                "            raise PolicyError('instance.bridge_busy') from None",
                "            pass",
            ),
            BRIDGE_WRITER.replace(
                "    with writer_lock(root):",
                "    write_file(root / 'health.json', {})\n    with writer_lock(root):",
            ),
            BRIDGE_WRITER.replace(
                "    with writer_lock(root):",
                "    apply_locked(root, incoming, reconcile, withdraw, deadline)\n    with writer_lock(root):",
            ),
            BRIDGE_WRITER.replace(
                "            write_file(root / 'health.json', {'error_code': code})",
                "            pass",
            )
                .replace(
                    "            raise\n",
                    "            raise\n    write_file(root / 'health.json', {})\n",
                ),
            BRIDGE_WRITER.replace(
                "    main()",
                "    apply_locked(None, None, False, False, 1)",
            ),
            BRIDGE_WRITER.replace("import fcntl", "import fake as fcntl"),
            BRIDGE_WRITER.replace(
                "    records =",
                "    fcntl = fake\n    records =",
            ),
            BRIDGE_WRITER.replace(
                "    records =",
                "    alias = apply_locked\n    records =",
            ),
            BRIDGE_WRITER.replace(
                "    records =",
                "    (root / 'writer.lock').unlink()\n    records =",
            ),
            BRIDGE_WRITER + "\nwriter_lock = lambda root: None\n",
        ]
    ) {
        assert(
            (await bridgeScan(source)).some((row) =>
                row.rule === "custom-control"
            ),
            source,
        );
    }
    assert((await bridgeScan(BRIDGE_WRITER, {
        "another.py":
            "from arbitrary_name import apply_locked\napply_locked(None, None, False, False, 1)\n",
    })).some((row) => row.rule === "custom-control"));
});
