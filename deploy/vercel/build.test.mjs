import test from 'node:test';
import assert from 'node:assert/strict';
import { createConfig } from './build.mjs';

test('requiere un origen HTTPS con nombre publico y sin credenciales', () => {
  for (const value of [
    undefined, '', '  ', 'no-es-url', 'http://origin.example.com',
    'https://user:password@origin.example.com', 'https://origin.example.com/mapa',
    'https://origin.example.com?q=1', 'https://origin.example.com#section',
    'https://origin.example.com:8080', 'https://localhost', 'https://pc.local',
    'https://pc.internal.', 'https://127.0.0.1', 'https://10.0.0.1',
    'https://169.254.169.254', 'https://0.0.0.0', 'https://[::1]',
  ]) assert.throws(() => createConfig(value), `Debe rechazar ${value}`);
});

test('evita usar el propio despliegue como origen', () => {
  assert.throws(() => createConfig('https://geovisor.vercel.app', ['geovisor.vercel.app']));
  assert.throws(() => createConfig('https://GEOVISOR.vercel.app', ['geovisor.vercel.app']));
});

test('genera Build Output API v3 y evita cache compartida', () => {
  const config = createConfig(' https://origin.example.com/ ');
  assert.equal(config.version, 3);
  const policy = config.routes[0];
  assert.equal(policy.continue, true);
  assert.match(policy.headers['Cache-Control'], /no-store/);
  assert.equal(policy.headers['Vercel-CDN-Cache-Control'], 'no-store');
  assert.equal(policy.headers['x-vercel-enable-rewrite-caching'], '0');
  assert.equal(config.routes.at(-1).dest, 'https://origin.example.com/$1');
});

const routes = createConfig('https://origin.example.com').routes;
function finalRoute(path) {
  return routes.find(route => !route.continue &&
    new RegExp(route.src, route.caseSensitive === false ? 'i' : '').test(path));
}

test('protege rutas internas y archivos de configuracion', () => {
  for (const path of [
    '/.git/config', '/.env', '/assets/.private/key', '/data', '/data/file.geojson',
    '/uploads/excel/file.xlsx', '/cache/session', '/analysis/run.py',
    '/includes/auth.php', '/scripts/manage_users.php', '/certs/key.pem',
    '/deploy/vercel/build.mjs', '/config.php', '/CONFIG.PHP', '/dump.SQL',
    '/users.json', '/.vercel/output/config.json', '/foo/bar.ini',
  ]) assert.equal(finalRoute(path)?.status, 404, path);
});

test('conserva acceso al mapa, APIs, recursos e informes', () => {
  for (const path of [
    '/', '/index.php', '/login.php', '/upload.php', '/api/openai_chat.php',
    '/api/geojson.php', '/assets/js/app.js', '/assets/css/style.css',
    '/assets/img/logo.png', '/reports/example.pdf', '/reports/example.docx',
  ]) assert.equal(finalRoute(path)?.dest, 'https://origin.example.com/$1', path);
});
