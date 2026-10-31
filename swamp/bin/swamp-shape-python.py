"""Static Python effects and private-value flow. Reads source on stdin, never executes it."""
import ast
import json
import re
import sys
from urllib.parse import urlsplit

SECRET = re.compile(r"secret|password|passwd|credential|private.?key|api.?key|token|identity", re.I)
# Guide rule 6: these are existing per-host tool transactions, not workflow locks.
HOST_TOOL_LOCKS = {
    "/run/lock/orbit-firewall.lock": "UFW numbered-rule transaction",
    "/run/lock/orbit-self-update.lock": "atomic observer binary/config transaction",
    "/run/orbit/project-documents/execution.lock": "documents cleanup tool inode",
}
SAFE_REF = re.compile(r"(?:key|id|ref|name|hash|digest|public|field)s?$", re.I)


def scan(source, gateway_primitives=()):
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

    parents = {child: parent for parent in ast.walk(tree) for child in ast.iter_child_nodes(parent)}
    bindings = {}
    for node in ast.walk(tree):
        if isinstance(node, ast.Assign):
            for target in node.targets:
                if isinstance(target, ast.Name):
                    bindings.setdefault(target.id, []).append(node.value)

    def scope(node):
        while node in parents and not isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef, ast.Lambda)):
            node = parents[node]
        return node

    def bound(identifier, at):
        current = scope(at)
        while current is not None:
            values = [(part, part.value) for part in ast.walk(current)
                      if isinstance(part, (ast.Assign, ast.AnnAssign, ast.AugAssign, ast.NamedExpr)) and scope(part) is current
                      and any(isinstance(target, ast.Name) and target.id == identifier
                              for target in (part.targets if isinstance(part, ast.Assign) else [part.target]))]
            values += [(part, item.context_expr) for part in ast.walk(current)
                       if isinstance(part, (ast.With, ast.AsyncWith)) and scope(part) is current
                       for item in part.items if isinstance(item.optional_vars, ast.Name) and item.optional_vars.id == identifier]
            if values:
                # Function bodies may run after later module/closure rebindings.
                # Only a syntactically immutable outer binding is provable here.
                if current is not scope(at) and (len(values) != 1 or any(
                    isinstance(part, (ast.Global, ast.Nonlocal)) and identifier in part.names
                    for part in ast.walk(tree)
                )):
                    return None
                # A unique binding is sufficient; repeated assignment needs a final
                # unconditional binding that dominates the use, not a branch guess.
                prior = [(part, value) for part, value in values if part.lineno <= at.lineno]
                if not prior:
                    return None
                part, value = max(prior, key=lambda item: item[0].lineno)
                if isinstance(part, (ast.AugAssign, ast.NamedExpr)):
                    return None
                parent = parents.get(part)
                use = at
                while use in parents and parents[use] is not parent:
                    use = parents[use]
                if part is use or (parent is not None and parents.get(use) is parent
                    and any(isinstance(items, list) and part in items and use in items and items.index(part) < items.index(use)
                            for _, items in ast.iter_fields(parent))):
                    return value
                return None
            if isinstance(current, (ast.FunctionDef, ast.AsyncFunctionDef, ast.Lambda)):
                if any(arg.arg == identifier for arg in ast.walk(current.args) if isinstance(arg, ast.arg)):
                    return None
            if current not in parents:
                return None
            current = scope(parents[current])

    def statement(node):
        while node in parents and not isinstance(node, ast.stmt):
            node = parents[node]
        return node

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

    def external_origin(value):
        # The slash fixes the complete authority before any dynamic suffix.
        if not value or not re.match(r"https?://[^/]+/", value) or re.search(r"[\\\x00-\x20]", value):
            return False
        parsed = urlsplit(value)
        return bool(parsed.hostname and not re.search(r"gateway", parsed.hostname, re.I)
                    and not parsed.username and not parsed.password)

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
        if len(node.args) != 2 or lock_path(node.args[0], node) not in HOST_TOOL_LOCKS:
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
        elif call in ("requests.get", "requests.post", "requests.put", "requests.patch", "requests.delete", "requests.request", "urllib.request.urlopen"):
            generic = call == "requests.request"
            method = string(node.args[0], node) if generic and node.args else call.rsplit(".", 1)[1].upper()
            if call == "urllib.request.urlopen":
                method = "GET"
            index = 1 if generic else 0
            url_node = node.args[index] if len(node.args) > index else None
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
                if call != "fcntl.flock" or not host_tool_lock(node):
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
        if isinstance(node, (ast.For, ast.AsyncFor)) and any(isinstance(part, ast.Name) and re.search(r"attempt|retry|poll", part.id, re.I) for part in ast.walk(node.target)):
            add(node, "custom-control")
    return findings


if __name__ == "__main__":
    primitives = json.loads(sys.argv[1]) if len(sys.argv) > 1 else []
    print(json.dumps(scan(sys.stdin.read(), primitives)))
