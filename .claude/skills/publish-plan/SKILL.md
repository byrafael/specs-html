---
name: publish-plan
description: Turn an implementation plan into a polished, self-contained HTML page and publish it to the self-hosted main.php endpoint via curl, returning a shareable URL. Use when the user wants to share/publish a plan as a web page — e.g. "publish the plan", "post this plan", "turn the plan into a page", "share this as a link".
---

# Publish Plan

Render the current implementation plan as a single well-designed HTML file, then
`curl` POST it to the self-hosted `main.php` endpoint and hand back the URL.

## 1. Get the plan content

Use, in order of preference:

1. A file path passed as an argument → read it (`plan.md`, a spec, etc.).
2. Plain text passed as an argument → use it as the plan (or as a title hint).
3. Otherwise → use the implementation plan from the current conversation (the
   plan you just produced or discussed).

If there is no plan in any of these, ask the user what to publish — do not invent one.

## 2. Load config (endpoint + token)

The endpoint URL and upload token are deployment-specific. Resolve them from the
environment, falling back to a local gitignored `config` file:

```bash
set -a
[ -f .claude/skills/publish-plan/config ] && . .claude/skills/publish-plan/config
set +a
: "${SPECS_URL:?Missing SPECS_URL — copy config.example to config and fill it in, or export it}"
: "${SPECS_TOKEN:?Missing SPECS_TOKEN — set it in config or the environment}"
```

If either is missing, stop and tell the user to create
`.claude/skills/publish-plan/config` from `config.example` (or export the vars).
Never print the token back to the user or commit it.

## 3. Generate the HTML

Design the page yourself — make it genuinely good. Translate the plan's structure
into a clear visual hierarchy (title, overview, ordered steps, files touched,
risks/notes, code/commands) and commit to a clean aesthetic. A few hard constraints:

- **Self-contained:** inline all CSS/JS; no external fonts, stylesheets, scripts, or
  images. The page must render offline (use a system-font stack).
- **Responsive:** readable on mobile and desktop; wide content (code, tables) scrolls
  inside its own container — the page body must never scroll horizontally.
- **Escape content:** HTML-escape `&`, `<`, `>` in all text, especially inside
  `<pre><code>`. Never inject raw plan text that could break the markup.
- **Derive a good `<title>`** from the plan, and include today's date (`2026-06-20`).
- Don't pad — keep it as long as the plan warrants, skimmable, not a dumped outline.

Write the result to `.claude/skills/publish-plan/.out/plan.html` (create the `.out`
directory first; it is gitignored). Use the Write tool with the absolute path.

## 4. Upload it (inline curl)

```bash
mkdir -p .claude/skills/publish-plan/.out
URL=$(curl -fsS -X POST --data-binary @.claude/skills/publish-plan/.out/plan.html \
  -H "Authorization: Bearer $SPECS_TOKEN" \
  "$SPECS_URL")
echo "$URL"
```

- To request a specific name, append `?name=<slug>` to `$SPECS_URL` (the server
  will pick a random friendly name if you don't).
- `-f` makes curl fail on HTTP errors; if it errors, show the user the status and
  the response body so they can see e.g. a 401 (bad token) or 413 (too large).

## 5. Report back

Give the user the returned URL on its own line and offer to open it in the browser.
You may delete the `.out/plan.html` afterward, or leave it for re-publishing.
