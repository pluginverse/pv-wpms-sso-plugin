#!/usr/bin/env python3
"""Prepare version metadata and extract release notes from CHANGELOG.md."""
import argparse
import datetime
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PLUGIN_FILE = 'pv-wpms-sso.php'
VERSION = re.compile(r'(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)\Z')
HEADER_VERSION = re.compile(r'^(\s*\*\s*Version:\s*)(\S+)(\s*)$', re.M)


def notes(changelog, version):
    match = re.search(r'^## \[' + re.escape(version) + r'\] - \d{4}-\d{2}-\d{2}\s*\n(.*?)(?=^## \[|\Z)', changelog, re.M | re.S)
    if not match or not match.group(1).strip():
        raise ValueError(f'No changelog notes for {version}')
    return match.group(1).strip()


def header_version(header):
    matches = HEADER_VERSION.findall(header)
    if len(matches) != 1 or not VERSION.fullmatch(matches[0][1]):
        raise ValueError('Expected exactly one numeric X.Y.Z plugin header version')
    return matches[0][1]


def prepare(root, version, date):
    if not VERSION.fullmatch(version):
        raise ValueError('Version must be numeric X.Y.Z')
    datetime.date.fromisoformat(date)
    changelog = (root / 'CHANGELOG.md').read_text()
    match = re.search(r'^## \[Unreleased\]\s*\n(.*?)(?=^## \[|\Z)', changelog, re.M | re.S)
    if not match or not match.group(1).strip():
        raise ValueError('Unreleased changelog must contain release notes')
    if re.search(r'^## \[' + re.escape(version) + r'\]', changelog, re.M):
        raise ValueError('Version already exists in changelog')
    header = (root / PLUGIN_FILE).read_text()
    current = header_version(header)
    # Equal is allowed once: the header may name a version that was never released.
    if tuple(map(int, version.split('.'))) < tuple(map(int, current.split('.'))):
        raise ValueError('New version must not be lower than the plugin header version')
    header = HEADER_VERSION.sub(lambda found: found.group(1) + version + found.group(3), header)
    body = match.group(1).strip()
    updated = changelog[:match.start()] + '## [Unreleased]\n\n## [' + version + '] - ' + date + '\n\n' + body + '\n\n' + changelog[match.end():]
    (root / 'CHANGELOG.md').write_text(updated.rstrip('\n') + '\n')
    (root / PLUGIN_FILE).write_text(header)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest='command', required=True)
    prep = sub.add_parser('prepare')
    prep.add_argument('version')
    prep.add_argument('--date', default=datetime.date.today().isoformat())
    extract = sub.add_parser('notes')
    extract.add_argument('version')
    args = parser.parse_args()
    try:
        if args.command == 'prepare':
            prepare(ROOT, args.version, args.date)
            print(f'Prepared {args.version}; review, test, commit, and tag the release.')
        else:
            print(notes((ROOT / 'CHANGELOG.md').read_text(), args.version.removeprefix('v')))
    except (OSError, ValueError, KeyError) as error:
        print(f'Release error: {error}', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
