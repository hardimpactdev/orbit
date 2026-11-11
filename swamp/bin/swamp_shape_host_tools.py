"""Structural proofs for canonical host-tool transactions."""

import ast

USER_PATTERN = "[a-z_][a-z0-9_-]{0,31}"


def php_runtime_transaction(tree, sources, descriptor, acquire):
    if not sources or not isinstance(descriptor, ast.Call) or not descriptor.args:
        return False
    parents = {child: parent for parent in ast.walk(tree) for child in ast.iter_child_nodes(parent)}
    function = acquire
    while function in parents and not isinstance(function, ast.FunctionDef):
        function = parents[function]
    if not isinstance(function, ast.FunctionDef) or len(function.args.args) != 2:
        return False
    host, user = (arg.arg for arg in function.args.args)
    # Recognize the entire acquisition/custody/release sequence, not a nearby
    # check that a branch could skip, swallow, or follow by descriptor replacement.
    contract = ast.parse(f"""
@contextlib.contextmanager
def {function.name}({host}, {user}):
    check(directory({host}.locks), "lock directory exists")
    fd = os.open({host}.locks / ("orbit-php-" + {user} + ".lock"),
                 os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        m = os.fstat(fd)
        check(stat.S_ISREG(m.st_mode) and m.st_uid == {host}.root_uid
              and m.st_gid == {host}.root_gid
              and stat.S_IMODE(m.st_mode) == 0o600, "trusted runtime lock")
        fcntl.flock(fd, fcntl.LOCK_EX)
        yield
    finally:
        os.close(fd)
""").body[0]
    if ast.dump(function) != ast.dump(contract):
        return False
    expected = ast.parse(f"{host}.locks / ('orbit-php-' + {user} + '.lock')", mode="eval").body
    if ast.dump(descriptor.args[0]) != ast.dump(expected):
        return False
    if (
        len(descriptor.args) != 3
        or not isinstance(descriptor.args[2], ast.Constant)
        or descriptor.args[2].value != 0o600
    ):
        return False
    flags = {
        ast.unparse(node)
        for node in ast.walk(descriptor.args[1])
        if isinstance(node, ast.Attribute)
    }
    if flags != {"os.O_RDWR", "os.O_CREAT", "os.O_NOFOLLOW"}:
        return False
    modules = {}
    try:
        for filename, source in sources.items():
            modules[filename.rsplit("/", 1)[-1].removesuffix(".py")] = ast.parse(source)
    except SyntaxError:
        return False
    own = next(
        (name for name, root in modules.items() if ast.dump(root) == ast.dump(tree)),
        None,
    )
    if own is None:
        return False
    modules[own] = tree
    standard = {"os", "fcntl", "stat", "re", "pathlib", "contextlib"}
    actual_imports = {
        alias.asname or alias.name: alias.name
        for node in tree.body
        if isinstance(node, ast.Import)
        for alias in node.names
    }
    if any(actual_imports.get(name) != name for name in standard):
        return False
    # Duplicate imports/definitions can replace the apparent constructor.
    for root in modules.values():
        declared = [
            node.name for node in root.body if isinstance(node, (ast.FunctionDef, ast.ClassDef))
        ]
        declared += [
            alias.asname or alias.name.split(".")[0]
            for node in root.body
            if isinstance(node, (ast.Import, ast.ImportFrom))
            for alias in node.names
        ]
        if len(declared) != len(set(declared)):
            return False
    definitions = {
        name: {
            node.name: node
            for node in root.body
            if isinstance(node, (ast.FunctionDef, ast.ClassDef))
        }
        for name, root in modules.items()
    }
    imports = {
        name: {
            alias.asname or alias.name: (node.module, alias.name)
            for node in root.body
            if isinstance(node, ast.ImportFrom)
            for alias in node.names
        }
        for name, root in modules.items()
    }

    def resolve(module, identifier, seen=frozenset()):
        key = (module, identifier)
        if key in seen:
            return None
        if identifier in definitions.get(module, {}):
            return module, definitions[module][identifier]
        target = imports.get(module, {}).get(identifier)
        return resolve(*target, seen | {key}) if target else None

    def pairs(target, value):
        if (
            isinstance(target, (ast.Tuple, ast.List))
            and isinstance(value, (ast.Tuple, ast.List))
            and len(target.elts) == len(value.elts)
        ):
            return [
                pair for left, right in zip(target.elts, value.elts) for pair in pairs(left, right)
            ]
        return [(target, value)]

    def assignments(root):
        return [
            (target, value, node)
            for node in ast.walk(root)
            if isinstance(node, ast.Assign)
            for item in node.targets
            for target, value in pairs(item, node.value)
        ]

    def constructor_fields(cls):
        init = next(
            (
                node
                for node in cls.body
                if isinstance(node, ast.FunctionDef) and node.name == "__init__"
            ),
            None,
        )
        if (
            not init
            or cls.decorator_list
            or cls.bases
            or cls.keywords
            or any(
                isinstance(node, ast.FunctionDef)
                and node.name
                in (
                    "__new__",
                    "__getattribute__",
                    "__setattr__",
                    "locks",
                    "root_uid",
                    "root_gid",
                    "user",
                )
                for node in cls.body
            )
        ):
            return None, {}
        if any(isinstance(node, (ast.Return, ast.Yield, ast.YieldFrom)) for node in ast.walk(init)):
            return None, {}
        fields = {
            target.attr: (value, node)
            for target, value, node in assignments(init)
            if isinstance(target, ast.Attribute)
            and isinstance(target.value, ast.Name)
            and target.value.id == "self"
        }
        if init.decorator_list or any(
            statement not in init.body
            for key, (_, statement) in fields.items()
            if key in ("locks", "user", "root_uid", "root_gid")
        ):
            return None, {}
        return init, fields

    host_class = runtime_class = None
    protected = set()
    for cls in (node for node in tree.body if isinstance(node, ast.ClassDef)):
        init, fields = constructor_fields(cls)
        locks = fields.get("locks", (None, None))[0]
        if (
            isinstance(locks, ast.Call)
            and len(locks.args) == 1
            and isinstance(locks.args[0], ast.Constant)
            and locks.args[0].value == "/run/lock"
        ):
            path_type = ast.unparse(locks.func)
            aliases = [
                (target, value)
                for target, value, _ in assignments(tree)
                if isinstance(target, ast.Name) and target.id == path_type
            ]
            immutable_path = (
                path_type == "pathlib.Path"
                or len(aliases) == 1
                and ast.unparse(aliases[0][1]) == "pathlib.Path"
                and sum(
                    isinstance(node, ast.Name)
                    and node.id == path_type
                    and isinstance(node.ctx, (ast.Store, ast.Del))
                    for node in ast.walk(tree)
                )
                == 1
            )
            if immutable_path and all(arg.arg != path_type for arg in init.args.args):
                if all(
                    isinstance(fields.get(key, (None,))[0], ast.Constant)
                    and fields[key][0].value == 0
                    for key in ("root_uid", "root_gid")
                ):
                    host_class = cls
                    protected.update(
                        (id(fields[key][1]), key) for key in ("locks", "root_uid", "root_gid")
                    )
        if init and "user" in fields:
            guard = next(
                (
                    node
                    for node in init.body
                    if isinstance(node, ast.Expr)
                    and isinstance(node.value, ast.Call)
                    and isinstance(node.value.func, ast.Name)
                    and node.value.func.id == "check"
                    and node.value.args
                    and isinstance(node.value.args[0], ast.Call)
                    and ast.unparse(node.value.args[0].func) == "re.fullmatch"
                ),
                None,
            )
            if guard:
                args = guard.value.args[0].args
                if (
                    len(args) == 2
                    and isinstance(args[0], ast.Constant)
                    and args[0].value in (USER_PATTERN, "^" + USER_PATTERN + "$")
                    and ast.unparse(args[1]) == "self.user"
                    and fields["user"][1].lineno < guard.lineno
                ):
                    runtime_class = cls
                    protected.add((id(fields["user"][1]), "user"))
    check = definitions[own].get("check")
    if (
        not host_class
        or not runtime_class
        or not isinstance(check, ast.FunctionDef)
        or check.decorator_list
        or len(check.args.args) != 2
        or len(check.body) != 1
        or not isinstance(check.body[0], ast.If)
    ):
        return False
    guard = check.body[0]
    if (
        not isinstance(guard.test, ast.UnaryOp)
        or not isinstance(guard.test.op, ast.Not)
        or not isinstance(guard.test.operand, ast.Name)
        or guard.test.operand.id != check.args.args[0].arg
        or not guard.body
        or not isinstance(guard.body[0], ast.Raise)
    ):
        return False
    for module, root in modules.items():
        reserved = (
            {"P"}
            | set(definitions[module])
            | set(imports[module])
            | {
                alias.asname or alias.name.split(".")[0]
                for node in ast.walk(root)
                if isinstance(node, (ast.Import, ast.ImportFrom))
                for alias in node.names
            }
        )
        if any(
            isinstance(node, ast.Name)
            and isinstance(node.ctx, (ast.Store, ast.Del))
            and node.id in reserved - ({"P"} if module == own else set())
            for node in ast.walk(root)
        ):
            return False
        local_parents = {
            child: node for node in ast.walk(root) for child in ast.iter_child_nodes(node)
        }
        for node in ast.walk(root):
            if isinstance(node, ast.Name) and isinstance(node.ctx, ast.Load):
                symbol = resolve(module, node.id)
                parent = local_parents.get(node)
                if node.id in standard and not isinstance(parent, ast.Attribute):
                    return False
                if symbol in ((own, host_class), (own, runtime_class)) and not (
                    isinstance(parent, ast.Call) and parent.func is node
                ):
                    return False
            if isinstance(node, ast.Attribute) and isinstance(node.ctx, (ast.Store, ast.Del)):
                base = node.value
                while isinstance(base, ast.Attribute):
                    base = base.value
                if isinstance(base, ast.Name) and base.id in reserved:
                    return False
                if node.attr in ("locks", "user", "root_uid", "root_gid"):
                    statement = node
                    while statement in local_parents and not isinstance(statement, ast.stmt):
                        statement = local_parents[statement]
                    if module != own or (id(statement), node.attr) not in protected:
                        return False
        for target, _, statement in assignments(root):
            if (
                isinstance(target, ast.Attribute)
                and target.attr in ("locks", "user", "root_uid", "root_gid")
                and (module != own or (id(statement), target.attr) not in protected)
            ):
                return False
        for node in ast.walk(root):
            if (
                isinstance(node, ast.Call)
                and isinstance(node.func, ast.Name)
                and node.func.id
                in ("setattr", "delattr", "globals", "locals", "vars", "eval", "exec")
            ):
                return False
            if isinstance(node, ast.Attribute) and node.attr in (
                "__dict__",
                "__setattr__",
                "__delattr__",
            ):
                return False
    factories = {}
    for module, entries in definitions.items():
        for factory in (node for node in entries.values() if isinstance(node, ast.FunctionDef)):
            if factory.decorator_list:
                continue
            returns = [node for node in ast.walk(factory) if isinstance(node, ast.Return)]
            if (
                len(returns) != 1
                or returns[0] not in factory.body
                or not isinstance(returns[0].value, ast.Tuple)
                or len(returns[0].value.elts) < 2
            ):
                continue
            names = returns[0].value.elts[:2]
            if not all(isinstance(node, ast.Name) for node in names):
                continue
            bindings = {
                target.id: value
                for target, value, statement in assignments(factory)
                if isinstance(target, ast.Name)
                and statement in factory.body
                and statement.lineno < returns[0].lineno
            }
            if any(
                sum(
                    isinstance(part, ast.Name)
                    and isinstance(part.ctx, (ast.Store, ast.Del))
                    and part.id == name.id
                    for part in ast.walk(factory)
                )
                != 1
                for name in names
            ):
                continue
            host_value, runtime_value = (bindings.get(node.id) for node in names)
            if (
                isinstance(host_value, ast.Call)
                and isinstance(runtime_value, ast.Call)
                and isinstance(host_value.func, ast.Name)
                and isinstance(runtime_value.func, ast.Name)
                and resolve(module, host_value.func.id) == (own, host_class)
                and resolve(module, runtime_value.func.id) == (own, runtime_class)
                and runtime_value.args
                and ast.dump(runtime_value.args[0]) == ast.dump(names[0])
            ):
                factories[(module, factory.name)] = factory
    executors = set()
    for module, entries in definitions.items():
        for executor in (
            node
            for node in entries.values()
            if isinstance(node, ast.FunctionDef) and len(node.args.args) == 1
        ):
            if executor.decorator_list:
                continue
            parameter = executor.args.args[0].arg
            uses = [
                node
                for node in ast.walk(executor)
                if isinstance(node, ast.Name) and node.id == parameter
            ]
            for call in (
                node
                for node in ast.walk(executor)
                if isinstance(node, ast.Call)
                and isinstance(node.func, ast.Name)
                and node.func.id == parameter
            ):
                if (
                    len(uses) == 1
                    and len(call.args) == 1
                    and isinstance(call.args[0], ast.Starred)
                    and isinstance(call.args[0].value, ast.Call)
                    and isinstance(call.args[0].value.func, ast.Name)
                ):
                    factory = resolve(module, call.args[0].value.func.id)
                    if factory and (factory[0], factory[1].name) in factories:
                        executors.add((module, executor.name))
    actions = set()
    for module, root in modules.items():
        for call in (
            node
            for node in ast.walk(root)
            if isinstance(node, ast.Call) and isinstance(node.func, ast.Name)
        ):
            executor = resolve(module, call.func.id)
            if executor and (executor[0], executor[1].name) in executors:
                if len(call.args) != 1 or not isinstance(call.args[0], ast.Name):
                    return False
                action = resolve(module, call.args[0].id)
                if not action or not isinstance(action[1], ast.FunctionDef):
                    return False
                actions.add((action[0], action[1].name))
    for module, root in modules.items():
        local_parents = {
            child: node for node in ast.walk(root) for child in ast.iter_child_nodes(node)
        }
        for ref in (
            node
            for node in ast.walk(root)
            if isinstance(node, ast.Name) and isinstance(node.ctx, ast.Load)
        ):
            symbol = resolve(module, ref.id)
            if not symbol:
                continue
            key = (symbol[0], symbol[1].name)
            parent = local_parents.get(ref)
            if key in factories:
                if not (isinstance(parent, ast.Call) and parent.func is ref):
                    return False
                enclosing = parent
                while enclosing in local_parents and not isinstance(enclosing, ast.FunctionDef):
                    enclosing = local_parents[enclosing]
                if (
                    not isinstance(enclosing, ast.FunctionDef)
                    or (module, enclosing.name) not in executors
                ):
                    return False
            if key in executors and not (isinstance(parent, ast.Call) and parent.func is ref):
                return False
            if key in actions:
                executor = (
                    resolve(module, parent.func.id)
                    if isinstance(parent, ast.Call) and isinstance(parent.func, ast.Name)
                    else None
                )
                if (
                    not executor
                    or (executor[0], executor[1].name) not in executors
                    or parent.args != [ref]
                ):
                    return False
    calls = 0
    for module, root in modules.items():
        local_parents = {
            child: node for node in ast.walk(root) for child in ast.iter_child_nodes(node)
        }
        for ref in (
            node
            for node in ast.walk(root)
            if isinstance(node, ast.Name)
            and isinstance(node.ctx, ast.Load)
            and resolve(module, node.id)
            and resolve(module, node.id)[0] == own
            and resolve(module, node.id)[1].name == function.name
        ):
            call = local_parents.get(ref)
            if not isinstance(call, ast.Call) or call.func is not ref or len(call.args) != 2:
                return False
            action = call
            while action in local_parents and not isinstance(action, ast.FunctionDef):
                action = local_parents[action]
            if (
                not isinstance(action, ast.FunctionDef)
                or (module, action.name) not in actions
                or len(action.args.args) < 2
            ):
                return False
            host_arg, runtime_arg = (arg.arg for arg in action.args.args[:2])
            if (
                ast.unparse(call.args[0]) != host_arg
                or ast.unparse(call.args[1]) != runtime_arg + ".user"
            ):
                return False
            if action.decorator_list:
                return False
            for target in ast.walk(action):
                if isinstance(target, (ast.Global, ast.Nonlocal)) and set(target.names) & {
                    host_arg,
                    runtime_arg,
                }:
                    return False
                if not isinstance(target, ast.Name) or not isinstance(
                    target.ctx, (ast.Store, ast.Del)
                ):
                    continue
                if target.id == host_arg:
                    return False
                if target.id == runtime_arg:
                    assignment = local_parents.get(target)
                    value = assignment.value if isinstance(assignment, ast.Assign) else None
                    if not (
                        isinstance(value, ast.Call)
                        and isinstance(value.func, ast.Name)
                        and resolve(module, value.func.id) == (own, runtime_class)
                        and value.args
                        and ast.unparse(value.args[0]) == host_arg
                    ):
                        return False
            calls += 1
    critical = {function, host_class, runtime_class, check}
    critical.update(factories.values())
    critical.update(definitions[module][name] for module, name in executors | actions)
    for node in critical:
        for part in ast.walk(node):
            if isinstance(part, ast.arg) and part.arg in {
                "os",
                "fcntl",
                "stat",
                "re",
                "pathlib",
                "P",
                "check",
                host_class.name,
                runtime_class.name,
                function.name,
            }:
                return False
    return calls > 0


