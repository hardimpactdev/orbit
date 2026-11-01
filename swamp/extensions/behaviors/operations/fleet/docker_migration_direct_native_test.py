"""One native parent with fake system boundaries; no host or private credential action."""
import json
import os
from pathlib import Path
import secrets
import shutil
import sys

ROOT = Path(__file__).resolve().parents[4]
FAMILY = Path(__file__).resolve().parent
sys.path.insert(0, str(ROOT / "tests"))
from native_harness import NativeTestCase


class DockerMigrationNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        canonical = Path(os.environ.get("ORBIT_DOCKER_SYSTEM_SOURCE_ROOT", ROOT))
        files = ["automatic-deploy/development.ts", "automatic-deploy/gateway.ts", "automatic-deploy/policy.ts",
                 "node-worker/command.ts", "operations/_lib/direct.ts", "operations/_lib/node-command-process.ts",
                 "operations/_lib/node-commands.ts", "operations/node/docker_migration_system.ts", "operations/node/system_command.ts",
                 "operations/processes-schedules/process-system.ts", "operations/processes-schedules/system.ts"]
        for file in files:
            dest = self.repo / "extensions/behaviors" / file
            dest.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy(canonical / "extensions/behaviors" / file, dest)
        dest = self.repo / "extensions/behaviors/operations/fleet"
        dest.mkdir(parents=True, exist_ok=True)
        for name in ("docker_migration_direct.ts", "docker_migration_direct_native_fixture.ts"):
            shutil.copy(FAMILY / name, dest / name)
        source = dest / "docker_migration_direct.ts"
        source.write_text('import {fixtureHttp, fixtureAction} from "./docker_migration_direct_native_fixture.ts";\n' +
                          source.read_text().replace("export const behavior = dockerMigration();", "export const behavior = dockerMigration(() => fixtureHttp, undefined, fixtureAction);"))
        workflow = ROOT / "workflows/workflow-operation-fleet-docker-migrate.yaml"
        # Scratch local execution only. Shipped workflow keeps its exact Node placement.
        (self.repo / "workflows" / workflow.name).write_text("\n".join(line for line in workflow.read_text().splitlines() if not line.startswith(("target:", "labels:"))))
        sentinel = secrets.token_hex(32)
        self.native_env = {"ORBIT_DOCKER_SENTINEL": sentinel}
        self.secrets = (sentinel,)
        self.state = {"mode": "success", "calls": [], "effects": [], "operation": {"id": 17, "kind": "fleet.docker_migrate", "node_id": 14,
                      "status": "queued", "cancel_requested": False, "result": None, "input": {"mode": "migrate", "migration": {
                      "recipe": "sha256:recipe", "snapshot": "20261001T000000Z", "architecture": "amd64", "docker_version": "29.1", "containerd_version": "2.2",
                      "process_ids": ["7"], "process_states": {"7": "running"}, "process_specs": {"7": {"name": "orbit-process-7-web", "hash": "a"*64, "volumes": []}}, "fallback": False}}}}

    def run_parent(self, success):
        (self.repo / "docker-native.json").write_text(json.dumps(self.state))
        self.cli("workflow", "validate", "operation-fleet-docker-migrate")
        self.cli("workflow", "run", "operation-fleet-docker-migrate", "--input", '{"operation_id":17,"node_id":14,"kind":"fleet.docker_migrate"}', success=success)
        self.assert_private_absent()
        return json.loads((self.repo / "docker-native.json").read_text())

    def test_one_parent_reconciles_then_projects_without_secret_history(self):
        result = self.run_parent(True)
        self.assertEqual(result["effects"], ["prepare", "reconcile", "report"])
        self.assertEqual(result["operation"]["status"], "succeeded")

    def test_confirmed_refusal_reports_same_parent_and_skips_reconcile(self):
        self.state["mode"] = "refused"
        result = self.run_parent(False)
        self.assertEqual(result["effects"], ["prepare"])
        self.assertEqual(result["operation"]["status"], "failed")
        self.assertEqual(result["operation"]["result"]["error_code"], "docker.migration_failed")

    def test_uncertain_process_outcome_is_terminal_without_effect_replay(self):
        self.state["mode"] = "uncertain"
        result = self.run_parent(False)
        self.assertEqual(result["effects"], ["prepare", "reconcile"])
        self.assertEqual(result["operation"]["result"]["error_code"], "operation.native_outcome_unknown")
