"""Native check admission and bounded CI ticks using disposable Git/GitHub fixtures."""

import datetime
import json
import unittest

import yaml
from publication_actions_test import TOKEN
from publication_native_test import ROOT, NativePublication


class NativeCheckCI(NativePublication):
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
        intent["setup_steps"] = []
        intent["command_deliverables"] = []
        intent["project_command_json"] = json.dumps({"command": intent["command"]})
        self.inputs["execution_secrets"] = {}
        for name, level in [("github_token", "write"), ("checks_token", "read")]:
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
            for reference in self.server.mint_copies.values():
                self.cli(
                    "vault", "put", reference["vault"], reference["key"], input=TOKEN
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
        # Only fixture transport changes the canonical Gateway origin/CA.
        shim = self.repo / "tools/sitecustomize.py"
        original = shim.read_text().replace(
            "import subprocess,urllib.request", "import subprocess,urllib.request,ssl"
        )
        original = original.replace(
            "  request.full_url=request.full_url.replace",
            "  request.full_url=request.full_url.replace('https://gateway.orbit','http://127.0.0.1:"
            + str(self.server.server_port)
            + "')\n  request.full_url=request.full_url.replace",
        )
        original += "\noriginal_context=ssl.create_default_context\ndef context(*a,**kw):\n kw.pop('cafile',None)\n return original_context(*a,**kw)\nssl.create_default_context=context\n"
        shim.write_text(original)
        self.flow = self.copy_flow("workflow-operation-task-check.yaml")
        self.flow["name"] = "task-check-fixture"
        (self.repo / "workflows/workflow-check-fixture.yaml").write_text(
            yaml.safe_dump(self.flow, sort_keys=False)
        )
        schedule = self.copy_flow("workflow-task-check-ci.yaml")
        (self.repo / "workflows/workflow-check-ci.yaml").write_text(
            yaml.safe_dump(schedule, sort_keys=False)
        )

    def copy_flow(self, name):
        flow = yaml.safe_load((ROOT / "workflows" / name).read_text())

        def rewrite(value):
            if isinstance(value, str):
                return value.replace(
                    "/tmp/orbit-task-check-", str(self.repo / "check-")
                )
            if isinstance(value, list):
                return [rewrite(item) for item in value]
            if isinstance(value, dict):
                return {k: rewrite(v) for k, v in value.items()}
            return value

        flow = rewrite(flow)
        for job in flow["jobs"]:
            job.pop("target", None)
            job.pop("labels", None)
            for step in job["steps"]:
                step.pop("target", None)
                step.pop("labels", None)
        return flow

    def execute(self, name, inputs=None):
        reply = self.cli(
            "workflow", "run", name, "--stdin", input=json.dumps(inputs or {})
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
        result = next(x for x in history["jobs"][0]["steps"] if x["name"] == "result")
        return history, json.loads(result["outputs"]["stdout"])

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
        _, idle = self.execute("task-check-ci")
        self.assertEqual(idle, {"checks": []})
        self.assertEqual(
            len([call for call in self.server.calls if call[0] == "GET"]), 2
        )


if __name__ == "__main__":
    unittest.main()
