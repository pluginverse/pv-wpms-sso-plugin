#!/usr/bin/env python3
"""Trace behavior IDs from Gherkin scenarios to the tests that reference them.

Scans .feature files for ID tags on scenarios (for example @GL-001) and scans
test sources for the same IDs, then prints a matrix of ID -> scenario ->
supporting tests grouped by level (e2e, integration, unit, other).

Reports, as errors:
  duplicate   one ID tagged on more than one scenario
  orphan      an ID referenced in tests but tagged on no scenario
Reports, as warnings:
  untagged    a scenario with no ID tag
  multi       a scenario with more than one ID tag
  unsupported an ID with no test outside the feature's own step definitions
              (informational: many behaviors are fine with E2E only)

Exit codes: 0 clean, 1 errors found (or warnings with --strict), 2 bad usage.
Standard library only.
"""

from __future__ import annotations

# Source: spenserhale/skills, skills/bdd-gherkin/scripts/trace_ids.py (MIT)
# Reference commit: 2d9767c4cc85f35078a6abc84149f52de21b1e4f
import argparse
import json
import os
import re
import sys
from collections import defaultdict
from pathlib import Path

DEFAULT_PATTERN = r"[A-Z][A-Z0-9]{1,9}-\d{2,}"
SKIP_DIRS = {
    ".git", "node_modules", ".venv", "venv", "env", "__pycache__", "dist",
    "build", "bin", "obj", "target", ".tox", ".pytest_cache", "coverage",
    ".next", ".turbo", "vendor",
}
TEST_EXTS = {
    ".py", ".js", ".mjs", ".cjs", ".ts", ".tsx", ".jsx", ".cs", ".java",
    ".kt", ".go", ".rb", ".php", ".rs", ".swift",
}
TEST_NAME_HINT = re.compile(
    r"(^test_|_test\.|\.test\.|\.spec\.|tests?\.(cs|java|kt)$|Tests?\.(cs|java|kt)$|_spec\.rb$|Test\.php$)",
    re.IGNORECASE,
)
TEST_DIR_HINT = {"test", "tests", "spec", "specs", "__tests__", "e2e", "integration", "unit", "features", "steps", "step_definitions", "acceptance"}
SCENARIO_RE = re.compile(r"^\s*(Scenario Outline|Scenario Template|Scenario|Example)\s*:\s*(.*)$")
FEATURE_RE = re.compile(r"^\s*Feature\s*:\s*(.*)$")
TAG_LINE_RE = re.compile(r"^\s*@")


def level_of(path: Path) -> str:
    parts = {p.lower() for p in path.parts}
    name = path.name.lower()
    if parts & {"e2e", "browser", "acceptance", "features", "step_definitions", "steps", "playwright", "cypress"} or ".e2e." in name:
        return "e2e"
    if parts & {"integration", "it"} or ".integration." in name or "integration" in name:
        return "integration"
    if "unit" in parts or ".unit." in name:
        return "unit"
    return "other"


def walk(roots: list[Path]):
    for root in roots:
        if root.is_file():
            yield root
            continue
        for dirpath, dirnames, filenames in os.walk(root):
            dirnames[:] = [d for d in dirnames if d not in SKIP_DIRS and not d.startswith(".")]
            for f in filenames:
                yield Path(dirpath) / f


def parse_features(paths, id_re):
    scenarios = []
    for path in paths:
        try:
            lines = path.read_text(encoding="utf-8").splitlines()
        except (OSError, UnicodeDecodeError) as exc:
            print(f"warning: cannot read {path}: {exc}", file=sys.stderr)
            continue
        feature = ""
        pending_tags: list[str] = []
        for lineno, line in enumerate(lines, 1):
            stripped = line.strip()
            if stripped.startswith("#") or not stripped:
                continue
            if TAG_LINE_RE.match(line):
                pending_tags += [t for t in stripped.split() if t.startswith("@")]
                continue
            m = FEATURE_RE.match(line)
            if m:
                feature = m.group(1).strip()
                pending_tags = []
                continue
            m = SCENARIO_RE.match(line)
            if m:
                ids = [t[1:] for t in pending_tags if id_re.fullmatch(t[1:])]
                scenarios.append({
                    "file": str(path), "line": lineno, "feature": feature,
                    "name": m.group(2).strip(), "ids": ids,
                })
                pending_tags = []
                continue
            # Rule:, Background:, Examples:, steps, tables reset dangling tags
            pending_tags = []
    return scenarios


def scan_tests(paths, id_re):
    refs = defaultdict(set)  # id -> {(file, level)}
    search = re.compile(r"(?<![A-Za-z0-9])(" + id_re.pattern + r")(?![0-9])")
    for path in paths:
        try:
            text = path.read_text(encoding="utf-8")
        except (OSError, UnicodeDecodeError):
            continue
        for m in search.finditer(text):
            refs[m.group(1)].add((str(path), level_of(path)))
    return refs


def is_test_file(path: Path) -> bool:
    if path.suffix not in TEST_EXTS:
        return False
    if TEST_NAME_HINT.search(path.name):
        return True
    return bool({p.lower() for p in path.parts[:-1]} & TEST_DIR_HINT)


