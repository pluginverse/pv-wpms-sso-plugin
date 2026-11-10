---
title: WordPress.org listing
description: Maintain the directory artwork and reproduce the screenshots from the disposable browser environment.
---

## Files

Directory media lives in `.wordpress-org/` and is published to SVN's top-level `assets/`, outside the installed plugin archive: `icon-128x128.png`, `icon-256x256.png`, `banner-772x250.png`, `banner-1544x500.png`, and `screenshot-1.png` to `screenshot-3.png`. Screenshot numbers must match the captions under `== Screenshots ==` in `readme.txt`.

Vector sources are `assets/listing/icon.svg` and `assets/listing/banner.svg`. `assets/icon.svg` is the standalone symbol used by the README.

## Brand

The organization name is **Pluginverse**, one word. [pluginverse/branding](https://github.com/pluginverse/branding) is the source for the plug/orbit symbol; the copies here match the ones in PV Redirects. Keep the supplied geometry and proportions, use the violet `#4326F2` symbol on light surfaces and the white/lilac variant on Deep Space `#151127`, and do not retype or recolor parts of the mark.

## Regenerate

```sh
npm run browser:start
npm run browser:seed
npm run listing:capture
npm run browser:stop
```

`tests/Browser/listing/capture.listing.cjs` renders the icons and banners from the SVG sources at their exact sizes and captures the screenshots at 1280×860 from the real plugin:

1. A mapped site's login page right after signing out, showing "Continue as {name}" (and the SAML button the fixture configures).
2. Network Admin → Settings → SSO.
3. The external single sign-on (SAML) section of the same screen.

The capture removes one element from the login page: the "Second factor" field, which belongs to the test fixture and not to the plugin. Nothing else is altered. For a display name in the first screenshot, run `wp user update alex --display_name="Alex Rivera"` in the browser environment before capturing and re-seed afterwards. The screenshots show fixture domains (`*.test:9443`) and contain no real data; the fixture's network admin runs over plain HTTP, which is why some URLs in them say `http`.
