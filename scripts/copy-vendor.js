// Copies the browser builds of the chart libraries from node_modules/ into public/assets/vendor/
// so the PHP server can serve them. Runs automatically after `npm install`.
// Any file that is missing falls back to the CDN at page load.
const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..');
const out = path.join(root, 'public', 'assets', 'vendor');

const files = [
  ['chart.umd.js', ['chart.js/dist/chart.umd.min.js', 'chart.js/dist/chart.umd.js']],
  ['hammer.min.js', ['hammerjs/hammer.min.js']],
  ['chartjs-plugin-zoom.min.js', ['chartjs-plugin-zoom/dist/chartjs-plugin-zoom.min.js']],
  ['chartjs-adapter-date-fns.bundle.min.js', ['chartjs-adapter-date-fns/dist/chartjs-adapter-date-fns.bundle.min.js']],
];

fs.mkdirSync(out, { recursive: true });
for (const [name, candidates] of files) {
  const src = candidates.map((c) => path.join(root, 'node_modules', c)).find((p) => fs.existsSync(p));
  if (!src) {
    console.warn(`!  ${name}: not found in node_modules — the CDN copy will be used`);
    continue;
  }
  fs.copyFileSync(src, path.join(out, name));
  console.log(`✓  public/assets/vendor/${name}`);
}
