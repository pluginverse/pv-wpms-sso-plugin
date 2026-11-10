import importlib.util
import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location('package', Path(__file__).resolve().parents[1] / 'package.py')
package = importlib.util.module_from_spec(spec)
spec.loader.exec_module(package)


def project(root, dev=False):
    files = {
        'pv-wpms-sso.php': '<?php\n/**\n * Version: 0.1.0\n */\n',
        'uninstall.php': '<?php\n',
        'readme.txt': '=== Plugin ===\nStable tag: 0.1.0\n',
        'LICENSE.txt': 'GPL\n',
        'README.md': '# Plugin\n',
        'CHANGELOG.md': '# Changelog\n',
        'composer.json': '{}\n',
        'src/Ticket.php': '<?php\n',
        'assets/icon.svg': '<svg/>\n',
        'vendor/autoload.php': '<?php\n',
        'vendor/composer/installed.json': json.dumps({'packages': [], 'dev': dev}),
        'vendor/onelogin/php-saml/src/Saml2/Auth.php': '<?php\n',
        'vendor/onelogin/php-saml/.github/workflow.yml': 'hidden\n',
        'tests/SecretTest.php': '<?php\n',
        'docs/plan.md': 'internal\n',
        'package.json': '{}\n',
        '.wp-env.json': '{}\n',
    }
    for name, content in files.items():
        path = root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(content)


class PackageTest(unittest.TestCase):
    def test_archive_holds_only_runtime_files_under_the_plugin_folder(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            project(root)
            version, files = package.files_and_version(root, 'v0.1.0')
            self.assertEqual(version, '0.1.0')
            self.assertEqual(sorted(files), [
                'pv-wpms-sso/CHANGELOG.md',
                'pv-wpms-sso/LICENSE.txt',
                'pv-wpms-sso/README.md',
                'pv-wpms-sso/composer.json',
                'pv-wpms-sso/pv-wpms-sso.php',
                'pv-wpms-sso/readme.txt',
                'pv-wpms-sso/src/Ticket.php',
                'pv-wpms-sso/uninstall.php',
                'pv-wpms-sso/vendor/autoload.php',
                'pv-wpms-sso/vendor/composer/installed.json',
                'pv-wpms-sso/vendor/onelogin/php-saml/src/Saml2/Auth.php',
            ])

    def test_tag_must_match_the_plugin_header(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            project(root)
            for tag in ('v0.1.1', '0.1.0', 'v0.1.0-rc1'):
                with self.assertRaises(ValueError):
                    package.files_and_version(root, tag)

    def test_stable_tag_must_match_the_plugin_header(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            project(root)
            (root / 'readme.txt').write_text('Stable tag: 0.0.9\n')
            with self.assertRaisesRegex(ValueError, 'stable tag'):
                package.files_and_version(root)

    def test_development_or_missing_vendor_is_refused(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            project(root, dev=True)
            with self.assertRaisesRegex(ValueError, 'development packages'):
                package.files_and_version(root)
            (root / 'vendor' / 'composer' / 'installed.json').unlink()
            with self.assertRaisesRegex(ValueError, 'vendor/ is missing'):
                package.files_and_version(root)

    def test_missing_runtime_file_is_refused(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            project(root)
            (root / 'uninstall.php').unlink()
            with self.assertRaises(ValueError):
                package.files_and_version(root)

    def test_verify_compares_bytes_except_composer_generated_files(self):
        script = Path(__file__).resolve().parents[1] / 'package.py'
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            project(root)
            (root / 'scripts').mkdir()
            copy = root / 'scripts' / 'package.py'
            copy.write_text(script.read_text())
            run = lambda *arguments: subprocess.run([sys.executable, str(copy), *arguments], capture_output=True, text=True)
            self.assertEqual(run().returncode, 0)
            archive = str(root / 'dist' / 'pv-wpms-sso.zip')
            self.assertEqual(run('--verify', archive).returncode, 0)
            (root / 'vendor' / 'composer' / 'installed.json').write_text('{"packages": [], "dev": false, "note": "regenerated"}')
            self.assertEqual(run('--verify', archive).returncode, 0)
            (root / 'src' / 'Ticket.php').write_text('<?php // changed\n')
            self.assertIn('differs', run('--verify', archive).stderr)
            (root / 'src' / 'Extra.php').write_text('<?php\n')
            self.assertIn('contents differ', run('--verify', archive).stderr)


if __name__ == '__main__':
    unittest.main()
