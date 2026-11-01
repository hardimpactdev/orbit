"""Prove bounded script controls from syntax, without running a phase or its queries."""
import ast
import ipaddress
import json
import re
import subprocess
from urllib.parse import urlsplit


def external_origin(value):
    """A fixed HTTPS authority, never an Orbit/local name or IP address."""
    if not value or not re.match(r'https://[^/]+/', value) or re.search(r'[\\\x00-\x20]', value):
        return False
    try:
        parsed = urlsplit(value)
        host = (parsed.hostname or '').lower().rstrip('.')
        port = parsed.port
    except ValueError:
        return False
    if parsed.username is not None or parsed.password is not None or port not in (None, 443):
        return False
    labels = host.split('.')
    if len(labels) < 2 or any(not re.fullmatch(r'[a-z0-9](?:[a-z0-9-]*[a-z0-9])?', label) for label in labels):
        return False
    if 'gateway' in host or labels[-1].isdigit() or any(label in ('orbit', 'gw', 'localhost', 'local', 'internal') for label in labels):
        return False
    try:
        ipaddress.ip_address(host)
        return False
    except ValueError:
        return True


def source_bindings(tree):
    """Resolve immutable lexical bindings for HTTP and host-tool proofs."""
    parents = {child: parent for parent in ast.walk(tree) for child in ast.iter_child_nodes(parent)}
    def scope(node):
        while node in parents and not isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef, ast.Lambda)):
            node = parents[node]
        return node

    def bound(identifier, at):
        current = scope(at)
        while current is not None:
            values = []
            for part in ast.walk(current):
                if isinstance(part, ast.Name) and part.id == identifier and isinstance(part.ctx, (ast.Store, ast.Del)) and scope(part) is current:
                    parent = parents.get(part)
                    if isinstance(parent, (ast.Assign, ast.AnnAssign, ast.AugAssign, ast.NamedExpr)) and (getattr(parent, "target", None) is part or part in getattr(parent, "targets", [])):
                        values.append((parent, parent.value if isinstance(parent, (ast.Assign, ast.AnnAssign)) else None))
                    elif isinstance(parent, ast.withitem) and parent.optional_vars is part:
                        values.append((parents[parent], parent.context_expr))
                    else:
                        # Loop, destructuring, deletion and other target forms are
                        # writes too. They cannot establish literal FD/URL provenance.
                        values.append((statement(part), None))
                if isinstance(part, ast.ExceptHandler) and part.name == identifier and scope(part) is current:
                    values.append((part, None))
                if isinstance(part, (ast.FunctionDef, ast.AsyncFunctionDef, ast.ClassDef)) and part.name == identifier and part in parents and scope(parents[part]) is current:
                    values.append((part, None))
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

    return parents, bound, statement


