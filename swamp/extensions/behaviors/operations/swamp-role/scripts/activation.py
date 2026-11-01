"""Start prepared services only; existing Serve processes are never restarted."""

from common import control, mount_units, plan, private_file, ready, tls_custody

if __name__ == "__main__":
    data = plan()
    tls_custody(data)
    for descriptor in data["credential_files"]:
        private_file(descriptor["path"], descriptor["owner"])
    for name, _ in mount_units(data):
        control("start", name)
    control("enable", *data["services"])
    control("start", *data["services"])
    ready()
