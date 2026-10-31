"""Gate acceptance, ratchet and relocation tests in private disposable Git trees."""
import json
import os
import re
import subprocess
import tempfile
import textwrap
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DENO = os.environ.get("DENO_BIN", "deno")


class ShapeGate(unittest.TestCase):
    def test_python_secret_flow_is_from_environment_not_internal_argument_names(self):
        def scan(source):
            p = subprocess.run(["python3", str(ROOT / "bin/swamp-shape-python.py")], input=source, text=True, capture_output=True, check=True)
            return json.loads(p.stdout)
        self.assertEqual(scan("def install(WorkerCredentials):\n    subprocess.run(['tool', WorkerCredentials])\n"), [])
        self.assertEqual(scan("import os, subprocess\nvalue=os.environ['ORBIT_SECRET']\nsubprocess.run(['tool',value])\n")[0]["rule"], "secret-argv")
        self.assertEqual(scan("import os, subprocess\nvalue=os.environ['ORBIT_SECRET']\nsubprocess.run(['tool'], input=value)\n"), [])

    def test_native_certificate_signer_has_only_the_scoped_post_and_checked_numeric_operation(self):
        source = (ROOT / "tests/fixtures/shape/certificate-signing-http.yaml").read_text()
        script = textwrap.dedent(re.search(r"<<'PYTHON'\n([\s\S]*?)\n    PYTHON", source)[1])
        exported = subprocess.run([DENO, "eval", f"import {{GATEWAY_PRIMITIVES}} from '{ROOT.as_uri()}/bin/check-swamp-shape.ts';console.log(JSON.stringify(GATEWAY_PRIMITIVES));"], capture_output=True, text=True, check=True).stdout.strip()
        def scan(text):
            result = subprocess.run(["python3", str(ROOT / "bin/swamp-shape-python.py"), exported], input=text, capture_output=True, text=True, check=True)
            return json.loads(result.stdout)
        self.assertEqual(scan(script), [])
        variants = [
            re.sub(r"method=([\"'])POST\1", 'method="GET"', script),
            script.replace("/certificates/sign", "/primitive"),
            re.sub(r"if not re\.fullmatch\([^\n]+\):", "if False:", script),
            script.replace("    url = ", "    operation = os.environ['UNCHECKED_PATH']\n    url = "),
            script + "\nurllib.request.Request('https://gateway.orbit/api/v1/operations/19/result',data=b'{}',method='POST')\n",
        ]
        for variant in variants:
            self.assertNotEqual(variant, script, "Mutation must change the actual formatted source")
            self.assertTrue(any(row["rule"] == "gateway-http" for row in scan(variant)))

    def test_fixed_external_origin_with_dynamic_path_is_not_gateway_http(self):
        def scan(source):
            result = subprocess.run(["python3", str(ROOT / "bin/swamp-shape-python.py")], input=source, text=True, capture_output=True, check=True)
            return json.loads(result.stdout)
        source = "import urllib.request\nORIGIN='https://api.upcloud.com/1.3/'\ndef request(method,path):\n    gateway_node_id=1\n    urllib.request.Request(ORIGIN+path,method=method)\n"
        self.assertEqual(scan(source), [])
        for variant in [
            source.replace('api.upcloud.com/1.3/', 'gateway.orbit/api/v1/'),
            source.replace('https://api.upcloud.com/1.3/', 'https://api.upcloud.com'),
            source.replace('def request(method,path):', 'def request(method,path,ORIGIN):'),
            source.replace('    gateway_node_id=1', "    ORIGIN=path\n    gateway_node_id=1"),
            source.replace('ORIGIN+path', 'path+ORIGIN'),
            source.replace('ORIGIN+path', 'urllib.parse.urljoin(ORIGIN,path)'),
        ]:
            self.assertTrue(any(row['rule'] == 'gateway-http' for row in scan(variant)), variant)

    def test_only_proven_named_host_tool_flocks_are_not_workflow_mutexes(self):
        def scan(source):
            result = subprocess.run(["python3", str(ROOT / "bin/swamp-shape-python.py")], input=source, text=True, capture_output=True, check=True)
            return json.loads(result.stdout)
        source = "import os,fcntl\nfd=os.open('/run/lock/orbit-firewall.lock',os.O_RDWR)\nfcntl.flock(fd,fcntl.LOCK_EX)\n"
        self.assertEqual(scan(source), [])
        self.assertEqual(scan(source.replace('fcntl.LOCK_EX)', 'fcntl.LOCK_EX|fcntl.LOCK_NB)')), [])
        self.assertEqual(scan(source.replace('orbit-firewall', 'orbit-self-update')), [])
        self.assertEqual(scan("from pathlib import Path\nimport fcntl\nROOT=Path('/run/orbit')\nlock=ROOT/'project-documents'/'execution.lock'\nwith lock.open('r+b') as held:\n    fcntl.flock(held,fcntl.LOCK_EX|fcntl.LOCK_NB)\n"), [])
        self.assertEqual(scan("import os,fcntl\nlock='/run/lock/orbit-self-update.lock'\nfd=os.open(lock,os.O_RDWR)\nwith os.fdopen(fd,'r+b') as held:\n    fcntl.flock(held,fcntl.LOCK_EX)\n"), [])
        for variant in [
            source.replace('orbit-firewall', 'workflow'),
            source.replace("'/run/lock/orbit-firewall.lock'", "os.environ['LOCK']"),
            source.replace('fcntl.LOCK_EX)', 'fcntl.LOCK_SH)'),
            source.replace('fcntl.flock(fd', 'fcntl.flock(other_fd'),
            source.replace('fcntl.flock(fd', "fd=os.open('/tmp/other',os.O_RDWR)\nfcntl.flock(fd"),
            source.replace('fcntl.flock(fd', "if condition:\n    fd=os.open('/tmp/other',os.O_RDWR)\nfcntl.flock(fd"),
            source.replace('fcntl.flock(fd', "while True:\n    fcntl.flock(fd"),
            source + "import time\ntime.sleep(1)\n",
            source + "for attempt in range(3):\n    pass\n",
        ]:
            self.assertTrue(any(row['rule'] == 'custom-control' for row in scan(variant)), variant)

    def test_agent_executable_context_in_python_subprocesses_and_remote_commands(self):
        inventory = json.dumps({"agent.status": "observer", "cli.install": "action"})
        def scan(source):
            result = subprocess.run([DENO, "eval", f"import {{agentPythonFindings}} from '{ROOT.as_uri()}/bin/check-swamp-shape.ts';console.log(JSON.stringify(agentPythonFindings('fixture.py',await new Response(Deno.stdin.readable).text(),{inventory})));"], input=source, text=True, capture_output=True, check=True)
            return json.loads(result.stdout)
        for source in [
            "from pathlib import Path\nPath('/usr/local/bin/orbit-agent').read_bytes()\n",
            "import subprocess\nsubprocess.run(['sha256sum','/usr/local/bin/orbit-agent'])\n",
            "import subprocess\nsubprocess.run(['systemctl','restart','orbit-agent.service'])\n",
            "import subprocess\nsubprocess.run(['ssh','host','sudo systemctl is-active orbit-agent.service'])\n",
            "import subprocess\nsubprocess.run(['/usr/local/bin/orbit-agent','command','agent.status'])\n",
        ]:
            self.assertFalse(any(row["rule"].startswith("agent-") for row in scan(source)), source)
        for source in [
            "import subprocess\nsubprocess.run(['/usr/local/bin/orbit-agent','command','cli.install'])\n",
            "import subprocess\ncmd=['sudo','-n','/usr/local/bin/orbit-agent','command','cli.install']\nsubprocess.run(cmd)\n",
            "from subprocess import run as execute\nexecute(['ssh','-o','StrictHostKeyChecking=yes','--','host','sudo orbit-agent command cli.install'])\n",
            "import os\nos.system('orbit-agent command cli.install')\n",
        ]:
            self.assertTrue(any(row["rule"] == "agent-action" for row in scan(source)), source)

    def test_gate_preserves_ratchet_and_metrics_when_run_from_swamp_subtree(self):
        with tempfile.TemporaryDirectory(prefix="shape-gate-") as scratch:
            root = Path(scratch)
            def git(*args):
                return subprocess.run(["git", *args], cwd=root, text=True, capture_output=True, check=True).stdout.strip()
            git("init", "--quiet")
            git("config", "user.email", "shape@example.invalid")
            git("config", "user.name", "Shape fixture")
            swamp = root / "swamp"
            (swamp / "config").mkdir(parents=True)
            (swamp / "extensions/behaviors").mkdir(parents=True)
            (swamp / "workflows").mkdir()
            source = swamp / "extensions/behaviors/old.ts"
            source.write_text("export const behavior={type:'@example/old'};\nawait delay(10);\n")
            (swamp / "config/agent-command-kinds.json").write_text('{"agent.status":"observer","cli.install":"action"}')
            (swamp / "workflows/clean.yaml").write_text("name: clean\njobs:\n- steps:\n  - task: {type: behavior, behaviorType: command/shell, inputs: {run: 'true'}}\n")
            git("add", ".")
            git("commit", "--quiet", "-m", "baseline source")
            def gate(*extra):
                return subprocess.run([DENO, "run", "-A", str(ROOT / "bin/check-swamp-shape.ts"), "--base", "HEAD", "--python-analyzer", str(ROOT / "bin/swamp-shape-python.py"), *extra], cwd=swamp, text=True, capture_output=True)
            p = gate("--write-baseline")
            self.assertEqual(p.returncode, 0, p.stderr)
            metrics = json.loads(p.stdout.splitlines()[0])
            self.assertEqual(metrics["workflows"]["clean"], 1)
            git("add", ".")
            git("commit", "--quiet", "-m", "record explicit baseline")
            self.assertEqual(gate().returncode, 0)
            source.write_text(source.read_text() + "await delay(20);\n")
            self.assertNotEqual(gate().returncode, 0, "Growing existing debt must fail")
            source.write_text("export const behavior={type:'@example/old'};\n")
            self.assertNotEqual(gate().returncode, 0, "Resolved debt must remove its allowance")
            self.assertEqual(gate("--write-baseline").returncode, 0)
            # Native Python controls are owned by the AST helper, not counted
            # again from shell heredoc tokens. A host lock is clean; a mutex is not.
            native = "python3 - <<'PY'\nimport fcntl\nimport os\n\nfd = os.open('/run/lock/orbit-firewall.lock', os.O_RDWR)\nfcntl.flock(fd, fcntl.LOCK_EX)\nPY\n"
            workflow_path = swamp / "workflows/clean.yaml"
            workflow_path.write_text("name: clean\njobs:\n- steps:\n  - task: {type: behavior, behaviorType: command/shell, inputs: {run: " + json.dumps(native) + "}}\n")
            git("add", ".")
            self.assertEqual(gate().returncode, 0)
            workflow_path.write_text(workflow_path.read_text().replace("orbit-firewall", "workflow"))
            self.assertNotEqual(gate().returncode, 0)
            workflow_path.write_text(workflow_path.read_text().replace("workflow.lock", "orbit-firewall.lock"))
            git("add", ".")
            # Renaming cannot move the old file's allowance to a new path.
            source.rename(source.with_name("renamed.ts"))
            source.with_name("renamed.ts").write_text("await delay(10);\n")
            git("add", ".")
            self.assertNotEqual(gate().returncode, 0)

    def test_initial_import_uses_preserved_source_history_and_matching_repo_identity(self):
        with tempfile.TemporaryDirectory(prefix="shape-import-") as scratch:
            root = Path(scratch)
            def git(*args):
                return subprocess.run(["git", *args], cwd=root, text=True, capture_output=True, check=True).stdout.strip()
            git("init", "--quiet")
            git("config", "user.email", "shape@example.invalid")
            git("config", "user.name", "Shape fixture")
            (root / "config").mkdir()
            (root / "extensions/behaviors").mkdir(parents=True)
            (root / ".swamp.yaml").write_text("repoId: fixture-repository\n")
            (root / "extensions/behaviors/read.ts").write_text("export const value=1;\nawait delay(10);\n")
            git("add", ".")
            git("commit", "--quiet", "-m", "original Swamp tree")
            source = git("rev-parse", "HEAD")
            # Old main predates the gate; only the proposed tracked tree has its inventory.
            (root / "config/agent-command-kinds.json").write_text('{"agent.status":"observer"}')
            (root / "config/swamp-shape-baseline.json").write_text(json.dumps({"schema": 1, "entries": [{"file": "extensions/behaviors/read.ts", "rule": "custom-control", "count": 1, "evidence": ["delay"]}]}))
            swamp = root / "swamp"
            swamp.mkdir()
            for name in ["config", "extensions", ".swamp.yaml"]:
                (root / name).rename(swamp / name)
            git("add", ".")
            git("commit", "--quiet", "-m", "preserve source under swamp")
            command = [DENO,"run","-A",str(ROOT / "bin/check-swamp-shape.ts"),"--base",source,"--python-analyzer",str(ROOT / "bin/swamp-shape-python.py")]
            for event in ("pull_request", "push"):
                # The import PR and first main push both compare to a pre-import Orbit tree.
                result = subprocess.run(command, cwd=swamp, capture_output=True, text=True, env={**os.environ, "GITHUB_EVENT_NAME": event})
                self.assertEqual(result.returncode, 0, f"{event}: {result.stderr}")
            (swamp / ".swamp.yaml").write_text("repoId: unrelated-repository\n")
            result = subprocess.run(command, cwd=swamp, capture_output=True, text=True)
            self.assertNotEqual(result.returncode, 0)


if __name__ == "__main__":
    unittest.main()
