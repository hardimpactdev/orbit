from support import INPUT, finish, query


def observe():
    manager = query(["/usr/bin/apt-get", "--version"]).stdout.splitlines()[0]
    if INPUT.get("manager_only"):
        return {"manager_version": manager, "versions": [], "complete": True}
    result = query(["/usr/bin/dpkg-query", "--show", "--showformat=${Package}\t${Status}\t${Version}\n",
                    "--", INPUT["package"]], check=False)
    absent = f"dpkg-query: no packages found matching {INPUT['package']}"
    if result.returncode == 1 and not result.stdout and result.stderr.strip() == absent:
        versions = []
    else:
        result.check_returncode()
        name, status, version = result.stdout.strip().split("\t")
        if name != INPUT["package"]:
            raise ValueError("another package")
        absent = status in ("deinstall ok config-files", "deinstall ok not-installed", "purge ok config-files",
                            "purge ok not-installed", "unknown ok not-installed")
        versions = [] if absent else [version if status == "install ok installed" else None]
    return {"manager_version": manager, "versions": versions, "complete": True}

finish(observe)