def main() -> int:
    ap = argparse.ArgumentParser(
        description="Trace behavior IDs from Gherkin scenarios to tests.",
        epilog="Example: trace_ids.py --features features --tests tests src --format md",
    )
    ap.add_argument("--root", default=".", help="repo root used when --features/--tests are omitted (default: .)")
    ap.add_argument("--features", nargs="*", help="feature files or dirs (default: every *.feature under --root)")
    ap.add_argument("--tests", nargs="*", help="test files or dirs (default: test-looking files under --root)")
    ap.add_argument("--pattern", default=DEFAULT_PATTERN, help=f"ID regex without @ (default: {DEFAULT_PATTERN})")
    ap.add_argument("--id", action="append", default=[], help="only report these IDs (repeatable)")
    ap.add_argument("--format", choices=["md", "json"], default="md", help="output format (default: md)")
    ap.add_argument("--strict", action="store_true", help="exit 1 on warnings too")
    args = ap.parse_args()

    try:
        id_re = re.compile(args.pattern)
    except re.error as exc:
        print(f"error: bad --pattern: {exc}", file=sys.stderr)
        return 2

    root = Path(args.root)
    if not root.exists():
        print(f"error: --root {root} does not exist", file=sys.stderr)
        return 2
    feat_roots = [Path(p) for p in args.features] if args.features else [root]
    test_roots = [Path(p) for p in args.tests] if args.tests else [root]
    for p in feat_roots + test_roots:
        if not p.exists():
            print(f"error: path {p} does not exist", file=sys.stderr)
            return 2

    feature_files = sorted({p for p in walk(feat_roots) if p.suffix == ".feature"})
    if args.tests:
        test_files = sorted({p for p in walk(test_roots) if p.suffix in TEST_EXTS})
    else:
        test_files = sorted({p for p in walk(test_roots) if is_test_file(p)})

    scenarios = parse_features(feature_files, id_re)
    refs = scan_tests(test_files, id_re)

    by_id = defaultdict(list)
    for s in scenarios:
        for i in s["ids"]:
            by_id[i].append(s)

    errors, warnings = [], []
    for i, ss in sorted(by_id.items()):
        if len(ss) > 1:
            where = ", ".join(f"{s['file']}:{s['line']}" for s in ss)
            errors.append({"kind": "duplicate", "id": i, "detail": f"tagged on {len(ss)} scenarios: {where}"})
    for i in sorted(set(refs) - set(by_id)):
        where = ", ".join(sorted(f for f, _ in refs[i]))
        errors.append({"kind": "orphan", "id": i, "detail": f"referenced in tests but on no scenario: {where}"})
    for s in scenarios:
        if not s["ids"]:
            warnings.append({"kind": "untagged", "id": None, "detail": f"{s['file']}:{s['line']} {s['name']}"})
        elif len(s["ids"]) > 1:
            warnings.append({"kind": "multi", "id": ",".join(s["ids"]), "detail": f"{s['file']}:{s['line']} {s['name']}"})
    matrix = []
    for i in sorted(by_id):
        levels = defaultdict(list)
        for f, lvl in sorted(refs.get(i, ())):
            levels[lvl].append(f)
        lower = {k: v for k, v in levels.items() if k != "e2e"}
        if not lower:
            warnings.append({"kind": "unsupported", "id": i, "detail": "no integration/unit/other test references this ID"})
        s = by_id[i][0]
        matrix.append({"id": i, "scenario": s["name"], "feature": s["feature"],
                       "location": f"{s['file']}:{s['line']}", "tests": dict(levels)})

    if args.id:
        wanted = set(args.id)
        matrix = [m for m in matrix if m["id"] in wanted]
        errors = [e for e in errors if e["id"] in wanted]
        warnings = [w for w in warnings if w["id"] in wanted]

    summary = {"features": len(feature_files), "scenarios": len(scenarios),
               "ids": len(by_id), "test_files_scanned": len(test_files)}
    if args.format == "json":
        print(json.dumps({"summary": summary, "matrix": matrix, "errors": errors, "warnings": warnings}, indent=2))
    else:
        print(f"# Behavior trace\n\n{summary['features']} feature files, {summary['scenarios']} scenarios, "
              f"{summary['ids']} IDs, {summary['test_files_scanned']} test files scanned.\n")
        for m in matrix:
            print(f"## {m['id']}: {m['scenario']}\n\n- Scenario: {m['location']} ({m['feature']})")
            for lvl in ("e2e", "integration", "unit", "other"):
                for f in m["tests"].get(lvl, []):
                    print(f"- {lvl.capitalize()}: {f}")
            print()
        for title, items in (("Errors", errors), ("Warnings", warnings)):
            if items:
                print(f"## {title}\n")
                for it in items:
                    label = f" {it['id']}" if it["id"] else ""
                    print(f"- {it['kind']}{label}: {it['detail']}")
                print()
        print("Note: IDs record claimed relationships; they do not prove coverage.")

    if errors or (args.strict and warnings):
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
