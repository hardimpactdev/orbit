"""Static Python effects and private-value flow. Reads source on stdin, never executes it."""
import ast
import json
import re
import shlex
import sys

SECRET = re.compile(r"secret|password|passwd|credential|private.?key|api.?key|token|identity", re.I)
SAFE_REF = re.compile(r"(?:key|id|ref|name|hash|digest|public|field)s?$", re.I)


def scan(source, gateway_primitives=(), agent_inventory=None, depth=0):
    findings = []
    agent_inventory = agent_inventory or {}
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
            values = bindings.get(node.id, [])
            if len(values) == 1:
                return string(values[0], at, seen | {node.id})
        if isinstance(node, ast.BinOp) and isinstance(node.op, ast.Add):
            left, right = string(node.left, at, seen), string(node.right, at, seen)
            if left is not None and right is not None:
                return left + right
        return None

    def allowed(method, url):
        if method != "POST" or not url or re.search(r"[?#\\]", url):
            return False
        path = re.sub(r"^https?://[^/]+", "", url)
        return any(re.fullmatch(re.escape(route).replace(r"\{operation_id\}", r"(?:[1-9][0-9]*|\{operation_id\})"), path) for route in gateway_primitives)

    def gateway_request(node, call):
        method = url = None
        if call == "urllib.request.Request":
            url = string(node.args[0], node) if node.args else None
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
            url = string(node.args[index], node) if len(node.args) > index else None
        else:
            return
        external = url and re.match(r"https?://", url) and not re.search(r"gateway|/api/v1/", url, re.I)
        if not external and re.search(r"gateway|/api/v1/", source, re.I) and not allowed(method, url):
            findings.append({"rule": "gateway-http", "count": 1, "line": node.lineno, "evidence": [f"{method or '<unknown>'} {url or '<unknown>'}"]})

    def argument(node, at, seen=frozenset()):
        if isinstance(node, ast.Name) and node.id not in seen:
            values = bindings.get(node.id, [])
            if len(values) == 1:
                return argument(values[0], at, seen | {node.id})
        if isinstance(node, (ast.List, ast.Tuple)):
            result = []
            for item in node.elts:
                value = argument(item.value if isinstance(item, ast.Starred) else item, at, seen)
                result.extend(value if isinstance(item, ast.Starred) and isinstance(value, list) else [value if isinstance(value, str) else "<dynamic>"])
            return result
        if isinstance(node, ast.BinOp) and isinstance(node.op, ast.Add):
            left, right = argument(node.left, at, seen), argument(node.right, at, seen)
            if isinstance(left, list) and isinstance(right, list):
                return left + right
            if isinstance(left, (str, type(None))) and isinstance(right, (str, type(None))):
                return (left or "<dynamic>") + (right or "<dynamic>")
        if isinstance(node, ast.Call) and name(node.func) == "shlex.quote" and node.args:
            value = argument(node.args[0], at, seen)
            if isinstance(value, str):
                return shlex.quote(value)
        return string(node, at, seen)

    def agent_command(argv, at, level=0):
        if level > 8 or not argv:
            return
        if isinstance(argv, str):
            try:
                lexer = shlex.shlex(argv, posix=True, punctuation_chars=";&|()")
                lexer.whitespace_split = True
                current = []
                for token in lexer:
                    if token and all(ch in ";&|()" for ch in token):
                        agent_command(current, at, level + 1)
                        current = []
                    else:
                        current.append(token)
                agent_command(current, at, level + 1)
            except ValueError:
                if "orbit-agent" in argv:
                    findings.append({"rule": "agent-dynamic", "count": 1, "line": at.lineno})
            return
        while argv and (re.match(r"[A-Za-z_]\w*=", argv[0]) or argv[0] in ("exec", "command", "then", "do", "if", "!")):
            argv = argv[1:]
        if not argv:
            return
        binary = argv[0].rsplit("/", 1)[-1]
        if binary in ("sudo", "env"):
            i = 1
            while i < len(argv) and (argv[i].startswith("-") or re.match(r"[A-Za-z_]\w*=", argv[i])):
                option = argv[i]
                i += 2 if option in ("-u", "-g", "-h", "-p", "-C", "--user", "--group", "--chdir") else 1
            agent_command(argv[i:], at, level + 1)
        elif binary in ("sh", "bash", "dash", "zsh"):
            for i, item in enumerate(argv[:-1]):
                if re.fullmatch(r"-[a-z]*c[a-z]*", item):
                    agent_command(argv[i + 1], at, level + 1)
                    break
        elif binary == "ssh":
            i = 1
            while i < len(argv) and argv[i].startswith("-"):
                option = argv[i]
                i += 2 if option in ("-i", "-p", "-o", "-F", "-l", "-J", "-S", "-W") else 1
            if i < len(argv) and argv[i] == "--":
                i += 1
            agent_command(" ".join(argv[i + 1:]), at, level + 1)
        elif re.fullmatch(r"python[0-9.]*", binary) and "-c" in argv and depth < 4:
            i = argv.index("-c")
            if len(argv) > i + 1 and argv[i + 1] != "<dynamic>":
                for finding in scan(argv[i + 1], gateway_primitives, agent_inventory, depth + 1):
                    if finding["rule"] in ("agent-action", "agent-dynamic"):
                        findings.append({**finding, "line": at.lineno})
        elif binary == "orbit-agent":
            if len(argv) > 1 and argv[1] in ("--version", "--help", "-V", "-h"):
                return
            target = argv[2] if len(argv) > 2 and argv[1] == "command" else None
            if target and agent_inventory.get(target) == "observer":
                return
            findings.append({"rule": "agent-action" if target and agent_inventory.get(target) == "action" else "agent-dynamic", "count": 1, "line": at.lineno, "evidence": [target] if target and agent_inventory.get(target) == "action" else []})

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
                add(node, "custom-control")
            if call in ("subprocess.run", "subprocess.Popen", "subprocess.call", "subprocess.check_call", "subprocess.check_output", "os.system", "os.execv", "os.execve"):
                argv = node.args[:1] + [k.value for k in node.keywords if k.arg in ("args", "executable")]
                for arg in argv:
                    resolved = argument(arg, node)
                    if isinstance(resolved, (str, list)):
                        agent_command(resolved, node)
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
    primitives = json.loads(sys.argv[1]) if len(sys.argv) > 1 else []
    inventory = json.loads(sys.argv[2]) if len(sys.argv) > 2 else {}
    print(json.dumps(scan(sys.stdin.read(), primitives, inventory)))