class PythonReads:
    """Prove straight-line GET -> checked response -> state, including wrappers."""
    def __init__(self, tree, bound, modules=None):
        self.bound = bound
        self.modules = modules or {}
        self.peers = {}
        self.checked = set()
        self.imports = {}
        self.functions = {}
        writes = {node.id for node in ast.walk(tree) if isinstance(node, ast.Name) and isinstance(node.ctx, (ast.Store, ast.Del))}
        writes.update(node.arg for node in ast.walk(tree) if isinstance(node, ast.arg))
        for node in tree.body:
            if isinstance(node, ast.Import):
                for item in node.names:
                    self.imports[item.asname or item.name.split('.')[0]] = item.name if item.asname else item.name.split('.')[0]
            elif isinstance(node, ast.ImportFrom) and node.level <= 1:
                for item in node.names:
                    self.imports[item.asname or item.name] = f'{node.module}.{item.name}'
            elif isinstance(node, ast.FunctionDef):
                self.functions.setdefault(node.name, []).append(node)
        import_writes = writes | {node.name for node in ast.walk(tree) if isinstance(node, (ast.FunctionDef, ast.ClassDef))}
        for node in ast.walk(tree):
            if isinstance(node, ast.Attribute) and isinstance(node.ctx, (ast.Store, ast.Del)):
                root = node.value
                while isinstance(root, ast.Attribute):
                    root = root.value
                if isinstance(root, ast.Name):
                    import_writes.add(root.id)
        self.imports = {key: value for key, value in self.imports.items() if key not in import_writes}
        self.functions = {key: values[0] for key, values in self.functions.items() if len(values) == 1 and key not in writes}

    def name(self, node):
        if isinstance(node, ast.Name):
            return self.imports.get(node.id, '')
        if isinstance(node, ast.Attribute):
            return f'{self.name(node.value)}.{node.attr}'
        return ''

    def value(self, node, values, stack=()):
        if node is None or node in stack:
            return None
        stack = (*stack, node)
        if isinstance(node, ast.Constant):
            return ('literal', node.value)
        if isinstance(node, ast.Name):
            if node.id in values:
                return values[node.id]
            return self.value(self.bound(node.id, node), values, stack)
        if isinstance(node, ast.BinOp) and isinstance(node.op, ast.Add):
            if any(isinstance(part, ast.Call) for part in ast.walk(node)):
                return None
            left, right = self.value(node.left, values, stack), self.value(node.right, values, stack)
            if left and left[0] in ('literal', 'prefix') and isinstance(left[1], str):
                if left[0] == 'literal' and right and right[0] == 'literal' and isinstance(right[1], str):
                    return ('literal', left[1] + right[1])
                return ('prefix', left[1])
        if isinstance(node, ast.IfExp):
            test = node.test
            if isinstance(test, ast.Compare) and len(test.ops) == 1 and isinstance(test.ops[0], (ast.Is, ast.IsNot)):
                left, right = self.value(test.left, values, stack), self.value(test.comparators[0], values, stack)
                if left and right and left[0] == right[0] == 'literal':
                    matches = left[1] is right[1]
                    if isinstance(test.ops[0], ast.IsNot):
                        matches = not matches
                    return self.value(node.body if matches else node.orelse, values, stack)
            observed = self.value(test, values, stack)
            result = self.value(node.body, values, stack)
            if observed and result and observed[0] == result[0] == 'state' and isinstance(node.orelse, ast.Dict) and not node.orelse.keys:
                return result
        if isinstance(node, ast.Subscript):
            base = self.value(node.value, values, stack)
            if base and base[0] == 'state' and not any(isinstance(part, ast.Call) for part in ast.walk(node.slice)):
                return base
        if isinstance(node, ast.Attribute) and node.attr in ('text', 'content'):
            base = self.value(node.value, values, stack)
            if base and base[0] == 'response' and base[1] in self.checked:
                return ('state', base[1])
        if not isinstance(node, ast.Call):
            return None
        called = self.name(node.func)
        # Arguments may select data, not run another hidden action.
        arguments = [*node.args, *(kw.value for kw in node.keywords if not (called == 'urllib.request.Request' and kw.arg == 'data' and self.value(kw.value, values, stack) == ('literal', None)))]
        if any(isinstance(part, ast.Call) for arg in arguments for part in ast.walk(arg)):
            return None
        if called == 'urllib.request.build_opener' and len(node.args) == 1 and not node.keywords:
            handler = self.value(node.args[0], values, stack)
            return ('opener', handler[1]) if handler and handler[0] == 'redirect-handler' else None
        if isinstance(node.func, ast.Attribute) and node.func.attr == 'open':
            opener = self.value(node.func.value, values, stack)
            if opener and opener[0] == 'opener':
                called = 'urllib.request.urlopen'
        if called in ('requests.get', 'httpx.get', 'urllib.request.Request', 'urllib.request.urlopen'):
            args = [self.value(arg, values, stack) for arg in node.args]
            kw = {item.arg: self.value(item.value, values, stack) for item in node.keywords}
            if None in kw:
                return None
            target = args[0] if args else kw.get('url')
            if called == 'urllib.request.urlopen' and target and target[0] == 'request':
                target = target[1]
            if not target or target[0] not in ('literal', 'prefix') or not isinstance(target[1], str) or not external_origin(target[1]):
                return None
            if called == 'urllib.request.Request':
                if len(args) > 1 or kw.get('method', ('literal', 'GET')) != ('literal', 'GET') or kw.get('data', ('literal', None)) != ('literal', None):
                    return None
                return ('request', target)
            if len(args) > 1 or any(key not in ('url', 'timeout', 'headers', 'params') for key in kw):
                return None
            if called == 'urllib.request.urlopen':
                self.checked.add(node)
            return ('response', node)
        if isinstance(node.func, ast.Attribute):
            base = self.value(node.func.value, values, stack)
            if base and (base[0] == 'state' and node.func.attr in ('get', 'strip') or
                         base[0] == 'response' and base[1] in self.checked and node.func.attr in ('json', 'read')):
                return ('state', base[1])
        if called == 'json.loads' and len(node.args) == 1 and not node.keywords:
            data = self.value(node.args[0], values, stack)
            return data if data and data[0] == 'state' else None
        owner = self
        function = self.functions.get(node.func.id) if isinstance(node.func, ast.Name) else None
        if function is None and '.' in called:
            module, method = called.rsplit('.', 1)
            if module in self.modules:
                if module not in self.peers:
                    peer_tree, peer_bound = self.modules[module]
                    self.peers[module] = PythonReads(peer_tree, peer_bound, self.modules)
                owner = self.peers[module]
                function = owner.functions.get(method)
        if function is not None:
            if function in stack or function.decorator_list or function.args.vararg or function.args.kwarg or function.args.kwonlyargs:
                return None
            parameters = [*function.args.posonlyargs, *function.args.args]
            if len(node.args) > len(parameters):
                return None
            local = {parameter.arg: self.value(arg, values, stack) for parameter, arg in zip(parameters, node.args)}
            for item in node.keywords:
                if item.arg not in [parameter.arg for parameter in parameters] or item.arg in local:
                    return None
                local[item.arg] = self.value(item.value, values, stack)
            defaults = dict(zip([parameter.arg for parameter in parameters][-len(function.args.defaults):], function.args.defaults)) if function.args.defaults else {}
            for parameter in parameters:
                if parameter.arg not in local:
                    if parameter.arg not in defaults:
                        return None
                    local[parameter.arg] = owner.value(defaults[parameter.arg], {}, stack)
            return owner.body(function.body, local, (*stack, function))
        return None

    def status(self, node, values):
        if not isinstance(node, ast.Call) or not isinstance(node.func, ast.Attribute) or node.func.attr != 'raise_for_status' or node.args or node.keywords:
            return False
        base = self.value(node.func.value, values)
        if not base or base[0] != 'response':
            return False
        self.checked.add(base[1])
        return True

    def body(self, statements, values, stack):
        for index, statement in enumerate(statements):
            if index == 0 and isinstance(statement, ast.Expr) and isinstance(statement.value, ast.Constant) and isinstance(statement.value.value, str):
                continue
            if isinstance(statement, ast.Assign) and len(statement.targets) == 1 and isinstance(statement.targets[0], ast.Name):
                value = self.value(statement.value, values, stack)
                if value is None:
                    return None
                values[statement.targets[0].id] = value
            elif isinstance(statement, ast.ClassDef):
                if statement.decorator_list or statement.keywords or len(statement.bases) != 1 or self.name(statement.bases[0]) != 'urllib.request.HTTPRedirectHandler' or len(statement.body) != 1:
                    return None
                method = statement.body[0]
                if not isinstance(method, ast.FunctionDef) or method.name != 'redirect_request' or method.decorator_list or method.args.defaults or len(method.body) != 1 or not isinstance(method.body[0], ast.Return) or not isinstance(method.body[0].value, ast.Constant) or method.body[0].value.value is not None:
                    return None
                values[statement.name] = ('redirect-handler', statement)
            elif isinstance(statement, ast.If):
                # A read-size refusal may raise. It cannot catch, write, or retry.
                if statement.orelse or len(statement.body) != 1 or not isinstance(statement.body[0], ast.Raise):
                    return None
                error = statement.body[0]
                if not isinstance(error.exc, ast.Call) or not isinstance(error.exc.func, ast.Name) or error.exc.func.id not in ('ValueError', 'RuntimeError') or error.cause is not None or not error.exc.args or any(not isinstance(arg, ast.Constant) or not isinstance(arg.value, str) or not arg.value for arg in error.exc.args):
                    return None
                calls = [part for part in ast.walk(statement.test) if isinstance(part, ast.Call)]
                if any(not isinstance(call.func, ast.Name) or call.func.id != 'len' or len(call.args) != 1 or call.keywords or not (value := self.value(call.args[0], values, stack)) or value[0] != 'state' for call in calls):
                    return None
            elif isinstance(statement, ast.Expr) and self.status(statement.value, values):
                continue
            elif isinstance(statement, ast.With) and len(statement.items) == 1 and index == len(statements) - 1:
                item = statement.items[0]
                value = self.value(item.context_expr, values, stack)
                if not value or value[0] != 'response' or value[1] not in self.checked or not isinstance(item.optional_vars, ast.Name):
                    return None
                values[item.optional_vars.id] = value
                return self.body(statement.body, values, stack)
            elif isinstance(statement, ast.Return) and index == len(statements) - 1:
                value = self.value(statement.value, values, stack)
                return value if value and value[0] in ('state', 'request') else None
            else:
                # In particular: no try/except, action, loop or conditional status check.
                return None
        return None


