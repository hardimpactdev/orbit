from common import FILES, SERVICE, WG, command, finish, publish, saved_directory


def restore(data):
    snapshot = data["snapshot"]
    directory = saved_directory(data)
    command(["/usr/bin/systemctl", "stop", SERVICE])
    for name in FILES:
        if name in snapshot["present"]:
            publish(WG / name, (directory / name).read_text())
        else:
            (WG / name).unlink(missing_ok=True)
    for action in (
        ["unmask"],
        ["unmask", "--runtime"],
        ["disable"],
        ["disable", "--runtime"],
    ):
        command(["/usr/bin/systemctl", *action, SERVICE])
    enabled = snapshot["enabled"]
    runtime = ["--runtime"] if enabled.endswith("-runtime") else []
    if enabled in ("enabled", "enabled-runtime"):
        command(["/usr/bin/systemctl", "enable", *runtime, SERVICE])
    if snapshot["active"]:
        command(["/usr/bin/systemctl", "start", SERVICE])
        for field, values in snapshot["resolver"].items():
            command(["/usr/bin/resolvectl", field, "orbit", *(values or [""])])
    recorded = snapshot["recorded_resolver"]
    if recorded is not None and recorded["link"] != "orbit":
        for field, values in recorded["settings"].items():
            command(["/usr/bin/resolvectl", field, recorded["link"], *(values or [""])])
    if enabled in ("masked", "masked-runtime"):
        command(["/usr/bin/systemctl", "mask", *runtime, SERVICE])
    return {"restored": True}


finish(restore)
