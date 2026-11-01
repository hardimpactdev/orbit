"""Prove bounded script controls from syntax, without running a phase or its queries."""
import ast
import json
import subprocess


def python_controls(tree, seconds):
    """Only successful external GET observations may select whether to wait again."""
    if not seconds:
        return set()
    allowed = set()
    all_sleeps = [node for node in ast.walk(tree) if isinstance(node, ast.Call) and ast.unparse(node.func) == 'time.sleep']
    if len(all_sleeps) == 1 and not any(isinstance(node, (ast.While, ast.For, ast.AsyncFor)) for node in ast.walk(tree)):
        sleep = all_sleeps[0]
        if len(sleep.args) == 1 and not sleep.keywords and isinstance(sleep.args[0], ast.Constant) and type(sleep.args[0].value) in (int, float) and 0 < sleep.args[0].value < seconds:
            allowed.add(sleep)
    for loop in ast.walk(tree):
        if not isinstance(loop, ast.While) or not isinstance(loop.test, ast.Constant) or loop.test.value is not True:
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
        reads = [node for node in nodes if isinstance(node, ast.Call) and
                 (ast.unparse(node.func) == 'api' and node.args and isinstance(node.args[0], ast.Constant) and node.args[0].value == 'GET' or
                  ast.unparse(node.func) in ('requests.get', 'httpx.get'))]
        if not reads:
            continue
        if any(isinstance(node, ast.Call) and node not in reads and node not in sleeps and
               ast.unparse(node.func) not in ('RuntimeError', 'ValueError', 'SystemExit') and
               not (isinstance(node.func, ast.Attribute) and node.func.attr == 'get') for node in nodes):
            continue
        if any(isinstance(node, (ast.Continue, ast.NamedExpr)) for node in nodes):
            continue
        # Mutation requests and process exit-status retries cannot masquerade as state observations.
        if any(isinstance(node, ast.Call) and
               (ast.unparse(node.func).startswith(('subprocess.', 'os.system')) or
                ast.unparse(node.func) in ('api', 'requests.post', 'requests.put', 'requests.delete') and node not in reads)
               for node in nodes):
            continue
        # Keep the sleep unconditional and last. Track the current observation,
        # not a name which once held state but has since been overwritten.
        if loop.orelse or not isinstance(loop.body[-1], ast.Expr) or loop.body[-1].value is not sleeps[0]:
            continue
        observed = set()
        good = bad = False
        valid = True
        for statement in loop.body[:-1]:
            if isinstance(statement, ast.Assign):
                if not all(isinstance(target, ast.Name) for target in statement.targets) or not any(
                    part in reads or isinstance(part, ast.Name) and part.id in observed for part in ast.walk(statement.value)
                ):
                    valid = False
                    break
                observed.update(target.id for target in statement.targets)
            elif isinstance(statement, ast.If):
                if (statement.orelse or not isinstance(statement.test, ast.Compare) or
                    not any(isinstance(part, ast.Name) and part.id in observed for part in ast.walk(statement.test)) or
                    len(statement.body) != 1):
                    valid = False
                    break
                terminal = statement.body[0]
                good |= isinstance(terminal, (ast.Return, ast.Break))
                readable = isinstance(terminal, ast.Raise) and terminal.exc is not None and any(
                    isinstance(arg, ast.Constant) and isinstance(arg.value, str) and arg.value.strip()
                    for arg in ast.walk(terminal.exc)
                )
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
            if len(urls) != 1 or any(word in urls[0].lower() for word in ('gateway', '.orbit', 'localhost', '127.0.0.1')):
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
                    return shell_controls(tail[index + 1], bound, any(arg and arg.startswith('-') and 'e' in arg for arg in tail[2:index]))
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