BRIDGE_LOCK = '''
@contextmanager
def writer_lock(root):
    root.mkdir(parents=True, exist_ok=True)
    with (root / 'writer.lock').open('a') as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise PolicyError('instance.bridge_busy') from None
        yield
'''

BRIDGE_APPLY = '''
def apply(root, incoming, reconcile=False, withdraw=False):
    deadline = time.monotonic() + (REFRESH_BUDGET_SECONDS if incoming is None else LIFECYCLE_BUDGET_SECONDS)
    with writer_lock(root):
        try:
            return apply_locked(root, incoming, reconcile, withdraw, deadline)
        except (PolicyError, ValueError, OSError, subprocess.SubprocessError) as error:
            code = 'instance.bridge_state_unresolved' if unresolved_health(root) else failure_code(error)
            state_file(root, 'health', {'error_code': code})
            raise
'''

BRIDGE_MAIN = '''
def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--state-dir', type=Path, default=Path('/var/lib/orbit/instance-bridge'))
    parser.add_argument('--intent', action='store_true')
    parser.add_argument('--reconcile', action='store_true')
    parser.add_argument('--withdraw', action='store_true')
    args = parser.parse_args()
    try:
        changed = apply(args.state_dir, json.load(sys.stdin) if args.intent else None, args.reconcile, args.withdraw)
        print(json.dumps({'changed': changed}))
    except (PolicyError, ValueError, OSError, subprocess.SubprocessError) as error:
        code = failure_code(error)
        print(json.dumps({'error_code': code}))
        print(str(error), file=sys.stderr)
        raise SystemExit(1) from error
'''

