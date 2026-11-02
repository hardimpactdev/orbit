"""Execute the Tasks source/setup DAG against a frozen VM-child receipt fixture."""

import hashlib
import json
import os
import shlex
import shutil
import sys
import tempfile
from pathlib import Path

import native_test as fixture
import yaml

ROOT = fixture.ROOT


class NativeProvision(fixture.NativeRepository):
    test_analysis_native_dag_retains_exact_report = None
    test_private_analysis_is_refused_before_native_history = None
    test_script_native_steps_return_business_failure_with_literal_arguments = None
    test_native_resume_after_result_failure_never_reexecutes_program = None
    test_untracked_or_non_executable_script_refuses_without_running = None
    test_moved_candidate_refuses_as_stale_without_running_the_analyzer = None
    test_private_analysis_is_refused_in_the_terminal_result = None
    test_script_output_is_redacted_on_the_worker_before_history = None
    test_analyzer_stderr_and_private_report_never_reach_history = None
    native_timeout = 150

    def setUp(self):
        super().setUp()
        self.copy_behaviors("operations/task-vm-workspace")
        for relative in (
            "instance-lifecycle/source_profile.ts",
            "tools/constraints.ts",
        ):
            path = self.repo / "extensions/behaviors/operations" / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy(ROOT / "extensions/behaviors/operations" / relative, path)
        self.git("switch", "-C", "main")
        (self.workspace / "code").write_text("fixture\n")
        self.git("add", ".")
        self.git("commit", "-qm", "Source")
        self.start = self.git("rev-parse", "HEAD")
        checkout = self.repo / "guest/checkout"
        self.inputs = {
            "operation_id": 7,
            "node_id": 14,
            "kind": "tasks.provision",
            "execution_secrets": {},
            "intent": {
                "task_vm_id": 10,
                "guest_node_id": 20,
                "group_id": 30,
                "project_id": 40,
                "instance_id": 50,
                "wireguard_ip": "10.44.0.20",
                "eligible": True,
                "ssh_identity": {
                    "control_node_id": 209,
                    "identity_file": "/dev/null",
                    "public_key": "ssh-ed25519 PUBLIC",
                },
                "workspace": {
                    "checkout_path": str(checkout),
                    "repository": str(self.workspace),
                    "repository_identity": "github:acme/test",
                    "default_branch": "main",
                    "start_commit": self.start,
                    "branch": "task-30",
                    "user": "orbit",
                    "project_type": "monorepo",
                    "entry_point": "artisan",
                    "skip_profile": True,
                    "php_versions": ["8.5"],
                    "setup": [],
                },
                "fixture_vm": {
                    "task_vm_id": 10,
                    "guest_node_id": 20,
                    "ready": True,
                    "provider": {
                        "address": "192.0.2.20",
                        "provider_id": None,
                        "running": True,
                        "settled": True,
                    },
                    "enrollment": {
                        "ready": True,
                        "node": {
                            "ssh_host_key_type": "ssh-ed25519",
                            "ssh_host_key": "AAAAC3NzaC1lZDI1NTE5AAAAINqVuK+N4gL/sDvGNKnGrYhEtEFnZx9hvH+134PsQgND",
                        },
                        "process": {"process_id": 60, "status": "active"},
                    },
                },
            },
        }
        scp = self.repo / "tools/scp"
        scp.write_text(
            "#!"
            + sys.executable
            + '\nimport shutil,sys\nfrom pathlib import Path\nsrc=Path(sys.argv[-2].split(":",1)[-1]);dst=Path(sys.argv[-1].split(":",1)[-1])\nif src.is_dir():shutil.copytree(src,dst/src.name,dirs_exist_ok=True)\nelse:shutil.copy2(src,dst)\n'
        )
        fixture = self.repo / "extensions/models/receipt.ts"
        fixture.parent.mkdir(exist_ok=True)
        fixture.write_text("""import { z } from "npm:zod@4.4.3";
export const model = {
  type: "@nckrtl/test-vm-receipt", version: "2026.11.01.1",
  resources: { receipt: { schema: z.record(z.string(), z.unknown()), lifetime: "infinite", garbageCollection: 10 } },
  methods: { read: { description: "Return frozen public receipt", arguments: z.object({ receipt: z.record(z.string(),z.unknown()), operation_id:z.number(), kind:z.string(), execution_secrets:z.record(z.string(),z.unknown()) }),
    execute: async (args,ctx) => ({dataHandles:[await ctx.writeResource("receipt","receipt",{...args.receipt,operation_id:args.operation_id,root_kind:args.kind,vm_copy_keys:Object.keys(args.execution_secrets)})]}) } }
};""")
        provision = yaml.safe_load(
            (ROOT / "workflows/workflow-operation-task-provision.yaml").read_text()
        )
        child = {
            "id": "33e944fe-e0a1-4e6a-a19d-69103536273b",
            "name": "task-vm-provision",
            "inputs": yaml.safe_load(
                (ROOT / "workflows/workflow-task-vm-provision.yaml").read_text()
            )["inputs"],
            "jobs": [
                {
                    "name": "vm",
                    "steps": [
                        {
                            "name": "result",
                            "task": {
                                "type": "behavior",
                                "behaviorType": "@nckrtl/test-vm-receipt",
                                "behaviorName": "vm-fixture",
                                "methodName": "read",
                                "inputs": {
                                    "receipt": "${{ inputs.intent.fixture_vm }}",
                                    "operation_id": "${{ inputs.operation_id }}",
                                    "kind": "${{ inputs.kind }}",
                                    "execution_secrets": "${{ inputs.execution_secrets }}",
                                },
                            },
                        }
                    ],
                }
            ],
        }
        (self.repo / "workflows/workflow-task-vm-provision.yaml").write_text(
            yaml.safe_dump(child)
        )
        for flow in [
            provision,
            yaml.safe_load(
                (ROOT / "workflows/workflow-task-workspace-source.yaml").read_text()
            ),
            yaml.safe_load(
                (ROOT / "workflows/workflow-task-vm-workspace.yaml").read_text()
            ),
        ]:
            for job in flow["jobs"]:
                self.assertEqual(job.pop("labels"), {"role": "swamp"})
                self.assertNotIn("labels", job)
                for step in job["steps"]:
                    self.assertEqual(step.pop("labels"), {"role": "swamp"})
                    self.assertNotIn("labels", step)
            encoded = (
                json.dumps(flow)
                .replace(
                    "/home/orbit/.cache/orbit-task-provision/",
                    str(self.repo / "control") + "/",
                )
                .replace("/tmp/orbit-task-provision-", str(self.repo / "assets-"))
            )
            (self.repo / ("workflows/workflow-" + flow["name"] + ".yaml")).write_text(
                yaml.safe_dump(json.loads(encoded), sort_keys=False)
            )
        self.checkout = checkout

    def setup_commands(self, commands):
        self.inputs["intent"]["workspace"]["setup"] = [
            {
                "name": "setup-" + str(i),
                "command": command,
                "timeout_seconds": 30,
                "input_json": json.dumps({"command": command}),
            }
            for i, command in enumerate(commands)
        ]

    def execute_provision(self, success=True):
        self.cli("workflow", "validate", "operation-task-provision")
        reply = self.cli(
            "workflow",
            "run",
            "operation-task-provision",
            "--stdin",
            input=json.dumps(self.inputs),
            success=success,
        )
        run = self.decode(reply)
        history = json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)
        Path("/fast/apps/lane25-scratch/provision-native-last.json").write_text(
            json.dumps(history, indent=2)
        )
        steps = {row["name"]: row for row in history["jobs"][0]["steps"]}
        return history, steps

    def proof_delivery(self, fail=False):
        secret = os.urandom(32).hex()
        self.secrets = (secret,)
        self.cli("vault", "create", "local_encryption", "orbit-control-inputs")
        self.cli("vault", "put", "orbit-control-inputs", "swamp-proof-club-api-key", input=secret)
        self.inputs["intent"].update(project_id=62, proof_club_required=True)
        self.inputs["execution_secrets"]["proof_club"] = {
            "vault": "orbit-control-inputs", "key": "swamp-proof-club-api-key"
        }
        # The private home lives outside the scanned scratch repository.
        home = Path(tempfile.mkdtemp(prefix="orbit-proof-club-", dir=os.environ.get("TMPDIR")))
        self.addCleanup(shutil.rmtree, home, True)
        (home / ".config/swamp").mkdir(parents=True)
        (home / ".config/swamp/club-api-key").symlink_to(home / "public")
        (home / "public").write_text("untouched")
        calls = self.repo / "proof-club-calls.json"
        ssh = self.repo / "tools/ssh"
        # The official SSH transport stays real. The fixture drops sudo and the orbit
        # ownership (no root here) and maps /home/orbit to the private home.
        script = "#!" + sys.executable + "\n" + """import json,shlex,subprocess,sys
from pathlib import Path
args=sys.argv[1:]
if '-M' in args or '-O' in args:raise SystemExit(0)
argv=shlex.split(args[-1]);material=sys.stdin.buffer.read()
if any('/home/orbit/.config/swamp' in value for value in argv):
    assert argv[:3]==['sudo','-n','--'], argv
    argv=argv[3:]
    while '-o' in argv:
        i=argv.index('-o');del argv[i:i+2]
    while '-g' in argv:
        i=argv.index('-g');del argv[i:i+2]
    argv=[v.replace('/home/orbit',HOME) for v in argv]
    log=Path(CALLS);rows=json.loads(log.read_text()) if log.exists() else []
    rows.append({'argv':argv,'stdin':bool(material)});log.write_text(json.dumps(rows))
    if FAIL and '/dev/stdin' in argv:
        print('fixture private destination refused',file=sys.stderr);raise SystemExit(1)
r=subprocess.run(argv,input=material,capture_output=True)
sys.stdout.buffer.write(r.stdout);sys.stderr.buffer.write(r.stderr)
raise SystemExit(r.returncode)
"""
        script = script.replace("HOME", repr(str(home))).replace("CALLS", repr(str(calls))).replace("FAIL", repr(fail))
        ssh.write_text(script)
        ssh.chmod(0o755)
        history, steps = self.execute_provision(success=not fail)
        self.assertEqual(steps["vm"]["outputs"]["result"]["vm_copy_keys"], [])
        rows = json.loads(calls.read_text())
        # Only the final write receives the token, on stdin; no argv names it.
        self.assertEqual([row["stdin"] for row in rows], [False, False, True])
        self.assertEqual(rows[2]["argv"][2:], ["install", "-m", "0600", "/dev/stdin", str(home / ".config/swamp/club-api-key")])
        self.assertTrue(all(secret not in json.dumps(row) for row in rows))
        self.assertEqual((home / "public").read_text(), "untouched")
        self.assertEqual((home / ".config/swamp").stat().st_mode & 0o777, 0o700)
        logs = self.cli("workflow", "history", "logs", history["id"])
        self.assertNotIn(secret, logs.stdout + logs.stderr)
        self.private_home = home
        return history, steps

    def test_proof_club_token_reaches_a_private_file_on_ssh_stdin_and_never_native_history(self):
        history, steps = self.proof_delivery()
        self.assertEqual(history["status"], "succeeded")
        self.assertEqual(steps["proof_club"]["status"], "succeeded")
        # History records only that the exec had stdin, never the token.
        recorded = steps["proof_club"]["dataArtifacts"][0]["attributes"]["args"]
        self.assertEqual(recorded["hasStdin"], True)
        self.assertEqual(sorted(recorded), ["argv", "hasStdin", "sudo"])
        destination = self.private_home / ".config/swamp/club-api-key"
        self.assertFalse(destination.is_symlink())
        self.assertEqual(destination.read_text(), self.secrets[0])
        self.assertEqual(destination.stat().st_mode & 0o777, 0o600)
        self.assertNotIn("error_code", json.loads(steps["result"]["outputs"]["stdout"]))

    def test_private_delivery_refusal_keeps_vm_facts_but_never_claims_group_source_success(self):
        history, steps = self.proof_delivery(fail=True)
        receipt = json.loads(steps["result"]["outputs"]["stdout"])
        self.assertEqual(history["status"], "failed")
        self.assertEqual(receipt["failed_step"], "proof_club")
        self.assertTrue(receipt["ready"])
        self.assertNotIn("source", receipt)

    def test_full_source_and_ordered_setup_use_original_root_and_return_own_receipt(
        self,
    ):
        self.git("branch", "-m", "main", "develop")
        self.inputs["intent"]["workspace"]["default_branch"] = "develop"
        self.setup_commands(
            [
                "printf first > order",
                "test $(cat order) = first; printf second >> order",
            ]
        )
        history, steps = self.execute_provision()
        result = json.loads(steps["result"]["outputs"]["stdout"])
        self.assertEqual(history["status"], "succeeded")
        self.assertEqual(steps["proof_club"]["status"], "skipped")
        self.assertEqual(self.checkout.joinpath("order").read_text(), "firstsecond")
        self.assertEqual(result["starting_commit"], self.start)
        self.assertEqual(result["analysis_base"]["base"], self.start)
        self.assertEqual(result["instance_id"], 50)
        self.assertEqual(result["node_id"], 20)
        self.assertEqual(result["source"]["branch"], "task-30")
        self.assertTrue(result["ready"])
        self.assertNotIn("result_json", result)
        self.assertEqual(steps["vm"]["outputs"]["result"]["operation_id"], 7)
        self.assertEqual(
            steps["vm"]["outputs"]["result"]["root_kind"], "tasks.provision"
        )

    def test_task_branch_starts_at_the_admitted_commit_not_the_newer_default_branch_head(
        self,
    ):
        (self.workspace / "red").write_text("newer head\n")
        self.git("add", ".")
        self.git("commit", "-qm", "Red head")
        history, steps = self.execute_provision()
        result = json.loads(steps["result"]["outputs"]["stdout"])
        self.assertEqual(history["status"], "succeeded")
        self.assertEqual(result["starting_commit"], self.start)
        self.assertEqual(result["analysis_base"]["base"], self.start)
        self.assertFalse(self.checkout.joinpath("red").exists())

    def test_git_execution_copy_stays_on209_and_out_of_guest_configuration_and_history(
        self,
    ):
        secret = "AUTHORIZATION: basic " + os.urandom(24).hex()
        self.secrets = (secret,)
        self.cli("vault", "create", "local_encryption", "orbit-control-inputs")
        self.cli(
            "vault",
            "put",
            "orbit-control-inputs",
            "task-provision-git-10",
            input=secret,
        )
        self.inputs["execution_secrets"] = {
            "git_authorization": {
                "vault": "orbit-control-inputs",
                "key": "task-provision-git-10",
            }
        }
        history, steps = self.execute_provision()
        self.assertEqual(steps["vm"]["outputs"]["result"]["vm_copy_keys"], [])
        self.assertEqual(history["status"], "succeeded")
        self.assertNotIn(secret, json.dumps(history))
        self.assertNotIn(secret, self.checkout.joinpath(".git/config").read_text())
        self.assertEqual(self.checkout.joinpath("code").read_text(), "fixture\n")

    def test_cloud_provider_descriptors_stay_in_vm_child_and_out_of_source_child(self):
        self.inputs["execution_secrets"] = {
            "bootstrap": {"vault": "orbit-control-inputs", "key": "task-vm-provider-bootstrap-10"},
            "upcloud": {"vault": "orbit-control-inputs", "key": "task-vm-upcloud-token"},
        }
        history, steps = self.execute_provision()
        self.assertEqual(history["status"], "succeeded")
        self.assertEqual(sorted(steps["vm"]["outputs"]["result"]["vm_copy_keys"]), ["bootstrap", "upcloud"])

    def test_monorepo_skips_irrelevant_composer_profile_and_preserves_empty_setup_seed(
        self,
    ):
        (self.workspace / "composer.json").write_text("{invalid composer metadata")
        self.git("add", ".")
        self.git("commit", "-qm", "Irrelevant profile")
        self.setup_commands(
            [
                'set -u; test -z "$ORBIT_SEED_PATH"; test -z "$ORBIT_SEED_COMMIT"; printf seeded > setup-proof'
            ]
        )
        history, steps = self.execute_provision()
        result = json.loads(steps["result"]["outputs"]["stdout"])
        self.assertEqual(history["status"], "succeeded")
        self.assertIsNone(result["source"]["php_version"])
        self.assertEqual(self.checkout.joinpath("setup-proof").read_text(), "seeded")

    def test_invalid_laravel_profile_keeps_specific_refusal_and_vm_readiness(self):
        (self.workspace / "composer.json").write_text(
            json.dumps({"require": {"php": "^9"}})
        )
        self.git("add", ".")
        self.git("commit", "-qm", "Unsupported PHP")
        self.inputs["intent"]["workspace"].update(
            project_type="laravel-app",
            skip_profile=False,
            start_commit=self.git("rev-parse", "HEAD"),
        )
        history, steps = self.execute_provision(success=False)
        result = json.loads(steps["result"]["outputs"]["stdout"])
        self.assertEqual(history["status"], "failed")
        self.assertEqual(result["error_code"], "app-dev.php_version_unsupported")
        self.assertEqual(result["failed_step"], "profile")
        self.assertTrue(result["ready"])
        self.assertNotIn("source", result)

    def test_setup_output_is_redacted_on_the_worker_before_history(self):
        # The command builds its credentials at run time, so only its output
        # holds them; the scan covers history, data and logs of every run.
        self.secrets = fixture.runtime("ghp_", "P" * 36), fixture.runtime("d3" * 16, "d3" * 16)
        self.setup_commands(
            [
                'python3 -c \'print("ghp_" + "P" * 36); print("d3" * 32)\'; echo visible >&2',
                "exit 7",
            ]
        )
        history, steps = self.execute_provision(success=False)
        self.assertEqual(history["status"], "failed")
        source = steps["source"]["outputs"]
        self.assertEqual(source["setup-0"]["stdout"], "")
        self.assertEqual(
            source["setup_output"]["setup"]["setup-0"],
            {"output": "[REDACTED]\n[REDACTED]\nvisible\n", "truncated": False},
        )
        self.assertFalse(list(self.repo.glob("assets-*")))
        self.assertFalse(list(self.repo.glob("control/*")))

    def test_setup_failure_keeps_vm_receipt_without_source_success_or_later_setup(self):
        self.setup_commands(["exit 23", "touch never"])
        history, steps = self.execute_provision(success=False)
        result = json.loads(steps["result"]["outputs"]["stdout"])
        self.assertEqual(history["status"], "failed")
        self.assertTrue(result["ready"])
        self.assertEqual(result["error_code"], "tasks.provision_failed")
        self.assertEqual(result["failed_step"], "setup-0")
        self.assertNotIn("source", result)
        self.assertFalse(self.checkout.joinpath("never").exists())

    def test_unavailable_vm_child_cannot_prepare_workspace_or_claim_success(self):
        self.inputs["intent"]["fixture_vm"]["task_vm_id"] = 99
        history, steps = self.execute_provision(success=False)
        result = json.loads(steps["result"]["outputs"]["stdout"])
        self.assertEqual(history["status"], "failed")
        self.assertEqual(result["failed_step"], "guest")
        self.assertFalse(self.checkout.exists())

    def test_setup_runs_each_command_in_the_checkout_without_exporting_its_dotenv(self):
        self.setup_commands(
            [
                "printf 'SETUP_VALUE=ready\\n' > .env",
                'test -z "${SETUP_VALUE-}"; test "$COMPOSER_NO_INTERACTION" = 1; printf clean > setup-proof',
            ]
        )
        history, steps = self.execute_provision()
        self.assertEqual(history["status"], "succeeded")
        self.assertEqual(self.checkout.joinpath("setup-proof").read_text(), "clean")
        self.assertTrue(json.loads(steps["result"]["outputs"]["stdout"])["ready"])

    def test_laravel_profile_reads_composer_metadata_and_entry_point_over_ssh(self):
        (self.workspace / "composer.json").write_text(
            json.dumps({"require": {"php": "^8.5", "laravel/framework": "^13.0"}})
        )
        (self.workspace / "artisan").write_text("#!/usr/bin/env php\n")
        self.git("add", ".")
        self.git("commit", "-qm", "Laravel")
        self.inputs["intent"]["workspace"].update(
            project_type="laravel-app",
            skip_profile=False,
            start_commit=self.git("rev-parse", "HEAD"),
        )
        history, steps = self.execute_provision()
        result = json.loads(steps["result"]["outputs"]["stdout"])
        self.assertEqual(history["status"], "succeeded")
        self.assertEqual(result["source"]["php_version"], "8.5")
        self.assertTrue(result["source"]["laravel"])

    def test_composer_json_reaches_the_worker_by_copy_and_only_its_facts_reach_history(self):
        # The repository's composer.json holds a credential built at run time.
        # The source and checkout hold it by design; nothing else may: native
        # history, data, logs and the worker's run directory.
        secret = fixture.runtime("ghp_", "C" * 36)
        (self.workspace / "composer.json").write_text(
            json.dumps(
                {
                    "require": {"php": "^8.5", "laravel/framework": "^13.0"},
                    "extra": {"token": secret},
                }
            )
        )
        (self.workspace / "artisan").write_text("#!/usr/bin/env php\n")
        self.git("add", ".")
        self.git("commit", "-qm", "Laravel with a credential")
        self.inputs["intent"]["workspace"].update(
            project_type="laravel-app",
            skip_profile=False,
            start_commit=self.git("rev-parse", "HEAD"),
        )
        history, steps = self.execute_provision()
        self.assertEqual(history["status"], "succeeded")
        source = steps["source"]["outputs"]
        self.assertEqual(
            source["profile"]["source"],
            {
                "branch": "task-30",
                "commit": self.inputs["intent"]["workspace"]["start_commit"],
                "php_version": "8.5",
                "laravel": True,
            },
        )
        logs = self.cli("workflow", "history", "logs", history["id"])
        self.assertNotIn(secret, json.dumps(history) + logs.stdout + logs.stderr)
        needle = secret.encode()
        for path in self.repo.rglob("*"):
            relative = path.relative_to(self.repo)
            if relative.parts[0] in ("workspace", "guest"):
                continue
            if path.is_file() and not path.is_symlink():
                self.assertNotIn(needle, path.read_bytes(), str(relative))
        self.assertFalse(list(self.repo.glob("control/*")))

    def test_source_acquisition_failure_still_returns_vm_readiness_in_root_result(self):
        self.inputs["intent"]["workspace"]["repository"] = str(
            self.repo / "missing-repository"
        )
        history, steps = self.execute_provision(success=False)
        result = json.loads(steps["result"]["outputs"]["stdout"])
        self.assertEqual(history["status"], "failed")
        self.assertTrue(result["ready"])
        self.assertEqual(result["error_code"], "tasks.provision_failed")
        self.assertEqual(result["failed_step"], "clone")

    def test_official_extension_loader_resolves_shared_environment_text_import(self):
        runtime = (
            self.repo
            / "extensions/behaviors/operations/instance-deploy/app_step_runtime.ts"
        )
        runtime.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy(
            ROOT
            / "extensions/behaviors/operations/instance-deploy/app_step_runtime.ts",
            runtime,
        )
        (self.repo / "extensions/behaviors/environment-import.ts").write_text(
            'import { z } from "npm:zod@4.4.3";\nimport { appStepRuntime } from "./operations/instance-deploy/app_step_runtime.ts";\nexport const behavior = { type:"@nckrtl/test-environment-import",version:"2026.11.01.1",\nresources:{public:{schema:z.object({valid:z.boolean()}),lifetime:"infinite",garbageCollection:1}},\nmethods:{read:{description:"Check shipped shared source import",arguments:z.object({}),execute:async (_args,ctx)=>({dataHandles:[await ctx.writeResource("public","output",{valid:appStepRuntime.includes("def parse_project_environment")})]})}}};'
        )
        flow = {
            "id": "855f7c0b-83ab-46c6-8e64-3ec7765873b1",
            "name": "test-environment-import",
            "jobs": [
                {
                    "name": "import",
                    "steps": [
                        {
                            "name": "result",
                            "task": {
                                "type": "behavior",
                                "behaviorType": "@nckrtl/test-environment-import",
                                "behaviorName": "environment-import",
                                "methodName": "read",
                                "inputs": {},
                            },
                        }
                    ],
                }
            ],
        }
        (self.repo / "workflows/workflow-test-environment-import.yaml").write_text(
            yaml.safe_dump(flow)
        )
        reply = self.cli("workflow", "run", "test-environment-import")
        run = self.decode(reply)
        history = json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)
        self.assertTrue(history["jobs"][0]["steps"][0]["outputs"]["valid"])
