"""Revoke the existing short-lived installation token without exposing it."""

from publication_common import request
from repository_common import emit


def revoke():
    request("/installation/token", "DELETE")
    return {"revoked": True}


if __name__ == "__main__":
    emit(revoke)
