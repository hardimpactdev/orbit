import json

from support import INPUT, finish, query


def observe():
    scope = INPUT["before"]["scope_path"]
    return {"version": json.loads(query([scope + "/bin/vp", "info", INPUT["package"], "version", "--json"], {"VP_HOME": scope}).stdout)}
finish(observe)
