from bw import cli, emit


def login():
    cli("login", "--apikey", authenticate="login")
    return {"authenticated": True}


if __name__ == "__main__":
    emit(login)
