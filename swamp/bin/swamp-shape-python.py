"""Static Python effects and private-value flow. Reads source on stdin, never executes it."""
import ast
import json
import re
import sys

SECRET = re.compile(r"secret|password|passwd|credential|private.?key|api.?key|token|identity", re.I)
SAFE_REF = re.compile(r"(?:key|id|ref|name|hash|digest|public|field)s?$", re.I)


def scan(source):
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
            if call in ("time.sleep", "asyncio.sleep", "fcntl.flock", "fcntl.lockf"):
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
            add(node, "custom-control")
    return findings


if __name__ == "__main__":
    print(json.dumps(scan(sys.stdin.read())))
