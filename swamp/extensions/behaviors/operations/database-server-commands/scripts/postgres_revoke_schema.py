"""Single PostgreSQL revoke schema action."""

from create_common import main
from create_sql import postgres_scope

if __name__ == "__main__":
    main(
        lambda intent, material, item: postgres_scope(
            intent, material, item, "revoke_schema"
        )
    )
