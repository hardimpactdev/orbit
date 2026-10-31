"""Local CE contract fixture. No fleet endpoint or credential is used."""

import json
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import unquote


class PlausibleFixture:
    def __init__(self):
        self.sites = {}
        self.calls = []
        self.fail = ""
        self.bad_goals = False
        self.password = "provider-password-938725"
        self.api_key = "provider-api-key-736529"
        self.plugin = "provider-plugin-824235"
        self.cookie = "provider-cookie-824369"
        owner = self

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *_):
                pass

            def handle_call(self):
                path = unquote(self.path)
                owner.calls.append((self.command, path))
                body = self.rfile.read(int(self.headers.get("Content-Length", 0)))
                data = json.loads(body) if body else {}
                status, headers, response = 200, {}, ""
                form = '<meta name="csrf-token" content="private-csrf-826495">'
                if (
                    path.startswith("/api/")
                    and self.headers.get("Accept") != "application/json"
                ):
                    status = 406
                elif (
                    path not in ("/login", "/logout")
                    and not path.startswith("/api/")
                    and self.headers.get("Cookie") != "session=" + owner.cookie
                ):
                    status = 401
                elif (
                    self.command in ("POST", "PUT")
                    and not path.startswith("/api/")
                    and self.headers.get("X-CSRF-Token") != "private-csrf-826495"
                ):
                    status = 403
                elif owner.fail and owner.fail in path:
                    status = 503
                    response = (
                        owner.password
                    )  # must never escape the action's safe error
                elif path in ("/login", "/sites/new") and self.command == "GET":
                    response = form
                elif path == "/login":
                    if data.get("password") != owner.password:
                        status = 401
                    else:
                        status, headers = (
                            302,
                            {
                                "Location": "/sites",
                                "Set-Cookie": "session=" + owner.cookie + "; Path=/",
                            },
                        )
                elif path == "/logout":
                    status, headers = (
                        302,
                        {
                            "Location": "/login",
                            "Set-Cookie": "session=; Max-Age=0; Path=/",
                        },
                    )
                elif path == "/api/v2/query":
                    status = (
                        200
                        if self.headers.get("Authorization")
                        == "Bearer " + owner.api_key
                        else 401
                    )
                elif path == "/api/plugins/v1/goals":
                    import base64

                    expected = (
                        "Basic "
                        + base64.b64encode((":" + owner.plugin).encode()).decode()
                    )
                    status = (
                        201 if self.headers.get("Authorization") == expected else 401
                    )
                    goals = data["goals"]
                    response = json.dumps(
                        {
                            "goals": []
                            if owner.bad_goals
                            else [
                                {**row, "goal": {**row["goal"], "id": i + 1}}
                                for i, row in enumerate(goals)
                            ]
                        }
                    )
                elif path == "/sites" and self.command == "POST":
                    site = data["site"]
                    owner.sites[site["domain"]] = site["timezone"]
                    status, headers = (
                        302,
                        {"Location": "/" + site["domain"] + "/installation"},
                    )
                elif path.endswith("/settings/general"):
                    domain = path.split("/")[1]
                    if domain not in owner.sites:
                        status = 404
                    else:
                        response = (
                            form
                            + '<select name="site[timezone]"><option value="'
                            + owner.sites[domain]
                            + '" selected>UTC</option></select>'
                        )
                elif path.endswith("/settings") and self.command == "PUT":
                    owner.sites.pop(path.split("/")[1], None)
                    site = data["site"]
                    owner.sites[site["domain"]] = site["timezone"]
                    status, headers = (
                        302,
                        {"Location": "/" + site["domain"] + "/settings/general"},
                    )
                else:
                    status = 404
                self.send_response(status)
                for key, value in headers.items():
                    self.send_header(key, value)
                self.end_headers()
                self.wfile.write(response.encode())

            do_GET = handle_call
            do_POST = handle_call
            do_PUT = handle_call

        self.server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()
        self.url = "http://127.0.0.1:" + str(self.server.server_port)

    def close(self):
        self.server.shutdown()
        self.server.server_close()
        self.thread.join()

    @property
    def credentials(self):
        return {
            "email": "fixture@example.test",
            "password": self.password,
            "apiKey": self.api_key,
            "plugins": {"domain": "shop.test", "token": self.plugin},
        }
