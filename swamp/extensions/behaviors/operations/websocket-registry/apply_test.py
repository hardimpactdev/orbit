"""Public expiry/refusal results at the HTTP boundary; no live requests."""

import contextlib
import importlib.util
import io
import json
import unittest
import urllib.error
from pathlib import Path
from unittest.mock import Mock, patch

spec = importlib.util.spec_from_file_location(
    "websocket_apply", Path(__file__).parent / "scripts/apply.py"
)
action = importlib.util.module_from_spec(spec)
spec.loader.exec_module(action)


class ApplyExpiry(unittest.TestCase):
    def test_only_timeouts_are_pending_and_provider_error_values_are_never_printed(
        self,
    ):
        for error, code in [
            (TimeoutError("private-provider-diagnostic"), "websocket.registry_pending"),
            (
                urllib.error.URLError(TimeoutError("private-provider-diagnostic")),
                "websocket.registry_pending",
            ),
            (
                urllib.error.URLError(
                    ConnectionRefusedError("private-provider-diagnostic")
                ),
                "websocket.registry_unavailable",
            ),
        ]:
            with self.subTest(kind=type(error).__name__):
                opener = Mock()
                opener.open.side_effect = error
                output, errors = io.StringIO(), io.StringIO()
                request = json.dumps(
                    {"port": 18790, "request": {"process_id": "public-fixture"}}
                )
                with (
                    patch.object(
                        action.urllib.request, "build_opener", return_value=opener
                    ),
                    patch.object(action.sys, "argv", ["apply.py", request]),
                    patch.object(
                        action.sys, "stdin", io.StringIO("private-credential-fixture")
                    ),
                    contextlib.redirect_stdout(output),
                    contextlib.redirect_stderr(errors),
                ):
                    self.assertEqual(action.main(), 1)
                self.assertEqual(json.loads(output.getvalue()), {"error_code": code})
                self.assertNotIn("private-", output.getvalue() + errors.getvalue())
                self.assertEqual(opener.open.call_count, 1)


if __name__ == "__main__":
    unittest.main()
