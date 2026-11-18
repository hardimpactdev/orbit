#!/usr/bin/env python3
"""Stand-in for the GitHub App edge of an automatic Gateway release, for a nested-fleet proof only.

It answers the api.github.com calls that the Gateway's tick and the `gateway.release` run make: the
installation, scoped tokens, the main commit list, check runs, compare, the branch ref, the web artifact
listing, its CI run and its ZIP. Commits, parents and ancestry come from a real local Git repository.
The state file names which commit is the head of main, which commits are green and which ZIP is each
commit's web build, so "landing" a tree is one edit of the state file. It mints no real credential.

Usage: github_stub.py --state STATE.json --cert CERT.pem --key KEY.pem [--port 443]
State: {"repository": "owner/name", "repository_id": 1, "git_dir": "/path/to/repo.git",
        "main": "<sha>", "green": ["<sha>", ...], "artifacts": {"<sha>": "/path/web.zip"}}
"""

import argparse
import datetime
import hashlib
import json
import os
import re
import ssl
import subprocess
import sys
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse

WORKFLOW = ".github/workflows/ci.yml"
CHECKS = ("Required checks", "Gateway release inputs", "Main delivery checks")


def load(path):
    with open(path, encoding="utf-8") as handle:
        return json.load(handle)


def git(state, *args):
    return subprocess.run(["git", "--git-dir", state["git_dir"], *args], check=True, capture_output=True, text=True).stdout.strip()


def artifact_id(sha):
    return int(sha[:7], 16) + 1


def run_id(sha):
    return int(sha[7:14], 16) + 1


class Handler(BaseHTTPRequestHandler):
    server_version = "orbit-github-stub"

    def log_message(self, fmt, *args):
        sys.stderr.write("%s %s\n" % (datetime.datetime.now(datetime.UTC).strftime("%H:%M:%S"), fmt % args))

    def reply(self, status, body=None, raw=None, content_type="application/json"):
        data = raw if raw is not None else json.dumps(body).encode()
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def payload(self):
        length = int(self.headers.get("Content-Length") or 0)
        return json.loads(self.rfile.read(length) or b"{}") if length else {}

    def do_DELETE(self):
        self.reply(204, raw=b"")

    def do_POST(self):
        state = load(self.server.state_path)
        path = urlparse(self.path).path
        if re.fullmatch(r"/app/installations/1/access_tokens", path):
            requested = self.payload().get("permissions") or {"contents": "read", "actions": "read", "checks": "read"}
            expires = (datetime.datetime.now(datetime.UTC) + datetime.timedelta(minutes=55)).strftime("%Y-%m-%dT%H:%M:%SZ")
            token = "ghs_stub" + hashlib.sha256(os.urandom(16)).hexdigest()[:32]
            return self.reply(201, {"token": token, "expires_at": expires, "permissions": {**requested, "metadata": "read"},
                                    "repositories": [{"full_name": state["repository"]}]})
        self.reply(404, {"message": "Not Found"})

    def do_GET(self):
        state = load(self.server.state_path)
        url = urlparse(self.path)
        query = {key: values[-1] for key, values in parse_qs(url.query).items()}
        repo = "/repos/" + state["repository"]
        path = url.path
        owner_repo = {"id": state["repository_id"], "full_name": state["repository"]}

        if path == repo + "/installation":
            return self.reply(200, {"id": 1})
        if path == "/installation/repositories":
            return self.reply(200, {"total_count": 1, "repositories": [owner_repo]})
        if path == repo:
            return self.reply(200, {**owner_repo, "private": False, "default_branch": "main"})
        if path == repo + "/git/ref/heads/main":
            return self.reply(200, {"ref": "refs/heads/main", "object": {"type": "commit", "sha": state["main"]}})
        if path == repo + "/branches/main":
            return self.reply(200, {"name": "main", "commit": {"sha": state["main"]}})
        if path == repo + "/commits":
            if query.get("sha") not in ("refs/heads/main", "main"):
                return self.reply(404, {"message": "Not Found"})
            rows = []
            for line in git(state, "rev-list", "--first-parent", "--parents", "-n", query.get("per_page", "100"), state["main"]).splitlines():
                sha, *parents = line.split()
                rows.append({"sha": sha, "parents": [{"sha": parent} for parent in parents]})
            return self.reply(200, rows)
        match = re.fullmatch(re.escape(repo) + r"/commits/([0-9a-f]{40})/check-runs", path)
        if match:
            sha = match.group(1)
            names = [query["check_name"]] if "check_name" in query else list(CHECKS)
            runs = [{"id": run_id(sha) * 10 + index, "name": name, "head_sha": sha, "status": "completed", "conclusion": "success",
                     "app": {"slug": "github-actions"}} for index, name in enumerate(names) if sha in state["green"] and name in CHECKS]
            return self.reply(200, {"total_count": len(runs), "check_runs": runs})
        match = re.fullmatch(re.escape(repo) + r"/compare/([0-9a-f]{40})\.\.\.([0-9a-f]{40})", path)
        if match:
            base, head = match.groups()
            merge_base = git(state, "merge-base", base, head)
            ahead = int(git(state, "rev-list", "--count", base + ".." + head))
            behind = int(git(state, "rev-list", "--count", head + ".." + base))
            status = "identical" if ahead == behind == 0 else "ahead" if behind == 0 else "behind" if ahead == 0 else "diverged"
            return self.reply(200, {"status": status, "ahead_by": ahead, "behind_by": behind, "base_commit": {"sha": base},
                                    "merge_base_commit": {"sha": merge_base}, "commits": [], "files": []})
        if path == repo + "/actions/artifacts":
            name = query.get("name", "")
            rows = []
            for sha, archive in state["artifacts"].items():
                if name == "web-dist-" + sha:
                    with open(archive, "rb") as handle:
                        digest = hashlib.sha256(handle.read()).hexdigest()
                    rows.append({"id": artifact_id(sha), "name": name, "size_in_bytes": os.path.getsize(archive), "expired": False,
                                 "digest": "sha256:" + digest, "created_at": "2026-11-18T00:00:00Z",
                                 "workflow_run": {"id": run_id(sha), "head_sha": sha, "head_branch": "main",
                                                  "repository_id": state["repository_id"], "head_repository_id": state["repository_id"]}})
            return self.reply(200, {"total_count": len(rows), "artifacts": rows})
        match = re.fullmatch(re.escape(repo) + r"/actions/runs/(\d+)", path)
        if match:
            for sha in state["artifacts"]:
                if run_id(sha) == int(match.group(1)):
                    return self.reply(200, {"id": run_id(sha), "event": "push", "head_branch": "main", "path": WORKFLOW, "head_sha": sha,
                                            "repository": owner_repo, "head_repository": owner_repo})
        match = re.fullmatch(re.escape(repo) + r"/actions/artifacts/(\d+)/zip", path)
        if match:
            for sha, archive in state["artifacts"].items():
                if artifact_id(sha) == int(match.group(1)):
                    with open(archive, "rb") as handle:
                        return self.reply(200, raw=handle.read(), content_type="application/zip")
        self.reply(404, {"message": "Not Found"})


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--state", required=True)
    parser.add_argument("--cert", required=True)
    parser.add_argument("--key", required=True)
    parser.add_argument("--port", type=int, default=443)
    args = parser.parse_args()
    server = ThreadingHTTPServer(("127.0.0.1", args.port), Handler)
    server.state_path = args.state
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain(args.cert, args.key)
    server.socket = context.wrap_socket(server.socket, server_side=True)
    server.serve_forever()


if __name__ == "__main__":
    main()
