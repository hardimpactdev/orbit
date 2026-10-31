from bw import cli, configuration, emit


def configure():
    _, intent = configuration()
    cli("config", "server", intent["url"])
    return {"configured": True}


if __name__ == "__main__":
    emit(configure)
