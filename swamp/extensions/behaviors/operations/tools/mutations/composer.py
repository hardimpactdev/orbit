from support import INPUT, query

query(["/usr/bin/composer", "global", "remove", INPUT["package"], "--no-interaction", "--no-ansi",
       "--no-progress", "--no-audit", "--with-all-dependencies"], {"COMPOSER_HOME": "/opt/orbit/composer"})
print('{"ready":true}')
