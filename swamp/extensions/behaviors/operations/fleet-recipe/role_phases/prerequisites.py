"""Keep the legacy prerequisite order in one bounded native phase."""

import json

from phase_common import account, execute, inputs, module


def converge(data, observed):
    intent = data["intent"]
    prepared = data["prepared_tools"]
    role = intent["role"]
    policy = module("role_prerequisites")
    if role in ["ingress", "websocket", "app-prod"]:
        policy.caddy(intent["caddy_package"])
    if role == "app-prod":
        policy.prerequisites(
            {
                **observed,
                "releases": intent["prerequisites"]["releases"],
                "packages": [
                    p for p in intent["prerequisites"]["packages"] if p != "caddy"
                ],
                "apps_directory": False,
                "composer_home": True,
                "install_packages": not prepared,
            }
        )
        execute("canonical_recipe", intent["php"], "php-build")
        if not prepared and intent["javascript"] is not None:
            execute("canonical_recipe", intent["javascript"], "javascript")
        ordering = module("caddy_ordering").converge_ordering()
        if not ordering["completed"]:
            raise ValueError("service.ordering_failed")
    elif not prepared:
        execute("packages")
    return {"ready": True}


if __name__ == "__main__":
    print(json.dumps(converge(inputs(), account())))
