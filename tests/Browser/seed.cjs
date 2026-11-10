const { execFileSync } = require('node:child_process');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
execFileSync(path.join(root, 'node_modules/.bin/wp-env'), ['--config', 'tests/Browser/.wp-env.json', 'run', 'cli', 'wp', 'eval-file', 'wp-content/plugins/' + path.basename(root) + '/tests/Browser/seed.php'], {cwd: root, stdio: 'inherit'});