BRIDGE_FAILURE = '''
def failure_code(error):
    if isinstance(error, subprocess.TimeoutExpired):
        return 'instance.bridge_command_timeout'
    return str(error) if str(error).startswith('instance.') else 'instance.container_bridge_write_failed'
'''


# The only destructive authority: fixed state leaves or canonical numeric endpoint
# identities. Unlink cannot remove a directory; replacement uses a fresh regular
# temporary file. Neither operation can name writer.lock or replace an ancestor.
BRIDGE_STATE = r'''
def state_file(root, kind, value=None, host=None, port=None, capture=None, remove=False):
    root = Path(root)
    if kind in ('config', 'health', 'pending'):
        path = root / {'config': 'config.json', 'health': 'health.json', 'pending': 'reconcile.pending'}[kind]
    elif kind in ('endpoint', 'staged'):
        host = str(ipaddress.IPv4Address(host))
        if type(port) is not int or not 1 <= port <= 65535 or type(capture) is not int or capture < 0:
            raise PolicyError('instance.container_bridge_write_failed')
        directory = 'endpoints' if kind == 'endpoint' else '.staged'
        path = root / directory / (host + '-' + str(port) + '-' + str(capture) + '.json')
    else:
        raise PolicyError('instance.container_bridge_write_failed')
    if remove:
        path.unlink(missing_ok=True)
        return 1
    content = json.dumps(value, sort_keys=True) + '\n'
    if path.exists() and path.read_text() == content:
        return 0
    path.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.NamedTemporaryFile(mode='w', dir=path.parent, delete=False) as stream:
        stream.write(content)
        temporary = Path(stream.name)
    temporary.chmod(0o644)
    temporary.replace(path)
    return 1
'''

