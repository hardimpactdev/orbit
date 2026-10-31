"""Narrow AST proof for the canonical parameterized PHP/FPM host transaction."""

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
