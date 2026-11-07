#!/usr/bin/env node
/**
 * Lists English texts in src/ that have no Kiswahili in src/i18n/sw.ts.
 * Finds t('…') calls, `label: '…'` entries and the label maps that are
 * translated at the point of use (TYPE, TRIGGER, DESCRIPTION, METHOD).
 *
 *   npm run i18n:check           → summary, exit code 1 when something is missing
 *   npm run i18n:check -- --json → the missing texts as JSON (to translate)
 *   npm run i18n:check -- --unused → also list sw.ts entries no longer used
 */
const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..');
const files = [];
(function walk(dir) {
  for (const name of fs.readdirSync(dir)) {
    const p = path.join(dir, name);
    if (fs.statSync(p).isDirectory()) walk(p);
    else if (/\.tsx?$/.test(name) && !/\.test\.tsx?$/.test(name) && !p.endsWith(path.join('i18n', 'sw.ts'))) files.push(p);
  }
})(path.join(root, 'src'));

const unescape = (s) => s.replace(/\\(['"\\])/g, '$1');
const STRING = String.raw`(['"])((?:\\.|(?!\1).)*)\1`;
const used = new Set();
for (const file of files) {
  const src = fs.readFileSync(file, 'utf8');
  for (const m of src.matchAll(new RegExp(String.raw`\bt\(\s*` + STRING, 'g'))) used.add(unescape(m[2]));
  for (const m of src.matchAll(new RegExp(String.raw`\blabel:\s*` + STRING, 'g'))) used.add(unescape(m[2]));
  for (const block of src.matchAll(/const (?:TYPE|TRIGGER|DESCRIPTION|METHOD)\b[^=]*=\s*\{([\s\S]*?)\};/g)) {
    for (const m of block[1].matchAll(new RegExp(String.raw`:\s*` + STRING, 'g'))) used.add(unescape(m[2]));
  }
}
// Language names are shown in their own language.
['English', 'Kiswahili'].forEach((k) => used.delete(k));

const sw = fs.readFileSync(path.join(root, 'src/i18n/sw.ts'), 'utf8');
const keys = new Set();
for (const m of sw.matchAll(new RegExp(String.raw`^\s*` + STRING + String.raw`\s*:`, 'gm'))) keys.add(unescape(m[2]));

const missing = [...used].filter((k) => !keys.has(k)).sort();
const args = process.argv.slice(2);
if (args.includes('--json')) {
  console.log(JSON.stringify(missing, null, 1));
} else {
  console.log(`${used.size} texts, ${keys.size} in Kiswahili, ${missing.length} missing.`);
  missing.forEach((k) => console.log(`  - ${k}`));
  if (args.includes('--unused')) {
    const unused = [...keys].filter((k) => !used.has(k));
    console.log(`${unused.length} Kiswahili entries no longer used.`);
    unused.forEach((k) => console.log(`  ~ ${k}`));
  }
}
process.exit(missing.length && !args.includes('--json') ? 1 : 0);
