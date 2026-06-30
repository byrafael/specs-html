#!/usr/bin/env node
import { readFileSync, existsSync } from 'node:fs';
import { homedir } from 'node:os';
import { resolve } from 'node:path';

const args = process.argv.slice(2);

if (!args.length || args[0] === '--help' || args[0] === '-h') {
  console.log(`Usage: specs-html <file.html> [--name my-plan]

Config (env vars or ~/.specs-html):
  SPECS_HTML_URL    Server URL  (e.g. https://example.com/specs/)
  SPECS_HTML_TOKEN  Auth token

~/.specs-html format:
  URL=https://example.com/specs/
  TOKEN=your-secret-token`);
  process.exit(args.length ? 0 : 1);
}

// --- load config ---
let url = process.env.SPECS_HTML_URL ?? '';
let token = process.env.SPECS_HTML_TOKEN ?? '';

const rcPath = resolve(homedir(), '.specs-html');
if (existsSync(rcPath)) {
  for (const line of readFileSync(rcPath, 'utf8').split('\n')) {
    const [key, ...rest] = line.trim().split('=');
    const val = rest.join('=').trim();
    if (key === 'URL' && !url) url = val;
    if (key === 'TOKEN' && !token) token = val;
  }
}

if (!url) {
  console.error('error: SPECS_HTML_URL not set (env var or ~/.specs-html URL=...)');
  process.exit(1);
}
if (!token) {
  console.error('error: SPECS_HTML_TOKEN not set (env var or ~/.specs-html TOKEN=...)');
  process.exit(1);
}

// --- parse args ---
const filePath = resolve(args[0]);
let nameParam = '';
const nameIdx = args.indexOf('--name');
if (nameIdx !== -1 && args[nameIdx + 1]) {
  nameParam = `?name=${encodeURIComponent(args[nameIdx + 1])}`;
}

// --- upload ---
let body;
try {
  body = readFileSync(filePath);
} catch {
  console.error(`error: cannot read file: ${filePath}`);
  process.exit(1);
}

const uploadUrl = url.replace(/\/?$/, '/') + nameParam;

let res;
try {
  res = await fetch(uploadUrl, {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'text/html',
    },
    body,
    redirect: 'follow',
  });
} catch (err) {
  console.error(`error: upload failed: ${err.message}`);
  process.exit(1);
}

const text = (await res.text()).trim();

if (!res.ok) {
  console.error(`error: server returned ${res.status}\n${text}`);
  process.exit(1);
}

console.log(text);