def python_controls(tree, seconds, bound, context=None):
    """Only successful external GET observations may select whether to wait again."""
    if not seconds:
        return set()
    modules = {}
    for path, source in (context or {}).items():
        if not path.endswith('.py'):
            continue
        try:
            module = ast.parse(source)
        except SyntaxError:
            continue
        modules[path.rsplit('/', 1)[-1][:-3]] = (module, source_bindings(module)[1])
    allowed = set()
    all_sleeps = [node for node in ast.walk(tree) if isinstance(node, ast.Call) and ast.unparse(node.func) == 'time.sleep']
    if len(all_sleeps) == 1 and not any(isinstance(node, (ast.While, ast.For, ast.AsyncFor)) for node in ast.walk(tree)):
        sleep = all_sleeps[0]
        if len(sleep.args) == 1 and not sleep.keywords and isinstance(sleep.args[0], ast.Constant) and type(sleep.args[0].value) in (int, float) and 0 < sleep.args[0].value < seconds:
            allowed.add(sleep)
    parents = {child: parent for parent in ast.walk(tree) for child in ast.iter_child_nodes(parent)}
    for loop in ast.walk(tree):
        if not isinstance(loop, ast.While) or not isinstance(loop.test, ast.Constant) or loop.test.value is not True:
            continue
        ancestor = parents.get(loop)
        guarded = False
        while ancestor is not None:
            guarded |= isinstance(ancestor, (ast.Try, ast.TryStar, ast.With, ast.AsyncWith))
            ancestor = parents.get(ancestor)
        if guarded:
            continue
        nodes = list(ast.walk(loop))
        if any(isinstance(node, ast.While) and node is not loop for node in nodes):
            continue
        # Only state capture, state branches and the fixed wait belong in a poll.
        if any(not isinstance(node, (ast.Assign, ast.If, ast.Expr)) for node in loop.body):
            continue
        if any(isinstance(node, (ast.Try, ast.TryStar, ast.For, ast.AsyncFor)) for node in nodes):
            continue
        sleeps = [node for node in nodes if isinstance(node, ast.Call) and ast.unparse(node.func) == 'time.sleep']
        if len(sleeps) != 1 or len(sleeps[0].args) != 1 or sleeps[0].keywords:
            continue
        interval = sleeps[0].args[0]
        if not isinstance(interval, ast.Constant) or type(interval.value) not in (int, float) or not 0 < interval.value < seconds:
            continue
        if any(isinstance(node, (ast.Continue, ast.NamedExpr)) for node in nodes):
            continue
        # Keep the sleep unconditional and last. Track the current observation,
        # not a name which once held state but has since been overwritten.
        if loop.orelse or not isinstance(loop.body[-1], ast.Expr) or loop.body[-1].value is not sleeps[0]:
            continue
        proof = PythonReads(tree, bound, modules)
        values = {}
        good = bad = False
        valid = True
        for statement in loop.body[:-1]:
            if isinstance(statement, ast.Assign):
                value = proof.value(statement.value, values)
                if not all(isinstance(target, ast.Name) for target in statement.targets) or not value or value[0] not in ('response', 'state'):
                    valid = False
                    break
                values.update((target.id, value) for target in statement.targets)
            elif isinstance(statement, ast.Expr) and proof.status(statement.value, values):
                continue
            elif isinstance(statement, ast.If):
                if (statement.orelse or not isinstance(statement.test, ast.Compare) or
                    any(isinstance(part, ast.Call) for part in ast.walk(statement.test)) or
                    not any(isinstance(part, ast.Name) and values.get(part.id, ('',))[0] == 'state' for part in ast.walk(statement.test)) or
                    len(statement.body) != 1):
                    valid = False
                    break
                terminal = statement.body[0]
                if any(isinstance(part, ast.Call) for part in ast.walk(terminal) if part is not getattr(terminal, 'exc', None)):
                    valid = False
                    break
                good |= isinstance(terminal, (ast.Return, ast.Break))
                readable = (isinstance(terminal, ast.Raise) and isinstance(terminal.exc, ast.Call) and
                            isinstance(terminal.exc.func, ast.Name) and terminal.exc.func.id in ('RuntimeError', 'ValueError', 'SystemExit') and any(
                    isinstance(arg, ast.Constant) and isinstance(arg.value, str) and arg.value.strip()
                    for arg in terminal.exc.args
                ))
                bad |= readable
                if not isinstance(terminal, (ast.Return, ast.Break)) and not readable:
                    valid = False
                    break
            else:
                valid = False
                break
        if valid and good and bad:
            allowed.update((loop, sleeps[0]))
    return allowed


