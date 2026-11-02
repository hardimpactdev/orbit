"""Synthetic Pi HTTP server used only inside a disposable proof Node."""

import json
import re
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path

workspace = Path("/home/orbit/turn-proof")
credential = "a1" * 32
calls = []
turn = None


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *_args):
        pass

    def authorized(self):
        if (
            self.headers.get("Authorization")
            == "Bearer synthetic-pi-test-token-with-at-least-32-characters"
        ):
            return True
        self.send_response(401)
        self.end_headers()
        return False

    def do_POST(self):
        global turn
        if not self.authorized():
            return
        body = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
        calls.append(self.path)
        if self.path.endswith("/messages"):
            turn = body["key"]
            # Like an agent, read the thread and the first allowed outcome from the prompt's
            # receipt instruction, then write the receipt file directly.
            text = body["text"]
            thread = int(re.search(r"- thread is ([0-9]+),", text).group(1))
            outcome = re.search(r"- outcome is one of: ([a-z_]+)", text).group(1)
            (workspace / "file").write_text("MODEL_KEY=" + credential + "\n")
            (workspace / "untracked").write_text("proof addition\n")
            (workspace / ".git/orbit/receipt.json").write_text(
                json.dumps(
                    {
                        "thread": thread,
                        "outcome": outcome,
                        "summary": "key " + credential,
                    }
                )
            )
            (workspace / ".git/orbit/pi-calls.json").write_text(json.dumps(calls))
        self.send_response(200)
        self.end_headers()
        self.wfile.write(b'{"accepted":true}')

    def do_GET(self):
        if not self.authorized():
            return
        self.send_response(200)
        self.end_headers()
        self.wfile.write(
            json.dumps(
                {"state": "done", "turnId": turn, "usage": {"total": 4}}
            ).encode()
            + b"\n"
        )


HTTPServer(("0.0.0.0", 3774), Handler).serve_forever()
