from bw import cli, emit


def sync():
    cli("sync")
    return {"synced": True}


if __name__ == "__main__":
    emit(sync)
