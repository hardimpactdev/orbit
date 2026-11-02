"""Static Python effects and private-value flow. Reads source on stdin, never executes it."""
import ast
import json
import re
import sys

from swamp_shape_host_tools import php_runtime_transaction
from swamp_shape_polling import (
    external_origin,
    python_controls,
    shell_controls,
    source_bindings,
)

SECRET = re.compile(r"secret|password|passwd|credential|private.?key|api.?key|token|identity", re.I)
# Guide rule 6: these are existing per-host tool transactions, not workflow locks.
HOST_TOOL_LOCKS = {
    "/run/lock/orbit-firewall.lock": "UFW numbered-rule transaction",
    "/run/lock/orbit-self-update.lock": "atomic observer binary/config transaction",
    "/run/lock/orbit-wireguard-peer.lock": "WireGuard peer config/syncconf transaction",
    "/run/orbit/project-documents/execution.lock": "documents cleanup tool inode",
}
SAFE_REF = re.compile(r"(?:key|id|ref|name|hash|digest|public|field)s?$", re.I)


def scan(source, gateway_primitives=(), context=None, phase_seconds=0, phase_ssh=False):
    findings = []
    try:
        tree = ast.parse(source)
    except SyntaxError:
        return [{"rule": "unknown-python", "count": 1, "line": 1}]
    aliases = {}
    tainted = set()
    for node in ast.walk(tree):
        if isinstance(node, ast.Import):
            aliases.update({a.asname or a.name: a.name for a in node.names})
        if isinstance(node, ast.ImportFrom):
            aliases.update({a.asname or a.name: f"{node.module}.{a.name}" for a in node.names})

    def name(node):
        if isinstance(node, ast.Name):
            return aliases.get(node.id, node.id)
        if isinstance(node, ast.Attribute):
            return f"{name(node.value)}.{node.attr}"
        return ""

    parents, bound, statement = source_bindings(tree)
    bindings = {}
    for node in ast.walk(tree):
        if isinstance(node, ast.Assign):
            for target in node.targets:
                if isinstance(target, ast.Name):
                    bindings.setdefault(target.id, []).append(node.value)

    def checked_integer(identifier, at):
        for guard in ast.walk(tree):
            if not isinstance(guard, ast.If) or guard.lineno >= at.lineno or parents.get(guard) is not parents.get(statement(at)):
                continue
            test = guard.test
            if not isinstance(test, ast.UnaryOp) or not isinstance(test.op, ast.Not) or not isinstance(test.operand, ast.Call):
                continue
            check = test.operand
            if name(check.func) != "re.fullmatch" or len(check.args) != 2 or not isinstance(check.args[0], ast.Constant) or check.args[0].value != "[1-9][0-9]*":
                continue
            if isinstance(check.args[1], ast.Name) and check.args[1].id == identifier and guard.body and isinstance(guard.body[0], ast.Raise):
                overwritten = any(isinstance(part, ast.Name) and isinstance(part.ctx, ast.Store) and part.id == identifier and guard.lineno < part.lineno < at.lineno for part in ast.walk(tree))
                if not overwritten:
                    return True
        return False

    def string(node, at, seen=frozenset()):
        if isinstance(node, ast.Constant) and isinstance(node.value, str):
            return node.value
        if isinstance(node, ast.Name) and node.id not in seen:
            if checked_integer(node.id, at):
                return "{operation_id}"
            value = bound(node.id, at)
            if value is not None:
                return string(value, at, seen | {node.id})
        if isinstance(node, ast.BinOp) and isinstance(node.op, ast.Add):
            left, right = string(node.left, at, seen), string(node.right, at, seen)
            if left is not None and right is not None:
                return left + right
        return None

    def prefix(node, at, seen=frozenset()):
        exact = string(node, at)
        if exact is not None:
            return exact
        if isinstance(node, ast.Name) and node.id not in seen:
            value = bound(node.id, at)
            if value is not None:
                return prefix(value, at, seen | {node.id})
        if isinstance(node, ast.BinOp) and isinstance(node.op, ast.Add):
            return prefix(node.left, at, seen)
        return None

    phase_controls = python_controls(tree, phase_seconds, bound, context, phase_ssh)

    def lock_path(node, at, seen=frozenset()):
        if isinstance(node, ast.Name) and node.id not in seen:
            value = bound(node.id, at)
            return lock_path(value, at, seen | {node.id}) if value is not None else None
        if isinstance(node, ast.Constant) and isinstance(node.value, str):
            return node.value
        if isinstance(node, ast.BinOp) and isinstance(node.op, ast.Div):
            left, right = lock_path(node.left, at, seen), lock_path(node.right, at, seen)
            if left is not None and right is not None and not right.startswith("/"):
                return left.rstrip("/") + "/" + right
        if isinstance(node, ast.Call):
            called = name(node.func)
            if called in ("open", "os.open", "os.fdopen", "pathlib.Path", "Path") and node.args:
                return lock_path(node.args[0], at, seen)
            if isinstance(node.func, ast.Attribute) and node.func.attr in ("open", "fileno"):
                return lock_path(node.func.value, at, seen)
        return None

    def host_tool_lock(node):
        if len(node.args) != 2:
            return False
        if lock_path(node.args[0], node) not in HOST_TOOL_LOCKS:
            descriptor = bound(node.args[0].id, node) if isinstance(node.args[0], ast.Name) else None
            if not php_runtime_transaction(tree, context or {}, descriptor, node):
                return False
        flags = node.args[1]
        parts = [flags.left, flags.right] if isinstance(flags, ast.BinOp) and isinstance(flags.op, ast.BitOr) else [flags]
        modes = {name(part) for part in parts}
        return modes in ({"fcntl.LOCK_EX"}, {"fcntl.LOCK_EX", "fcntl.LOCK_NB"}, {"fcntl.LOCK_UN"})

    def allowed(method, url):
        if method != "POST" or not url or re.search(r"[?#\\]", url):
            return False
        path = re.sub(r"^https?://[^/]+", "", url)
        return any(re.fullmatch(re.escape(route).replace(r"\{operation_id\}", r"(?:[1-9][0-9]*|\{operation_id\})"), path) for route in gateway_primitives)

    def gateway_request(node, call):
        method = url = None
        url_node = None
        if call == "urllib.request.Request":
            url_node = node.args[0] if node.args else next((item.value for item in node.keywords if item.arg == "url"), None)
            url = string(url_node, node)
            keywords = {item.arg: item.value for item in node.keywords}
            method = string(keywords.get("method"), node)
            if method is None and "method" not in keywords:
                method = "POST" if "data" in keywords else "GET"
        elif call in ("requests.get", "requests.post", "requests.put", "requests.patch", "requests.delete", "requests.request", "httpx.get", "httpx.post", "httpx.put", "httpx.patch", "httpx.delete", "httpx.request", "urllib.request.urlopen"):
            generic = call in ("requests.request", "httpx.request")
            method = string(node.args[0], node) if generic and node.args else call.rsplit(".", 1)[1].upper()
            if call == "urllib.request.urlopen":
                method = "GET"
            index = 1 if generic else 0
            url_node = node.args[index] if len(node.args) > index else next((item.value for item in node.keywords if item.arg == "url"), None)
            url = string(url_node, node)
        else:
            return
        external = external_origin(prefix(url_node, node))
        if not external and re.search(r"gateway|/api/v1/", source, re.I) and not allowed(method, url):
            findings.append({"rule": "gateway-http", "count": 1, "line": node.lineno, "evidence": [f"{method or '<unknown>'} {url or '<unknown>'}"]})

    def private(node):
        for part in ast.walk(node):
            if isinstance(part, ast.Name) and part.id in tainted:
                return True
            if isinstance(part, ast.Subscript) and name(part.value) in ("os.environ", "environ"):
                if isinstance(part.slice, ast.Constant) and isinstance(part.slice.value, str) and SECRET.search(part.slice.value):
                    return True
            if isinstance(part, ast.Call) and name(part.func) in ("os.getenv", "os.environ.get", "environ.get"):
                if part.args and isinstance(part.args[0], ast.Constant) and SECRET.search(str(part.args[0].value)):
                    return True
        return False

    for _ in range(8):
        for node in ast.walk(tree):
            if isinstance(node, (ast.Assign, ast.AnnAssign, ast.NamedExpr)) and node.value is not None and private(node.value):
                targets = node.targets if isinstance(node, ast.Assign) else [node.target]
                for target in targets:
                    tainted.update(n.id for n in ast.walk(target) if isinstance(n, ast.Name))

    def add(node, rule):
        findings.append({"rule": rule, "count": 1, "line": getattr(node, "lineno", 1)})

    for node in ast.walk(tree):
        if isinstance(node, ast.Call):
            call = name(node.func)
            if isinstance(node.func, ast.Name) and len(bindings.get(node.func.id, [])) == 1:
                call = name(bindings[node.func.id][0]) or call
            gateway_request(node, call)
            if call in ("time.sleep", "asyncio.sleep", "fcntl.flock", "fcntl.lockf"):
                if node not in phase_controls and (call != "fcntl.flock" or not host_tool_lock(node)):
                    add(node, "custom-control")
            if call in ("subprocess.run", "subprocess.Popen", "subprocess.call", "subprocess.check_call", "subprocess.check_output", "os.system", "os.execv", "os.execve"):
                argv = node.args[:1] + [k.value for k in node.keywords if k.arg in ("args", "executable")]
                if any(private(arg) for arg in argv):
                    add(node, "secret-argv")
            if call in ("print", "logging.info", "logging.warning", "logging.error", "logging.debug", "json.dump") or call.endswith((".write", ".write_text", ".write_bytes")):
                if any(private(arg) for arg in node.args):
                    # File writes are permitted only as protected config, which this heuristic cannot prove.
                    add(node, "secret-output")
        if isinstance(node, ast.While) and isinstance(node.test, ast.Constant) and node.test.value:
            if node not in phase_controls:
                add(node, "custom-control")
        if isinstance(node, (ast.For, ast.AsyncFor)) and any(isinstance(part, ast.Name) and re.search(r"attempt|retry|poll", part.id, re.I) for part in ast.walk(node.target)):
            add(node, "custom-control")
    return findings


if __name__ == "__main__":
    primitives = json.loads(sys.argv[1]) if len(sys.argv) > 1 and sys.argv[1] != "--phase-controls" else []
    payload = sys.stdin.read()
    if len(sys.argv) > 1 and sys.argv[1] == "--phase-controls":
        payload = json.loads(payload)
        print(json.dumps({file: shell_controls(value['source'], value['seconds'])
                          for file, value in payload.items()}))
    elif len(sys.argv) > 2 and sys.argv[2] == "--source-context":
        payload = json.loads(payload)
        print(json.dumps(scan(payload["source"], primitives, payload["sources"], payload.get("phase_seconds", 0), payload.get("phase_ssh", False))))
    else:
        print(json.dumps(scan(payload, primitives)))
