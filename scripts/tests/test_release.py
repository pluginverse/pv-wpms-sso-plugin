import importlib.util
import tempfile
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location('release', Path(__file__).resolve().parents[1] / 'release.py')
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)

HEADER = '<?php\n/**\n * Plugin Name: PV WPMS SSO\n * Version: 0.1.0\n */\n_doing_it_wrong( __FILE__, "x", \'0.1.0\' );\n'


def project(root, changelog):
    (root / 'CHANGELOG.md').write_text(changelog)
    (root / 'pv-wpms-sso.php').write_text(HEADER)


class ReleaseTest(unittest.TestCase):
    def test_notes_extracts_only_requested_release(self):
        text = '## [Unreleased]\n\n## [1.2.4] - 2026-11-07\n\n### Fixed\n\n- Repair.\n\n## [1.2.3] - 2026-11-01\n\n- Old.\n'
        self.assertEqual(release.notes(text, '1.2.4'), '### Fixed\n\n- Repair.')
        with self.assertRaises(ValueError):
            release.notes(text, '1.2.5')

    def test_prepare_releases_the_never_released_header_version(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            project(root, '# Changelog\n\n## [Unreleased]\n\n### Added\n\n- First.\n')
            release.prepare(root, '0.1.0', '2026-11-10')
            changelog = (root / 'CHANGELOG.md').read_text()
            self.assertEqual(release.notes(changelog, '0.1.0'), '### Added\n\n- First.')
            self.assertIn('## [Unreleased]\n\n## [0.1.0] - 2026-11-10\n', changelog)
            self.assertEqual((root / 'pv-wpms-sso.php').read_text(), HEADER)
            with self.assertRaises(ValueError):
                release.prepare(root, '0.1.0', '2026-11-10')

    def test_prepare_bumps_only_the_header_version(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            project(root, '## [Unreleased]\n\n- Next.\n\n## [0.1.0] - 2026-11-10\n\n- First.\n')
            release.prepare(root, '0.2.0', '2026-12-01')
            header = (root / 'pv-wpms-sso.php').read_text()
            self.assertIn(' * Version: 0.2.0\n', header)
            self.assertIn("'0.1.0' );", header)
            self.assertEqual(release.notes((root / 'CHANGELOG.md').read_text(), '0.1.0'), '- First.')

    def test_invalid_requests_write_nothing(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            empty = '## [Unreleased]\n\n## [0.1.0] - 2026-11-10\n\n- First.\n'
            project(root, empty)
            for version in ('0.2.0', '0.0.9', '1.0', 'v1.0.0'):
                with self.assertRaises(ValueError):
                    release.prepare(root, version, '2026-12-01')
            project(root, '## [Unreleased]\n\n- Next.\n')
            with self.assertRaises(ValueError):
                release.prepare(root, '0.0.9', '2026-12-01')
            self.assertEqual((root / 'CHANGELOG.md').read_text(), '## [Unreleased]\n\n- Next.\n')
            self.assertEqual((root / 'pv-wpms-sso.php').read_text(), HEADER)


if __name__ == '__main__':
    unittest.main()
