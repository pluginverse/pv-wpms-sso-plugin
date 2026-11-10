const https = require('node:https');
const http = require('node:http');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'pv-sso-tls-'));
const key = path.join(directory, 'key.pem');
const cert = path.join(directory, 'cert.pem');
execFileSync('openssl', ['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', key, '-out', cert, '-days', '1', '-subj', '/CN=localhost'], {stdio: 'ignore'});
const origins = new Set(['auth.test:9443', 'northcoast.test:9443', 'southmountain.test:9443', 'coastextra.test:9443', 'ridgeview.test:9443', 'onelogin.test:9443']);
const server = https.createServer({key: fs.readFileSync(key), cert: fs.readFileSync(cert)}, (request, response) => {
  if (request.url === '/health') { response.end('ready'); return; }
  if (!origins.has(request.headers.host)) { response.writeHead(400); response.end('Unknown test origin'); return; }
  const upstream = http.request({hostname: '127.0.0.1', port: 8894, path: request.url, method: request.method, headers: {...request.headers, 'x-forwarded-proto': 'https'}}, incoming => {
    response.writeHead(incoming.statusCode, incoming.headers);
    incoming.pipe(response);
  });
  upstream.on('error', () => {response.writeHead(502); response.end('Test WordPress unavailable');});
  request.pipe(upstream);
});
server.listen(9443, '127.0.0.1');
for (const signal of ['SIGTERM', 'SIGINT']) process.on(signal, () => server.close(() => {fs.rmSync(directory, {recursive:true, force:true}); process.exit(0);}));
