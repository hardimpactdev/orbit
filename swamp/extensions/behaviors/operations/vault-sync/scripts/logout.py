from bw import cli, emit


def logout():
    cli("logout")
    return {"logged_out": True}


if __name__ == "__main__":
    emit(logout)