BRIDGE_API = r'''
def api(name, value=None, etag=None, deadline=None):
    """Use the Incus API's ETag, so a concurrent write cannot replace another endpoint."""
    put = []
    if value is not None:
        if not isinstance(etag, str):
            raise PolicyError('instance.container_bridge_write_failed')
        put = ['--request', 'PUT', '--header', 'Content-Type: application/json',
               '--header', 'If-Match: ' + etag, '--data-binary', '@-']
    timeout = CURL_TIMEOUT_SECONDS if deadline is None else min(CURL_TIMEOUT_SECONDS, remaining(deadline))
    result = subprocess.run(['curl', '--silent', '--show-error', '--fail-with-body', '--max-time', str(CURL_TIMEOUT_SECONDS),
                             '--include', '--unix-socket', '/var/lib/incus/unix.socket',
                             'http://localhost/1.0/network-acls/' + name + '?project=default', *put],
                            input=None if value is None else json.dumps(value).encode(),
                            capture_output=True, check=False, timeout=timeout)
    if result.returncode == 28:
        raise PolicyError('instance.bridge_command_timeout')
    if result.returncode:
        detail = result.stderr.decode().strip()
        if b'\r\n\r\n' in result.stdout:
            detail += ' ' + json.loads(result.stdout.split(b'\r\n\r\n', 1)[1]).get('error', '')
        raise PolicyError(detail or 'instance.container_bridge_write_failed')
    headers, body = result.stdout.split(b'\r\n\r\n', 1)
    tags = [line.split(b':', 1)[1].strip().decode() for line in headers.splitlines()
            if line.lower().startswith(b'etag:')]
    response = json.loads(body)
    if response.get('error_code', 0):
        raise PolicyError(response.get('error', 'instance.container_bridge_write_failed'))
    return response['metadata'], tags[0] if tags else None
'''


