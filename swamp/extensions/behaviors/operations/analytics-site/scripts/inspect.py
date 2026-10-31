from common import run


def action(c):
    domain = c.site["domain"]
    timezone = c.inspect(domain)
    previous = c.site.get("previous_domain")
    if timezone is None and previous and previous != domain:
        domain = previous
        timezone = c.inspect(domain)
    mode = (
        "create"
        if timezone is None
        else (
            "update"
            if domain != c.site["domain"] or timezone != c.site["timezone"]
            else "unchanged"
        )
    )
    return {"action": mode, "current_domain": domain}


if __name__ == "__main__":
    run(action)
