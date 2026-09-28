/*
 * Injects the production API base URL into `src/environments/environment.prod.ts`.
 *
 * Angular has no built-in build-time env mechanism (that is a Vite feature),
 * so the production environment file is generated instead of hand-maintained.
 * The single source of truth is the `NEXORA_API_URL` build variable, which is
 * configured in the Cloudflare Pages project settings.
 *
 * Behaviour:
 *   NEXORA_API_URL unset   -> writes the relative default `/api/v1`. A local
 *                             `npm run build` therefore leaves the file
 *                             byte-identical and Git stays clean.
 *   NEXORA_API_URL set     -> writes that absolute base URL.
 *   NEXORA_API_URL invalid -> fails the build rather than shipping a bundle
 *                             that silently points at the wrong origin.
 */
import { writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const DEFAULT_API_URL = '/api/v1';

const target = join(
  dirname(fileURLToPath(import.meta.url)),
  '..',
  'src',
  'environments',
  'environment.prod.ts',
);

const raw = (process.env.NEXORA_API_URL ?? '').trim();

function resolveApiUrl() {
  if (raw === '') {
    console.warn(
      `[set-api-url] NEXORA_API_URL is not set; using the same-origin default "${DEFAULT_API_URL}". ` +
        'Cloudflare Pages cannot proxy /api/* to another host, so set NEXORA_API_URL to the ' +
        'absolute API base URL (e.g. https://<service>.onrender.com/api/v1) before deploying.',
    );
    return DEFAULT_API_URL;
  }

  // A relative value is allowed, but an absolute one must be a real https URL:
  // the deployed API is always reached over TLS.
  let parsed;
  try {
    parsed = new URL(raw);
  } catch {
    throw new Error(
      `[set-api-url] NEXORA_API_URL is not a valid absolute URL: "${raw}". ` +
        'Expected something like https://<service>.onrender.com/api/v1',
    );
  }

  if (parsed.protocol !== 'https:') {
    throw new Error(
      `[set-api-url] NEXORA_API_URL must use https, got "${parsed.protocol}". ` +
        'The production API is only ever served over TLS.',
    );
  }

  // Trailing slashes would produce `//assets/1` once a path is appended.
  return raw.replace(/\/+$/, '');
}

const apiUrl = resolveApiUrl();

const contents = `/*
 * Production environment.
 *
 * GENERATED FILE - \`scripts/set-api-url.mjs\` rewrites the \`apiUrl\` value below
 * from the \`NEXORA_API_URL\` build variable (set it in the Cloudflare Pages
 * project settings). The committed value below is what the script writes when
 * \`NEXORA_API_URL\` is unset, so a local \`npm run build\` leaves this file
 * byte-identical and Git stays clean.
 *
 * The default is the relative \`/api/v1\`, which resolves against whatever
 * origin serves the bundle. That keeps \`localhost\`, \`127.0.0.1\` and \`:8000\`
 * out of the production bundle. Cloudflare Pages cannot proxy \`/api/*\` to
 * another host, so a split deployment MUST set \`NEXORA_API_URL\` to the
 * absolute API base URL, e.g. \`https://nexora-api.onrender.com/api/v1\`.
 */
export const environment = {
  production: true,
  apiUrl: '${apiUrl}',
};
`;

writeFileSync(target, contents, 'utf8');
console.log(`[set-api-url] production apiUrl set to "${apiUrl}"`);
