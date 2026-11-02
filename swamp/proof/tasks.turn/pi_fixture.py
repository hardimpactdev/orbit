"""Synthetic Pi HTTP server used only inside a disposable proof Node."""

import json
import subprocess
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path

workspace = Path("/home/orbit/turn-proof")
credential = "a1" * 32
calls = []
turn = None


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *_args):
        pass

    def do_POST(self):
        global turn
        body = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
        calls.append(self.path)
        if self.path.endswith("/messages"):
            turn = body["key"]
            metadata = json.loads((workspace / ".git/orbit/turn.json").read_text())
            (workspace / "file").write_text("MODEL_KEY=" + credential + "\n")
            (workspace / "untracked").write_text("proof addition\n")
            outcome = {
                "reviewer": "approved",
                "consult": "answered",
                "implementer": "ready_for_review",
            }[metadata["role"]]
            subprocess.run(
                [
                    str(workspace / ".git/orbit/turn"),
                    "--thread=" + str(metadata["thread"]),
                    "--outcome=" + outcome,
                    "--summary=key " + credential,
                ],
                check=True,
            )
            (workspace / ".git/orbit/pi-calls.json").write_text(json.dumps(calls))
        self.send_response(200)
        self.end_headers()
        self.wfile.write(b'{"accepted":true}')

    def do_GET(self):
        self.send_response(200)
        self.end_headers()
        self.wfile.write(
            json.dumps(
                {"state": "done", "turnId": turn, "usage": {"total": 4}}
            ).encode()
            + b"\n"
        )


HTTPServer(("0.0.0.0", 3774), Handler).serve_forever()
