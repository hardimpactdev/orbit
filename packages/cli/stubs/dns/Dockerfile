FROM alpine:latest
RUN apk add --no-cache dnsmasq bind-tools
HEALTHCHECK --interval=10s --timeout=5s --start-period=5s --retries=3 \
    CMD nslookup localhost 127.0.0.1 > /dev/null 2>&1 || exit 1
ENTRYPOINT ["dnsmasq", "-k", "-C", "/etc/dnsmasq.conf"]
