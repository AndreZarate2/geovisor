import { mkdir, writeFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { isIP } from 'node:net';

export function createConfig(value, deploymentHosts = []) {
  if (!value?.trim()) {
    throw new Error('Configura GEOVISOR_ORIGIN con la URL HTTPS del servidor PHP/Python antes de desplegar.');
  }
  const origin = new URL(value.trim());
  if (origin.protocol !== 'https:' || origin.username || origin.password ||
      origin.search || origin.hash || origin.pathname !== '/' ||
      (origin.port && origin.port !== '443')) {
    throw new Error('GEOVISOR_ORIGIN debe ser un origen HTTPS sin credenciales, ruta, consulta ni puerto alternativo.');
  }
  if (!origin.hostname.includes('.') || isIP(origin.hostname.replace(/\.$/, '')) ||
      origin.hostname.includes(':') || /\.(local|localhost|internal)\.?$/i.test(origin.hostname)) {
    throw new Error('El servidor de origen debe tener un nombre HTTPS accesible desde Internet.');
  }
  if (deploymentHosts.filter(Boolean).some(host => host.toLowerCase() === origin.host.toLowerCase())) {
    throw new Error('El origen no puede ser el propio dominio de Vercel: produciria un bucle de solicitudes.');
  }
  return {
    version: 3,
    routes: [
      {
        src: '^/.*$',
        headers: {
          'Cache-Control': 'private, no-store, max-age=0',
          'CDN-Cache-Control': 'no-store',
          'Vercel-CDN-Cache-Control': 'no-store',
          'x-vercel-enable-rewrite-caching': '0',
          'X-Content-Type-Options': 'nosniff',
        },
        continue: true,
      },
      {
        src: '^/(?:[^/]+/)*\\.[^/]*(?:/.*)?$',
        status: 404,
      },
      {
        src: '^/(?:data|uploads|cache|analysis|includes|scripts|certs|deploy)(?:/.*)?$',
        caseSensitive: false,
        status: 404,
      },
      {
        src: '^/(?:.*\\.(?:py|ps1|bat|cmd|sh|ini|log|sql|sqlite|db|json|bak|old)|config\\.php)$',
        caseSensitive: false,
        status: 404,
      },
      { src: '^/(.*)$', dest: `${origin.origin}/$1` },
    ],
  };
}

async function build() {
  const config = createConfig(process.env.GEOVISOR_ORIGIN, [
    process.env.VERCEL_URL,
    process.env.VERCEL_PROJECT_PRODUCTION_URL,
  ]);
  const output = join(dirname(fileURLToPath(import.meta.url)), '.vercel', 'output');
  await mkdir(join(output, 'static'), { recursive: true });
  await writeFile(join(output, 'config.json'), JSON.stringify(config, null, 2) + '\n');
  console.log('Acceso de Vercel preparado. PHP, Python y los documentos permanecen en el servidor de origen.');
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  await build();
}
