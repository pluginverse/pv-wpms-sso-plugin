#!/usr/bin/env bash
# Publish one released archive and its tagged listing artwork to WordPress.org SVN.
set -euo pipefail
: "${RELEASE_TAG:?Missing release tag}" "${SVN_USERNAME:?Missing SVN_USERNAME}" "${SVN_PASSWORD:?Missing SVN_PASSWORD}"
[[ "$RELEASE_TAG" =~ ^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$ ]] || { echo "Invalid release tag" >&2; exit 1; }
version=${RELEASE_TAG#v}
slug=pv-wpms-sso
repo=https://plugins.svn.wordpress.org/$slug
archive=dist/$slug.zip
test -f "$archive" || { echo "Missing release archive: $archive" >&2; exit 1; }
for file in banner-772x250.png banner-1544x500.png icon-128x128.png icon-256x256.png screenshot-1.png screenshot-2.png screenshot-3.png; do
  test -f ".wordpress-org/$file" || { echo "Missing tagged artwork: $file" >&2; exit 1; }
done
workdir=$(mktemp -d)
trap 'rm -rf "$workdir"' EXIT
unzip -q "$archive" -d "$workdir/archive"
test -f "$workdir/archive/$slug/$slug.php" || { echo "Archive has no $slug/$slug.php" >&2; exit 1; }
grep -Eq "^ \* Version: ${version}\s*$" "$workdir/archive/$slug/$slug.php" || { echo "Archive version differs from $RELEASE_TAG" >&2; exit 1; }
grep -Eq "^Stable tag: ${version}\s*$" "$workdir/archive/$slug/readme.txt" || { echo "Archive stable tag differs from $RELEASE_TAG" >&2; exit 1; }
# --no-auth-cache prevents saving credentials on the runner.
svn_auth=(--non-interactive --no-auth-cache --username "$SVN_USERNAME" --password "$SVN_PASSWORD")
if ! svn checkout "$repo" "$workdir/svn" "${svn_auth[@]}" --quiet; then
  echo "Cannot access the assigned $slug SVN repository; confirm WordPress.org approval and credentials." >&2
  exit 1
fi
if [ -e "$workdir/svn/tags/$version" ]; then
  echo "SVN tag $version already exists; refusing to overwrite it." >&2
  exit 1
fi
rsync -a --delete --exclude=.svn/ "$workdir/archive/$slug/" "$workdir/svn/trunk/"
mkdir -p "$workdir/svn/assets"
rsync -a --delete --exclude=.svn/ .wordpress-org/ "$workdir/svn/assets/"
cd "$workdir/svn"
svn add --force trunk assets --quiet
svn status | awk '/^!/ {print substr($0,9)}' | while IFS= read -r path; do svn delete "$path" --quiet; done
svn copy trunk "tags/$version" --quiet
svn commit -m "Release $version" "${svn_auth[@]}"
