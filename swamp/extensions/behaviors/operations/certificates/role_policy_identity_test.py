"""Factored certificate helpers retain the reviewed policy AST before extraction."""

import ast
import hashlib
import pathlib
import unittest

EXPECTED = {
    "Refusal": "0cc8b24206432de806573803a75670582e8f61963210aaac9e204fbf3f9f1b29",
    "command": "7443547695fdd2c015b701c1f8805fd352d2e11628377104f506b06d0fe70f6c",
    "service": "cf0723dbdf73e350072afa3dcb62c485139d8c5ac8c105f33ff87e12ea603624",
    "exists": "bc6d8905f0a6121ce339d57c265ea5be78db00d3a74b49948cde2bec27b27401",
    "remove": "c8020a3d5e9b93489ea82477af95020770e918a0a5de5b1d99aae9a8821089fd",
    "certificate_scope": "0199583e337b4559d86626292eff3cf58bdb3b52c048149f8c59b4994751e883",
    "certificate_names": "286096dcd6ad2ccf7a1988767920391db5eb57bbc9f32da684f82af53d27efa3",
    "certificate_parents": "53f379f33b89759b0cee98f717f8affd795494eb1cb51836abcaa290dcf7afe1",
    "previous_pair": "040426033a4eccbc3a780576c337ee9c8dcd5e2918791bf2382d48101f3ce977",
}


class HelperIdentity(unittest.TestCase):
    def test_factored_helpers_preserve_existing_policy(self):
        source = pathlib.Path(__file__).with_name("retained_role_policy.py").read_text()
        actual = {
            node.name: hashlib.sha256(
                ast.dump(node, include_attributes=False).encode()
            ).hexdigest()
            for node in ast.parse(source).body
            if isinstance(node, (ast.ClassDef, ast.FunctionDef))
            and node.name in EXPECTED
        }
        self.assertEqual(EXPECTED, actual)


if __name__ == "__main__":
    unittest.main()
