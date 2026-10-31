"""Execute one recorded user/grant SQL action and retain no client output."""

from server_common import main
from user_common import execute, validate


def apply(intent, material):
    validate(intent, material)
    execute(intent, material, material["sql"])
    return {"backend": "active"}


if __name__ == "__main__":
    main(apply, error_code="database.user_create_outcome_unknown")
