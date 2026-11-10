// Renders the WordPress.org listing artwork; see docs/guides/listing.md.
const path = require('node:path');
const base = require('../playwright.config.cjs');
module.exports = { ...base, testDir: __dirname, testMatch: 'capture.listing.cjs', outputDir: path.resolve(__dirname, '../../../test-results') };
