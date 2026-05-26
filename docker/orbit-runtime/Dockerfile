FROM php:8.5-cli-bookworm

ENV DEBIAN_FRONTEND=noninteractive \
    ORBIT_SOURCE_PATH=/opt/orbit

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        bash \
        ca-certificates \
        curl \
        git \
        libcurl4-openssl-dev \
        libicu-dev \
        libonig-dev \
        libsqlite3-dev \
        libzip-dev \
        openssh-client \
        procps \
        sudo \
        tini \
        unzip \
        zip \
    && docker-php-ext-install bcmath curl intl mbstring pdo_sqlite zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=docker:cli /usr/local/bin/docker /usr/local/bin/docker
COPY --from=docker:cli /usr/local/libexec/docker/cli-plugins/docker-compose /usr/local/libexec/docker/cli-plugins/docker-compose

COPY docker/orbit-runtime/entrypoint.sh /usr/local/bin/orbit-runtime-entrypoint

RUN chmod 755 /usr/local/bin/orbit-runtime-entrypoint \
    && ln -s /usr/local/bin/orbit-runtime-entrypoint /usr/local/bin/orbit

WORKDIR /opt/orbit
VOLUME ["/opt/orbit"]

ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/orbit-runtime-entrypoint"]
CMD ["sleep", "infinity"]
