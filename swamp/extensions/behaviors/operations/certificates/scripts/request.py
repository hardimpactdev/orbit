"""Prepare the Node-local leaf key and return only its public CSR."""

import base64

from leaf import admitted, request
from public_input import emit, intent


def main():
    data, account = admitted(intent())
    value = request(data, account)
    if value["state"] == "current":
        return value
    return {
        "state": "request",
        "request_base64": base64.b64encode(value["request"].encode()).decode(),
    }


if __name__ == "__main__":
    emit(main)
