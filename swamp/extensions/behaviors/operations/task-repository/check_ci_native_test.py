"""Native check admission and bounded CI ticks using disposable Git/GitHub fixtures."""

import datetime
import json
import unittest

import yaml
from publication_native_test import ROOT, TOKEN, NativePublicationFixture


def check_result(history):
    """Read the producer output selected by the Gateway's single history read."""
    steps = {step["name"]: step for step in history["jobs"][0]["steps"]}
    if "collect" in steps:
        return steps["collect"]["outputs"]["stdout"]
    return steps["Push the candidate"]["outputs"]["finish"]["result_json"]


def child_steps(history):
    """Every step of the run: the parent's, and each child's from its parent step's outputs."""
    rows = []
    for step in history["jobs"][0]["steps"]:
        rows.append(step)
        for name, outputs in (step.get("outputs") or {}).items():
            if isinstance(outputs, dict):
                rows.append({"name": name, "status": "succeeded", "outputs": outputs})
    return rows

class NativeCheckCI(NativePublicationFixture):
    def setUp(self):
        super().setUp()
        self.inputs["kind"] = "tasks.check"
        intent = self.inputs["intent"]
        intent.update(
            subtask=4,
            check_id=5,
            phase="handoff",
            command="test -f code",
            setup=[],
            deliverables=[],
            base=self.head,
            message="Task check",
            check_name="Required checks",
            branch="task-1-handoff",
            ssr_port=None,
            admitted_at=datetime.datetime.now(datetime.UTC).isoformat(),
        )
        intent["command_deliverables"] = []
        self.inputs["execution_secrets"] = {}
        for name, level in [
            ("github_token", "write"),
            ("checks_token", "read"),
            ("git_authorization", "git"),
        ]:
            key = "task-check-" + level + "-aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa"
            self.inputs["execution_secrets"][name] = {
                "vault": "orbit-control-inputs",
                "key": key,
            }
        intent["execution_references_json"] = json.dumps(
            self.inputs["execution_secrets"]
        )
        self.server.mint_copies = self.inputs["execution_secrets"]
        self.server.mints = 0

        def publish_copies():
            # The Gateway mints the push credential in the Git extra-header form.
            for name, reference in self.server.mint_copies.items():
                value = self.authorization if name == "git_authorization" else TOKEN
                self.cli(
                    "vault", "put", reference["vault"], reference["key"], input=value
                )

        self.server.publish_copies = publish_copies
        previous_post = self.server.RequestHandlerClass.do_POST

        def mint_reply(handler):
            if handler.path.endswith("/github-token"):
                import json

                handler.rfile.read(int(handler.headers.get("Content-Length", 0)))
                handler.server.publish_copies()
                handler.server.mints += 1
                handler.send_response(200)
                handler.end_headers()
                handler.wfile.write(
                    json.dumps(
                        {"data": {"execution_secrets": handler.server.mint_copies}}
                    ).encode()
                )
            else:
                previous_post(handler)

        self.server.RequestHandlerClass = type(
            "MintFixture", (self.server.RequestHandlerClass,), {"do_POST": mint_reply}
        )
        self.flow = self.copy_flow("workflow-operation-task-check.yaml")
        self.flow["name"] = "task-check-fixture"
        (self.repo / "workflows/workflow-check-fixture.yaml").write_text(
            yaml.safe_dump(self.flow, sort_keys=False)
        )
        for child in sorted((ROOT / "workflows").glob("workflow-task-check-*.yaml")):
            if child.name != "workflow-task-check-ci.yaml":
                (self.repo / "workflows" / child.name).write_text(
                    yaml.safe_dump(self.copy_flow(child.name), sort_keys=False)
                )
        schedule = self.copy_flow("workflow-task-check-ci.yaml")
        (self.repo / "workflows/workflow-check-ci.yaml").write_text(
            yaml.safe_dump(schedule, sort_keys=False)
        )

    def execute(self, name, inputs=None, success=True):
        reply = self.cli(
            "workflow",
            "run",
            name,
            "--stdin",
            input=json.dumps(inputs or {}),
            success=success,
        )
        # Native SSH guards can produce public notices before the final JSON object.
        decoder = json.JSONDecoder()
        run = None
        for index, c in enumerate(reply.stdout):
            if c == "{":
                try:
                    value = decoder.raw_decode(reply.stdout[index:])[0]
                    if isinstance(value, dict) and "id" in value and "status" in value:
                        run = value
                        break
                except ValueError:
                    pass
        self.assertIsNotNone(run, reply.stdout)
        history = json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)
        return history, json.loads(check_result(history))

    def reading(self, status="in_progress", conclusion=None):
        self.server.pulls = {
            "total_count": 1,
            "check_runs": [
                {
                    "id": 10,
                    "name": "Required checks",
                    "head_sha": self.inputs["intent"]["commit"],
                    "status": status,
                    "conclusion": conclusion,
                    "app": {"slug": "github-actions"},
                    "output": {"summary": "not public evidence"},
                }
            ],
        }

    def test_native_pending_check_reads_once_each_tick_and_stops_when_settled(self):
        root, admitted = self.execute("task-check-fixture", self.inputs)
        self.assertTrue(admitted["pending"])
        self.assertEqual(self.server.mints, 1)
        self.assertEqual(
            len([call for call in self.server.calls if call[0] == "DELETE"]), 1
        )
        self.inputs["intent"]["commit"] = admitted["commit"]
        self.reading()
        _, pending = self.execute("task-check-ci")
        self.assertEqual(len(pending["checks"]), 1)
        self.assertTrue(pending["checks"][0]["result"]["pending"])
        self.assertEqual(
            len([call for call in self.server.calls if call[0] == "GET"]), 1
        )
        self.reading("completed", "success")
        _, completed = self.execute("task-check-ci")
        self.assertEqual(
            len([call for call in self.server.calls if call[0] == "GET"]), 2
        )
        self.assertEqual(len(completed["checks"]), 1)
        row = completed["checks"][0]
        self.assertEqual(row["root_run_id"], root["id"])
        self.assertEqual(row["operation_id"], 15)
        self.assertEqual(row["result"]["ci"]["state"], "passed")
        self.assertEqual(row["result"]["commit"], admitted["commit"])
        self.assertNotIn("summary", json.dumps(completed))
        self.assertEqual(
            len([call for call in self.server.calls if call[0] == "DELETE"]), 2
        )
        _, idle = self.execute("task-check-ci")
        self.assertEqual(idle, {"checks": []})
        self.assertEqual(
            len([call for call in self.server.calls if call[0] == "GET"]), 2
        )

    def test_failed_push_revokes_both_tokens_without_leaving_pending_ci(self):
        hook = self.remote / "hooks/pre-receive"
        hook.write_text("#!/bin/sh\nexit 1\n")
        hook.chmod(0o755)
        _, result = self.execute("task-check-fixture", self.inputs, success=False)
        self.assertFalse(result["pending"])
        self.assertEqual(result["error_code"], "tasks.push_failed")
        self.assertEqual(self.server.mints, 1)
        self.assertEqual(
            len([call for call in self.server.calls if call[0] == "DELETE"]), 2
        )
        _, idle = self.execute("task-check-ci")
        self.assertEqual(idle, {"checks": []})
        self.assertFalse([call for call in self.server.calls if call[0] == "GET"])


if __name__ == "__main__":
    unittest.main()
