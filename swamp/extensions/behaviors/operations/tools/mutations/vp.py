from support import INPUT, query

scope = INPUT["before"]["scope_path"]
query([scope + "/bin/vp", "remove", "-g", INPUT["package"]], {"VP_HOME": scope})
print('{"ready":true}')
