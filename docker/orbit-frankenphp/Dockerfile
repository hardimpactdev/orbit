ARG PHP_VERSION=8.5
ARG SQLITE_VERSION=3.44.6
ARG SQLITE_SOURCE_ID=863c171b76cd36e0c71662d4a50d84da531cc7fbead893d02459577febdf6396
ARG SQLITE_ARCHIVE_SHA256=c25cd42f803d5fb0af5f1a2863c9f529a8fd35177cb24b0f6e970b1cc96f00f0

FROM dunglas/frankenphp:1-php${PHP_VERSION}-bookworm AS sqlite

ARG SQLITE_VERSION
ARG SQLITE_SOURCE_ID
ARG SQLITE_ARCHIVE_SHA256

RUN apt-get update \
    && apt-get install -y --no-install-recommends build-essential ca-certificates curl tcl \
    && curl -fsSL --retry 5 --retry-delay 2 --retry-all-errors \
        "https://www.sqlite.org/src/tarball/${SQLITE_SOURCE_ID}/sqlite.tar.gz" \
        -o /tmp/sqlite.tar.gz \
    && echo "${SQLITE_ARCHIVE_SHA256}  /tmp/sqlite.tar.gz" | sha256sum -c - \
    && test "$(tar -xOf /tmp/sqlite.tar.gz sqlite/manifest.uuid)" = "$SQLITE_SOURCE_ID" \
    && mkdir -p /tmp/sqlite \
    && tar -xzf /tmp/sqlite.tar.gz -C /tmp/sqlite --strip-components=1 \
    && cd /tmp/sqlite \
    && ./configure --prefix=/opt/sqlite --disable-static --enable-shared \
    && make -j"$(getconf _NPROCESSORS_ONLN)" \
    && make install \
    && LD_LIBRARY_PATH=/opt/sqlite/lib php -r \
        '$extension = SQLite3::version()["versionString"]; $query = (new PDO("sqlite::memory:"))->query("select sqlite_version()")->fetchColumn(); exit($extension === "'"${SQLITE_VERSION}"'" && $query === "'"${SQLITE_VERSION}"'" ? 0 : 1);'

FROM node:22-bookworm-slim AS node

FROM dunglas/frankenphp:1-php${PHP_VERSION}-bookworm

ARG SQLITE_VERSION

COPY --from=sqlite /opt/sqlite /usr/local
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules

ENV LD_LIBRARY_PATH=/usr/local/lib

RUN ldconfig \
    && php -r \
        '$extension = SQLite3::version()["versionString"]; $query = (new PDO("sqlite::memory:"))->query("select sqlite_version()")->fetchColumn(); exit($extension === "'"${SQLITE_VERSION}"'" && $query === "'"${SQLITE_VERSION}"'" ? 0 : 1);' \
    && ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx \
    && apt-get update \
    && apt-get install -y --no-install-recommends chromium \
    && rm -rf /var/lib/apt/lists/*

ENV PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium

RUN install-php-extensions \
    bcmath \
    exif \
    gd \
    intl \
    pcntl \
    pdo_mysql \
    pdo_pgsql \
    redis \
    sockets \
    zip
