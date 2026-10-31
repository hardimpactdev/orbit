"""Create a disposable Vaultwarden account for the optional real-CLI test."""

import base64
import hashlib
import hmac
import json
import os
import ssl
import urllib.parse
import urllib.request
import uuid


def provision(url, password, ca_file):
    # Fixture encryption is setup data, never an Orbit writer implementation.
    from cryptography.hazmat.primitives import hashes, padding, serialization
    from cryptography.hazmat.primitives.asymmetric import padding as rsa_padding
    from cryptography.hazmat.primitives.asymmetric import rsa
    from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes

    def encode(value):
        return base64.b64encode(value).decode()

    def encrypt(value, key):
        iv = os.urandom(16)
        padder = padding.PKCS7(128).padder()
        padded = padder.update(value) + padder.finalize()
        engine = Cipher(algorithms.AES(key[:32]), modes.CBC(iv)).encryptor()
        cipher = engine.update(padded) + engine.finalize()
        return "2." + "|".join(
            map(encode, (iv, cipher, hmac.digest(key[32:], iv + cipher, "sha256")))
        )

    token = None

    def request(path, value=None, form=False):
        headers = {
            "Content-Type": "application/x-www-form-urlencoded"
            if form
            else "application/json"
        }
        if token:
            headers["Authorization"] = "Bearer " + token
        data = (
            None
            if value is None
            else (urllib.parse.urlencode(value) if form else json.dumps(value)).encode()
        )
        with urllib.request.urlopen(
            urllib.request.Request(url + path, data=data, headers=headers),
            timeout=15,
            context=ssl.create_default_context(cafile=ca_file),
        ) as response:
            return json.load(response)

    email = "native-sync-" + uuid.uuid4().hex + "@example.test"
    master = hashlib.pbkdf2_hmac(
        "sha256", password.encode(), email.encode(), 600000, 32
    )
    password_hash = encode(
        hashlib.pbkdf2_hmac("sha256", master, password.encode(), 1, 32)
    )
    stretched = hmac.digest(master, b"enc\1", "sha256") + hmac.digest(
        master, b"mac\1", "sha256"
    )
    user_key = os.urandom(64)
    private = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    public = private.public_key()
    request(
        "/identity/accounts/register",
        {
            "email": email,
            "name": "Native sync fixture",
            "masterPasswordHash": password_hash,
            "key": encrypt(user_key, stretched),
            "kdf": 0,
            "kdfIterations": 600000,
            "keys": {
                "publicKey": encode(
                    public.public_bytes(
                        serialization.Encoding.DER,
                        serialization.PublicFormat.SubjectPublicKeyInfo,
                    )
                ),
                "encryptedPrivateKey": encrypt(
                    private.private_bytes(
                        serialization.Encoding.DER,
                        serialization.PrivateFormat.PKCS8,
                        serialization.NoEncryption(),
                    ),
                    user_key,
                ),
            },
        },
    )
    login = request(
        "/identity/connect/token",
        {
            "grant_type": "password",
            "username": email,
            "password": password_hash,
            "scope": "api offline_access",
            "client_id": "cli",
            "deviceType": 25,
            "deviceIdentifier": "lane23-disposable-native-sync",
            "deviceName": "Native sync test",
        },
        form=True,
    )
    token = login["access_token"]
    org_key = os.urandom(64)
    organization = request(
        "/api/organizations",
        {
            "name": "Native sync fixture",
            "billingEmail": email,
            "planType": 0,
            "collectionName": encrypt(b"Disposable", org_key),
            "key": "4."
            + encode(
                public.encrypt(
                    org_key,
                    rsa_padding.OAEP(
                        mgf=rsa_padding.MGF1(hashes.SHA1()),
                        algorithm=hashes.SHA1(),
                        label=None,
                    ),
                )
            ),
        },
    )
    snapshot = request("/api/sync?excludeDomains=true")
    api_key = request("/api/accounts/api-key", {"masterPasswordHash": password_hash})
    return {
        "url": url,
        "client_id": "user." + snapshot["profile"]["id"],
        "client_secret": api_key["apiKey"],
        "master_password": password,
        "organization_id": organization["id"],
        "collection_id": snapshot["collections"][0]["id"],
    }
