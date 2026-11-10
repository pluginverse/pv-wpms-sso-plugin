#!/usr/bin/env python3
"""Create or verify the exact installable release archive."""
import argparse
import json
import re
import sys
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SLUG = 'pv-wpms-sso'
VERSION = re.compile(r'(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\Z')
TOP_LEVEL_FILES = (SLUG + '.php', 'uninstall.php', 'LICENSE.txt', 'README.md', 'CHANGELOG.md', 'composer.json')
DIRECTORIES = ('src', 'assets', 'vendor')


def header_version(root):
    matches = re.findall(r'^\s*\*\s*Version:\s*(\S+)\s*$', (root / (SLUG + '.php')).read_text(), re.M)
    if len(matches) != 1 or not VERSION.fullmatch(matches[0]):
        raise ValueError('Expected exactly one numeric X.Y.Z plugin header version')
    return matches[0]


def require_production_vendor(root):
    """The archive bundles vendor/: it must be a --no-dev Composer install."""
    installed = root / 'vendor' / 'composer' / 'installed.json'
    if not (root / 'vendor' / 'autoload.php').is_file() or not installed.is_file():
        raise ValueError('vendor/ is missing; run composer install --no-dev --optimize-autoloader')
    if json.loads(installed.read_text()).get('dev') is not False:
        raise ValueError('vendor/ holds development packages; run composer install --no-dev --optimize-autoloader')


def files_and_version(root, tag=None):
    version = header_version(root)
    if tag is not None and tag != f'v{version}':
        raise ValueError(f'Tag must match v{version}')
    require_production_vendor(root)
    files = [root / name for name in TOP_LEVEL_FILES]
    for name in DIRECTORIES:
        directory = root / name
        files.extend(
            path for path in directory.rglob('*')
            if path.is_file() and not any(part.startswith('.') for part in path.relative_to(directory).parts)
        )
    if any(not path.is_file() for path in files) or not any((root / 'src').glob('*.php')):
        raise ValueError('Required release files are missing')
    return version, {f'{SLUG}/{path.relative_to(root).as_posix()}': path.read_bytes() for path in sorted(files)}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--tag', help='Validate release tag, e.g. v1.0.0')
    parser.add_argument('--verify', type=Path, help='Verify an existing archive against this source tree')
    parser.add_argument('--output', type=Path, default=ROOT / 'dist' / (SLUG + '.zip'))
    args = parser.parse_args()
    try:
        version, files = files_and_version(ROOT, args.tag)
        if args.verify:
            with zipfile.ZipFile(args.verify) as archive:
                names = archive.namelist()
                if len(names) != len(set(names)) or set(names) != set(files):
                    raise ValueError('Archive contents differ from release files')
                if any(archive.read(name) != content for name, content in files.items()):
                    raise ValueError('Archive content differs from this checkout')
            print(f'Verified {args.verify} ({version}, {len(files)} files)')
        else:
            args.output.parent.mkdir(parents=True, exist_ok=True)
            with zipfile.ZipFile(args.output, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
                for name, content in files.items():
                    entry = zipfile.ZipInfo(name, (1980, 1, 1, 0, 0, 0))
                    entry.compress_type = zipfile.ZIP_DEFLATED
                    entry.external_attr = 0o100644 << 16
                    archive.writestr(entry, content, compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
            print(f'Created {args.output} ({version}, {len(files)} files)')
    except (OSError, ValueError, KeyError, zipfile.BadZipFile) as error:
        print(f'Release validation failed: {error}', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
