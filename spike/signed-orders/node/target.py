"""SPIKE(signed-orders) round 2: a wake target. It holds about 200 MB and answers HTTP 200 on 8000 + id."""
import http.server
import sys

target = int(sys.argv[1])
memory = bytearray(200 * 1024 * 1024)
for i in range(0, len(memory), 4096):
    memory[i] = 1


class Ok(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        self.send_response(200)
        self.end_headers()
        self.wfile.write(b"ready\n")

    def log_message(self, *args):
        pass


http.server.ThreadingHTTPServer(("127.0.0.1", 8000 + target % 1000), Ok).serve_forever()
