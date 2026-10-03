#!/usr/bin/env node
// Copies the version from package.json into the plugin files. Run automatically by `npm version patch|minor|major`
// (the "version" script), after package.json is bumped and before npm commits and tags.
//
// Updates:
//   woocommerce-checkout-tracker.php  " * Version: x.y.z" header and define( 'WCT_VERSION', 'x.y.z' )
//   readme.txt                        "Version: x.y.z" and the changelog heading: a "= Unreleased =" section is
//                                     renamed to "= x.y.z =", otherwise an empty "= x.y.z =" section is added.
'use strict';
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const version = require(path.join(root, 'package.json')).version;
if (!/^\d+\.\d+\.\d+$/.test(version)) fail(`package.json version "${version}" is not x.y.z`);

function update(file, edits) {
  const full = path.join(root, file);
  let text = fs.readFileSync(full, 'utf8');
  for (const [label, pattern, replacement] of edits) {
    if (!pattern.test(text)) fail(`${file}: could not find ${label}`);
    text = text.replace(pattern, replacement);
  }
  fs.writeFileSync(full, text);
  console.log(`  ${file} -> ${version}`);
}
function fail(message) {
  console.error(`sync-version: ${message}`);
  process.exit(1);
}

update('woocommerce-checkout-tracker.php', [
  ['the "Version:" header', /^(\s*\*\s*Version:\s*)\S+/m, `$1${version}`],
  ['define( \'WCT_VERSION\', ... )', /(define\(\s*'WCT_VERSION',\s*')[^']*(')/, `$1${version}$2`],
]);

const readme = path.join(root, 'readme.txt');
let text = fs.readFileSync(readme, 'utf8');
if (!/^Version:\s*\S+/m.test(text)) fail('readme.txt: could not find "Version:"');
text = text.replace(/^(Version:\s*)\S+/m, `$1${version}`);
const heading = `= ${version} =`;
if (/^=\s*Unreleased\s*=\s*$/im.test(text)) {
  text = text.replace(/^=\s*Unreleased\s*=\s*$/im, heading);
} else if (!text.includes(heading)) {
  // Put the new section above the newest existing changelog entry.
  const first = text.search(/^={1,2} \d+\.\d+\.\d+ ={1,2}\s*$/m);
  if (first === -1) fail('readme.txt: no changelog heading found');
  text = `${text.slice(0, first)}${heading}\n* \n\n${text.slice(first)}`;
  console.log(`  readme.txt: added an empty "${heading}" changelog section, fill it in.`);
}
fs.writeFileSync(readme, text);
console.log(`  readme.txt -> ${version}`);
