"""Git's update signal is conservative, paginated, and fails visibly when an upstream check fails."""
import importlib.machinery
import importlib.util
import io
import urllib.error
import subprocess
import unittest
from pathlib import Path
from unittest.mock import patch, MagicMock

ROOT = Path(__file__).resolve().parents[4]
loader = importlib.machinery.SourceFileLoader('git_build_watch', str(ROOT / 'bin/git-build-watch'))
watch = importlib.util.module_from_spec(importlib.util.spec_from_loader(loader.name, loader))
loader.exec_module(watch)
PIN = '1:2.55.0-0ppa1~ubuntu26.04.2'
NEW = '1:2.55.1-0ppa1~ubuntu26.04.1'
RECIPE = {'ubuntu': {'release': '26.04'}, 'tools': {'git': {'version': PIN}}, 'git': {'usn_since': '2026-08-11'}}


def notice(identifier, package, published):
    return {'id': identifier, 'published': published, 'release_packages': {'resolute': [{'name': package, 'is_source': True}]}}


class GitBuildWatchTest(unittest.TestCase):
    def test_fetch_recovers_from_503_and_connection_reset(self):
        for error in (urllib.error.HTTPError(watch.PPA, 503, 'Unavailable', {}, None), ConnectionResetError('reset')):
            response = MagicMock()
            response.__enter__.return_value = response
            response.geturl.return_value = watch.PPA
            response.read.return_value = b'{"entries": []}'
            with self.subTest(error=error), patch.object(watch.urllib.request, 'urlopen', side_effect=[error, response]) as fetch, \
                 patch.object(watch.time, 'sleep') as sleep:
                self.assertEqual(watch.fetch_json(watch.PPA), {'entries': []})
                self.assertEqual(fetch.call_count, 2)
                fetch.assert_called_with(watch.PPA, timeout=15)
                sleep.assert_called_once_with(10)

    def test_persistent_5xx_fails_after_four_attempts_with_backoff(self):
        error = urllib.error.HTTPError(watch.PPA, 503, 'Unavailable', {}, None)
        with patch.object(watch.urllib.request, 'urlopen', side_effect=error) as fetch, patch.object(watch.time, 'sleep') as sleep:
            with self.assertRaisesRegex(RuntimeError, 'after 4 attempts'):
                watch.fetch_json(watch.PPA)
            self.assertEqual(fetch.call_count, 4)
            self.assertEqual([call.args[0] for call in sleep.call_args_list], [10, 20, 30])

    def test_4xx_and_invalid_json_never_retry(self):
        with patch.object(watch.time, 'sleep') as sleep:
            for code in (404, 429):
                with patch.object(watch.urllib.request, 'urlopen', side_effect=urllib.error.HTTPError(watch.PPA, code, 'Client error', {}, None)) as fetch:
                    with self.assertRaises(urllib.error.HTTPError):
                        watch.fetch_json(watch.PPA)
                    self.assertEqual(fetch.call_count, 1)
            response = io.BytesIO(b'invalid JSON')
            response.geturl = lambda: watch.PPA
            with patch.object(watch.urllib.request, 'urlopen', return_value=response) as fetch:
                with self.assertRaises(ValueError):
                    watch.fetch_json(watch.PPA)
                self.assertEqual(fetch.call_count, 1)
            sleep.assert_not_called()

    def test_flags_a_newer_ppa_build_and_git_usn_but_not_libgit2_or_reviewed_notices(self):
        pages = [
            {'entries': [{'source_package_version': PIN}, {'source_package_version': NEW}]},
            {'notices': [notice('USN-1-1', 'git', '2026-08-11T10:00:00'),
                         notice('USN-2-1', 'libgit2', '2026-10-15T10:00:00'),
                         notice('USN-3-1', 'git', '2026-08-10T10:00:00')], 'total_results': 3},
        ]
        with patch.object(watch, 'fetch_json', side_effect=pages) as fetch, \
             patch.object(watch, 'newer', side_effect=lambda candidate, pinned: candidate == NEW):
            findings = watch.check(RECIPE)
        self.assertEqual(len(findings), 2)
        self.assertTrue(any(NEW in finding for finding in findings))
        self.assertTrue(any('USN-1-1' in finding for finding in findings))
        self.assertIn('distro_series=https%3A%2F%2Fapi.launchpad.net%2F1.0%2Fubuntu%2Fresolute', fetch.call_args_list[0].args[0])
        self.assertIn('details=git', fetch.call_args_list[1].args[0])

    def test_checks_later_pages_and_returns_no_signal_when_every_build_and_notice_is_reviewed(self):
        pages = [
            {'entries': [{'source_package_version': PIN}], 'next_collection_link': watch.PPA + '?page=2'},
            {'entries': [{'source_package_version': PIN}]},
            {'notices': [notice('USN-1-1', 'git', '2026-08-10')], 'total_results': 2},
            {'notices': [notice('USN-2-1', 'git', '2026-08-09')], 'total_results': 2},
        ]
        with patch.object(watch, 'fetch_json', side_effect=pages) as fetch, patch.object(watch, 'newer', return_value=False):
            self.assertEqual(watch.check(RECIPE), [])
        self.assertIn('offset=1', fetch.call_args_list[-1].args[0])
        self.assertEqual(fetch.call_count, 4)

    def test_network_or_malformed_data_fail_the_check_instead_of_reporting_no_updates(self):
        for error in (OSError('network down'), ValueError('invalid JSON'), KeyError('entries')):
            with self.subTest(error=str(error)), patch.object(watch, 'fetch_json', side_effect=error):
                with self.assertRaises(type(error)):
                    watch.check(RECIPE)

    def test_uses_debian_version_comparison_including_epochs_and_rejects_comparison_errors(self):
        for code, expected in ((0, True), (1, False), (2, None)):
            with self.subTest(code=code), patch.object(watch.subprocess, 'run', return_value=subprocess.CompletedProcess([], code)) as run:
                if expected is None:
                    with self.assertRaises(RuntimeError):
                        watch.newer(NEW, PIN)
                else:
                    self.assertEqual(watch.newer(NEW, PIN), expected)
                run.assert_called_once_with(['dpkg', '--compare-versions', NEW, 'gt', PIN], check=False)

    def test_unexpected_pagination_and_empty_notice_pages_fail_visibly(self):
        for pages in ([{'entries': [], 'next_collection_link': 'https://other.example/'}],
                      [{'entries': []}, {'notices': [], 'total_results': 1}]):
            with self.subTest(pages=pages), patch.object(watch, 'fetch_json', side_effect=pages):
                with self.assertRaises(RuntimeError):
                    watch.check(RECIPE)


if __name__ == '__main__':
    unittest.main()