def bridge_writer_transaction(tree, sources):
    """Return (acquisition, rejected site) for the closed bridge-writer contract.

    Compare custody, dispatch and the unlocked entry point in full. Other tool
    functions run only through apply's locked body: no executable module code,
    alternate entry point, callback escape, import replacement or rebinding can
    bypass custody. This does not exempt a file or add a lock pathname allowance.
    """
    functions = {node.name: node for node in tree.body if isinstance(node, ast.FunctionDef)}
    candidate = functions.get('writer_lock')
    if candidate is None:
        return None, None

    def refused():
        return None, candidate

    # Require the complete source inventory, as for the PHP runtime proof.
    try:
        modules = {path: ast.parse(source) for path, source in sources.items()}
    except SyntaxError:
        return refused()
    own = [path for path, module in modules.items() if ast.dump(module) == ast.dump(tree)]
    if len(own) != 1:
        return refused()
    def module_parts(path):
        parts = path.removesuffix('.py').split('/')
        return parts[:-1] if parts[-1] == '__init__' else parts

    writer = module_parts(own[0])

    def imports_writer(parts):
        # Inventory paths include the shipped source root; Python imports may
        # start below it. Compare qualified suffixes, never just the last name.
        return bool(parts) and len(parts) <= len(writer) and writer[-len(parts):] == parts

    for path, peer in modules.items():
        if path == own[0]:
            continue
        package = module_parts(path) if path.endswith('/__init__.py') else module_parts(path)[:-1]
        for node in ast.walk(peer):
            if isinstance(node, ast.Import):
                targets = [alias.name.split('.') for alias in node.names]
            elif isinstance(node, ast.ImportFrom):
                base = node.module.split('.') if node.module else []
                if node.level:
                    if node.level > len(package):
                        return refused()  # An unresolved relative import cannot prove custody.
                    base = package[:len(package) - node.level + 1] + base
                if any(alias.name == '*' for alias in node.names) and any(
                        base and len(base) <= length and writer[:length][-len(base):] == base
                        for length in range(1, len(writer))):
                    return refused()  # A package's __all__ may load the writer submodule.
                targets = [base, *(base + alias.name.split('.') for alias in node.names)]
            else:
                continue
            if any(imports_writer(target) for target in targets):
                return refused()

    contracts = [ast.parse(source).body[0]
                 for source in (BRIDGE_LOCK, BRIDGE_APPLY, BRIDGE_MAIN, BRIDGE_FAILURE, BRIDGE_STATE, BRIDGE_API)]
    if any(contract.name not in functions or ast.dump(functions[contract.name]) != ast.dump(contract)
           for contract in contracts):
        return refused()
    classes = [node for node in tree.body if isinstance(node, ast.ClassDef)]
    if len(classes) != 1 or ast.dump(classes[0]) != ast.dump(ast.parse('class PolicyError(Exception):\n    pass').body[0]):
        return refused()
    standard = {'argparse', 'fcntl', 'ipaddress', 'json', 're', 'shlex', 'subprocess', 'sys', 'tempfile', 'time'}
    imported = {}
    for node in tree.body:
        if isinstance(node, ast.Import):
            for alias in node.names:
                if alias.name not in standard or alias.asname or alias.name in imported:
                    return refused()
                imported[alias.name] = node
        if isinstance(node, ast.ImportFrom):
            if (node.level or (node.module, [(a.name, a.asname) for a in node.names]) not in
                    [('contextlib', [('contextmanager', None)]), ('pathlib', [('Path', None)])]):
                return refused()
            alias = node.names[0].name
            if alias in imported:
                return refused()
            imported[alias] = node
    required = {'argparse', 'fcntl', 'json', 'subprocess', 'sys', 'time', 'ipaddress', 'tempfile', 'contextmanager', 'Path'}
    if not required <= imported.keys():
        return refused()
    # Literal constants are inert; all record/ACL/health work must remain in
    # function bodies reached through the exact entry point and locked dispatch.
    constants = set()
    entry = ast.parse("if __name__ == '__main__':\n    main()").body[0]
    for node in tree.body:
        if isinstance(node, (ast.Import, ast.ImportFrom, ast.FunctionDef, ast.ClassDef)):
            continue
        if isinstance(node, ast.Expr) and isinstance(node.value, ast.Constant) and isinstance(node.value.value, str):
            continue
        if isinstance(node, ast.Assign) and len(node.targets) == 1 and isinstance(node.targets[0], ast.Name) and isinstance(node.value, ast.Constant):
            identifier = node.targets[0].id
            if identifier in constants:
                return refused()
            constants.add(identifier)
            continue
        if ast.dump(node) != ast.dump(entry) or node is not tree.body[-1]:
            return refused()
    builtins = {'BlockingIOError', 'Exception', 'ValueError', 'OSError', 'SystemExit', 'print', 'str', 'isinstance', 'type', 'int', '__name__'}
    reserved = set(functions) | set(imported) | constants | builtins | {'PolicyError'}
    if (set(functions) | set(imported) | constants) & builtins:
        return refused()
    declarations = [node.name for node in tree.body if isinstance(node, (ast.FunctionDef, ast.ClassDef))]
    if len(declarations) != len(set(declarations)) or (set(declarations) & (set(imported) | constants)) or set(imported) & constants:
        return refused()
    parents = {child: node for node in ast.walk(tree) for child in ast.iter_child_nodes(node)}
    acquisition = next(node for node in ast.walk(candidate) if isinstance(node, ast.Call) and ast.unparse(node.func) == 'fcntl.flock')
    acquire_nodes = set(ast.walk(acquisition))
    main_nodes = set(ast.walk(functions['main']))
    state_nodes = set(ast.walk(functions['state_file']))
    api_nodes = set(ast.walk(functions['api']))
    lock_nodes = set(ast.walk(candidate))
    callable_names = {node.name for node in ast.walk(tree) if isinstance(node, ast.FunctionDef)}
    safe_builtins = {'all', 'any', 'bool', 'dict', 'enumerate', 'float', 'int', 'isinstance',
                     'len', 'list', 'max', 'min', 'next', 'print', 'range', 'set', 'sorted',
                     'str', 'tuple', 'zip', 'PolicyError', 'SystemExit'}
    module_members = {
        'argparse': {'ArgumentParser'}, 'fcntl': {'flock', 'LOCK_EX', 'LOCK_NB'},
        'ipaddress': {'IPv4Address'}, 'json': {'load', 'loads', 'dumps'}, 're': {'fullmatch'},
        'shlex': {'split'}, 'subprocess': {'run', 'check_output', 'SubprocessError', 'TimeoutExpired'},
        'sys': {'stdin', 'stderr'}, 'tempfile': {'NamedTemporaryFile'},
        'time': {'monotonic'},
    }
    destructive = {'unlink', 'remove', 'removedirs', 'rmdir', 'rmtree', 'rename', 'renames',
                   'replace', 'move', 'move_into', 'copy', 'copy_into', 'copyfile', 'copytree',
                   'symlink_to', 'hardlink_to', 'link_to', 'touch', 'write_text', 'write_bytes',
                   'cleanup', 'truncate'}
    effects = destructive | {'open', 'append', 'extend', 'insert', 'pop', 'clear'}
    for node in ast.walk(tree):
        if isinstance(node, (ast.Import, ast.ImportFrom)) and node not in tree.body:
            return refused()
        if isinstance(node, ast.ExceptHandler) and node.name in reserved:
            return refused()
        if isinstance(node, ast.Name) and isinstance(node.ctx, (ast.Store, ast.Del)) and node.id in reserved:
            if not (isinstance(parents.get(node), ast.Assign) and parents[node] in tree.body):
                return refused()
        if isinstance(node, ast.arg) and node.arg in reserved:
            return refused()
        if isinstance(node, ast.Global):
            return refused()
        if isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef, ast.ClassDef)) and node not in tree.body:
            if node.name in reserved or not isinstance(node, ast.FunctionDef) or node.decorator_list:
                return refused()
        if isinstance(node, ast.FunctionDef):
            if node is not candidate and node.decorator_list:
                return refused()
            if node in tree.body and (node.returns or any(arg.annotation for arg in ast.walk(node.args) if isinstance(arg, ast.arg))
                                      or any(value is not None and not isinstance(value, ast.Constant)
                                             for value in node.args.defaults + node.args.kw_defaults)):
                return refused()
        if isinstance(node, ast.Attribute) and isinstance(node.ctx, (ast.Store, ast.Del)):
            return refused()
        if isinstance(node, ast.Attribute):
            parent = parents.get(node)
            # Deny every destructive effect, including dead/nested/control-flow
            # sites. Only the exact semantic state helper owns these operations.
            if node.attr in destructive and node not in state_nodes:
                return refused()
            if node.attr == 'open' and node not in state_nodes | lock_nodes:
                return refused()
            if node.attr.startswith('_') or node.attr in effects and not (isinstance(parent, ast.Call) and parent.func is node):
                return refused()
            if isinstance(node.value, ast.Name) and node.value.id in module_members:
                if node.attr not in module_members[node.value.id]:
                    return refused()
                if node.value.id not in {'sys', 'fcntl'} and node.attr not in {'SubprocessError', 'TimeoutExpired'}:
                    if not (isinstance(parent, ast.Call) and parent.func is node):
                        return refused()
        if isinstance(node, ast.Name) and isinstance(node.ctx, ast.Load):
            parent = parents.get(node)
            if node.id.startswith('__') and node.id != '__name__':
                return refused()
            if node.id in module_members and not isinstance(parent, ast.Attribute):
                return refused()
            if node.id in {'eval', 'exec', 'globals', 'locals', 'vars', 'getattr', 'setattr', 'delattr', '__import__', 'open', 'compile'}:
                return refused()
            if node.id in {'Path', 'contextmanager'} and not (isinstance(parent, ast.Call) and parent.func is node or node in candidate.decorator_list
                                                                         or node.id == 'Path' and node in main_nodes):
                return refused()
            if node.id == 'fcntl' and node not in acquire_nodes:
                return refused()
            if node.id == 'type' and node not in state_nodes:
                return refused()
            if node.id in callable_names:
                if not (isinstance(parent, ast.Call) and parent.func is node):
                    return refused()
                enclosing = parent
                while enclosing in parents and not isinstance(enclosing, ast.FunctionDef):
                    enclosing = parents[enclosing]
                caller = enclosing.name if isinstance(enclosing, ast.FunctionDef) else None
                allowed = {'writer_lock': {'apply'}, 'apply': {'main'}, 'apply_locked': {'apply'}, 'main': {None}}
                if node.id in allowed and caller not in allowed[node.id]:
                    return refused()
        if isinstance(node, ast.Call) and isinstance(node.func, ast.Name) and node.func.id in {'eval', 'exec', 'globals', 'locals', 'vars', 'getattr', 'setattr', 'delattr', '__import__', 'open'}:
            return refused()
    for node in ast.walk(tree):
        if not isinstance(node, ast.Call):
            continue
        if isinstance(node.func, ast.Name):
            if node.func.id not in callable_names | safe_builtins | {'Path', 'contextmanager'}:
                if node not in state_nodes or node.func.id != 'type':
                    return refused()
            if node.func.id == 'state_file':
                # This API accepts semantic state kinds, never an arbitrary path,
                # callback or destination. Its exact body constructs every leaf.
                if (not 2 <= len(node.args) <= 3 or any(isinstance(arg, ast.Starred) for arg in node.args)
                        or not isinstance(node.args[1], ast.Constant)
                        or node.args[1].value not in {'config', 'health', 'pending', 'endpoint', 'staged'}
                        or any(item.arg not in {'host', 'port', 'capture', 'remove'} for item in node.keywords)):
                    return refused()
        if isinstance(node.func, ast.Attribute):
            callee = ast.unparse(node.func)
            if callee.startswith('tempfile.') and node not in state_nodes:
                return refused()
            if callee.startswith('subprocess.') and node not in api_nodes:
                nat = ast.parse("subprocess.check_output(['iptables-save', '-t', 'nat'], text=True, timeout=remaining(deadline))", mode='eval').body
                if ast.dump(node) != ast.dump(nat):
                    return refused()
    return acquisition, None
