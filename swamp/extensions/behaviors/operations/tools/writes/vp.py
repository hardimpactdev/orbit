from support import INPUT, query

scope = INPUT["before"]["scope_path"]
query([scope + "/bin/vp", "install" if INPUT["kind"] == "tool.install" else "update", "-g", INPUT["package"], *( ["--node", "lts"] if INPUT["kind"] == "tool.install" else ["--reinstall-node-mismatch"])], {"VP_HOME": scope})
print('{"ready":true}')
