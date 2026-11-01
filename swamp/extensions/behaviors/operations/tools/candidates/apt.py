from support import INPUT, finish, query


def observe():
    options = []
    if INPUT.get("repository"):
        source = "/etc/apt/sources.list.d/orbit-" + INPUT["repository"]["name"] + ".sources"
        options = ["-o", "Dir::Etc::sourcelist=" + source, "-o", "Dir::Etc::sourceparts=-",
                   "-o", "Dir::State::status=/dev/null", "-o", "Dir::Cache::pkgcache=", "-o", "Dir::Cache::srcpkgcache="]
    rows = [line.lstrip()[10:] for line in query(["/usr/bin/apt-cache", *options, "policy", "--", INPUT["package"]]).stdout.splitlines() if line.lstrip().startswith("Candidate:")]
    if len(rows) != 1 or not rows[0].startswith((" ", "\t")) or not rows[0].strip():
        raise ValueError("Invalid apt candidate")
    return {"version": None if rows[0].strip() == "(none)" else rows[0].strip()}
finish(observe)
