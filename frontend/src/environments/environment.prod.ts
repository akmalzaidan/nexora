/*
 * Production environment.
 *
 * GENERATED FILE - `scripts/set-api-url.mjs` rewrites the `apiUrl` value below
 * from the `NEXORA_API_URL` build variable (set it in the Cloudflare Pages
 * project settings). The committed value below is what the script writes when
 * `NEXORA_API_URL` is unset, so a local `npm run build` leaves this file
 * byte-identical and Git stays clean.
 *
 * The default is the relative `/api/v1`, which resolves against whatever
 * origin serves the bundle. That keeps `localhost`, `127.0.0.1` and `:8000`
 * out of the production bundle. Cloudflare Pages cannot proxy `/api/*` to
 * another host, so a split deployment MUST set `NEXORA_API_URL` to the
 * absolute API base URL, e.g. `https://nexora-api.onrender.com/api/v1`.
 */
export const environment = {
  production: true,
  apiUrl: '/api/v1',
};