def shell_controls(source, outer_seconds, errexit=False):
    """Require a parsed coreutils timeout around each sleep or external-state poll."""
    result = subprocess.run(['shfmt', '--to-json'], input=source, text=True, capture_output=True, check=False)
    if result.returncode:
        return False
    tree = json.loads(result.stdout)
    # Errexit must precede the poll and must never be disabled. Do not treat
    # a late `set -e` as protection for an earlier failed observation.
    statements = tree.get('Stmts', [])
    first = statements[0].get('Cmd', {}) if statements else {}
    words = [word.get('Parts', [{}])[0].get('Value') for word in first.get('Args', [])]
    errexit = errexit or words in (['set', '-e'], ['set', '-eu'], ['set', '-euo', 'pipefail'])

    def literal(word):
        parts = word.get('Parts', [])
        if not parts:
            return None
        values = []
        for part in parts:
            if part.get('Type') in ('Lit', 'SglQuoted'):
                values.append(part.get('Value', ''))
            elif part.get('Type') == 'DblQuoted':
                inner = literal(part)
                if inner is None:
                    return None
                values.append(inner)
            else:
                return None
        return ''.join(values)

    def descendants(node):
        if isinstance(node, dict):
            yield node
            for key, value in node.items():
                if key not in ('Comments', 'Pos', 'End'):
                    yield from descendants(value)
        elif isinstance(node, list):
            for value in node:
                yield from descendants(value)

    def check(node, seconds, poll=False):
        if isinstance(node, list):
            return all(check(value, seconds, poll) for value in node)
        if not isinstance(node, dict):
            return True
        if node.get('Negated') or node.get('Background'):
            return False
        kind = node.get('Type')
        if kind in ('ForClause', 'BinaryCmd', 'IfClause', 'FuncDecl'):
            # Do not infer errexit through shell contexts which disable it.
            return False
        if kind == 'WhileClause':
            # A state poll, not `until command` / `while ! command` exit-status retries.
            cond = node.get('Cond', [])
            condition = cond[0].get('Cmd', {}) if len(cond) == 1 else {}
            args = [literal(word) for word in condition.get('Args', [])]
            body = list(descendants(node.get('Do', [])))
            cases = [value for value in body if value.get('Type') == 'CaseClause']
            calls = [[literal(word) for word in value.get('Args', [])] for value in body if value.get('Type') == 'CallExpr']
            sleeps = [argv for argv in calls if argv and argv[0] and argv[0].split('/')[-1] == 'sleep']
            if not seconds or not errexit or node.get('Until') or args not in (['true'], [':']) or len(sleeps) != 1 or not cases:
                return False
            # A simple assignment -> state case -> sleep sequence. No action retry,
            # swallowed failure, overwritten state, or command hidden in a selector.
            statements = node.get('Do', [])
            if len(statements) != 3 or any(stmt.get('Negated') or stmt.get('Background') for stmt in statements):
                return False
            capture, selector, wait = [stmt.get('Cmd', {}) for stmt in statements]
            assigns = capture.get('Assigns', [])
            if capture.get('Args') or len(assigns) != 1 or selector.get('Type') != 'CaseClause':
                return False
            query_parts = assigns[0].get('Value', {}).get('Parts', [])
            if len(query_parts) != 1 or query_parts[0].get('Type') != 'CmdSubst':
                return False
            query = query_parts[0].get('Stmts', [])
            if len(query) != 1 or query[0].get('Cmd', {}).get('Type') != 'CallExpr':
                return False
            request = [literal(word) for word in query[0]['Cmd'].get('Args', [])]
            flags = {'--fail', '--fail-with-body', '--silent', '--show-error', '-f', '-s', '-S', '-fsS', '-fs', '-sf', '-sS'}
            if (not request or request[0] not in ('curl', '/usr/bin/curl') or
                not any(arg in ('--fail', '--fail-with-body', '-f', '-fsS', '-fs', '-sf') for arg in request) or
                any(arg is None or arg not in flags and not arg.startswith('https://') for arg in request[1:])):
                return False
            # Static external HTTPS origin; never poll Orbit or Gateway state.
            urls = [arg for arg in request if arg.startswith('https://')]
            if len(urls) != 1 or not external_origin(urls[0]):
                return False
            params = [part for part in descendants(selector.get('Word', {})) if part.get('Type') == 'ParamExp']
            if len(params) != 1 or params[0].get('Param', {}).get('Value') != assigns[0].get('Name', {}).get('Value'):
                return False
            good = bad = False
            for arm in selector.get('Items', []):
                arm_calls = [stmt.get('Cmd', {}) for stmt in arm.get('Stmts', [])]
                if any(call.get('Type') != 'CallExpr' for call in arm_calls):
                    return False
                arm_argv = [[literal(word) for word in call.get('Args', [])] for call in arm_calls]
                if any(not argv or argv[0] not in ('exit', 'break', 'echo', 'printf') for argv in arm_argv):
                    return False
                good |= arm_argv in ([['exit', '0']], [['break']])
                bad |= (len(arm_argv) == 2 and arm_argv[0][0] in ('echo', 'printf') and
                        any(arg and arg.strip() for arg in arm_argv[0][1:]) and arm_argv[1] in (['exit', '1'], ['exit', '2']))
            if not good or not bad or wait.get('Type') != 'CallExpr':
                return False
            return check(node.get('Do', []), seconds, True)
        if kind == 'CallExpr':
            argv = [literal(word) for word in node.get('Args', [])]
            if argv and argv[0] == 'exec':
                argv = argv[1:]
            binary = (argv[0] or '').split('/')[-1] if argv else ''
            if binary == 'timeout':
                tail = argv[1:]
                if tail[:1] == ['--verbose']:
                    tail = tail[1:]
                if len(tail) < 3 or tail[0] is None or not tail[0].isdigit() or int(tail[0]) <= 0:
                    return False
                bound = min(seconds, int(tail[0])) if seconds else int(tail[0])
                if (tail[1] or '').split('/')[-1] == 'sleep':
                    return len(tail) == 3 and tail[2] is not None and tail[2].replace('.', '', 1).isdigit() and 0 < float(tail[2]) < bound
                if (tail[1] or '').split('/')[-1] in ('bash', 'sh') and '-c' in tail:
                    index = tail.index('-c')
                    if index + 2 != len(tail) or tail[index + 1] is None:
                        return False
                    flags = tail[2:index]
                    # Long options contain letters, not bundled short flags.
                    if any(arg not in ('-e', '-u', '-eu', '-ue', '--noprofile', '--norc') for arg in flags):
                        return False
                    return shell_controls(tail[index + 1], bound, any(arg in ('-e', '-eu', '-ue') for arg in flags))
                return False
            if binary == 'sleep':
                return bool((poll or single_wait) and seconds and len(argv) == 2 and argv[1] and argv[1].replace('.', '', 1).isdigit() and 0 < float(argv[1]) < seconds)
            if binary == 'set' and argv not in (['set', '-e'], ['set', '-eu'], ['set', '-euo', 'pipefail']):
                return False
            if binary in ('flock', 'lockfile', 'eval', 'command', 'builtin', 'source', '.') or binary.startswith(('python', 'node', 'perl', 'ruby')) or binary in ('bash', 'sh'):
                return False
            if argv and argv[0] is None:
                return False
        return all(check(value, seconds, poll) for key, value in node.items() if key not in ('Comments', 'Pos', 'End'))

    single_wait = sum(1 for node in descendants(tree) if node.get('Type') == 'CallExpr' and
                      any(literal(word) in ('sleep', '/usr/bin/sleep') for word in node.get('Args', [])[:1])) == 1
    return check(tree, outer_seconds)
