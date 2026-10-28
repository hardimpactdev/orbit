FROM swampclub/swamp@sha256:73e12ebf977819055a78f538deb3cf8311c58e2f4dd953d418adede13e8a1668
USER root
RUN apt-get update && apt-get install -y --no-install-recommends curl ca-certificates \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /var/lib/swamp-worker && chown 1000:1000 /var/lib/swamp-worker
USER swamp
