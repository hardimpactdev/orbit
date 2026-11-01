from support import INPUT, query

install = INPUT["kind"] == "tool.install"
query(["/usr/bin/composer", "global", "require" if install else "update", INPUT["package"] + ":*" if install else INPUT["package"], "--no-interaction", "--no-ansi", "--no-progress", "--no-audit", "--with-all-dependencies"], {"COMPOSER_HOME": "/opt/orbit/composer"})
print('{"ready":true}')
