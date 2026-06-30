---
name: specs
description: Publish a spec or plan file to the specs server using the `specs` npm binary, returning a shareable URL. Triggered by "specs <path>", "/specs relpath", "publish this with specs", or "share plan as specs link". Accepts HTML directly; renders markdown/text to HTML first.
---

# specs

Publish a plan or spec to the hosted specs server and return a shareable URL.

## Invocation patterns

```
/specs path/to/plan.html
/specs path/to/spec.md
/specs                       ← uses the plan from the current conversation
/specs path/to/plan.html --name my-plan          ← request a specific slug
/specs path/to/plan.html --update-token <tok>    ← update an existing plan (requires --name)
```

## 1. Resolve the source

Use, in order of preference:

1. **File path in args** — absolute or relative to the working directory.
   - `.html` → upload as-is (go to Step 3).
   - `.md`, `.txt`, `.markdown`, or anything that reads as non-HTML → render first (Step 2).
2. **No path given** — use the current conversation's implementation plan or spec.
   If there is no plan, ask the user for a path; do not invent content.

## 2. Render to HTML (if needed)

Convert the source content to a self-contained HTML page:

- **Self-contained:** inline all CSS; no external fonts, stylesheets, scripts, or images.
- **Responsive:** wide content (code blocks, tables) scrolls inside `overflow-x:auto`
  containers — the body must never scroll horizontally.
- **Escape content:** HTML-escape `&`, `<`, `>` in all text nodes, especially inside
  `<pre><code>`.
- **Title:** derive from the content (first heading or filename), include today's date.
- Make it genuinely readable — clean visual hierarchy, not a raw outline dump.

Write the result to `.claude/skills/specs/.out/plan.html` (create `.out` first if absent).

## 3. Upload with the `specs` binary

```bash
# Prefer a globally installed binary; fall back to npx.
if command -v specs &>/dev/null; then
  SPECS_BIN="specs"
else
  SPECS_BIN="npx --yes @rsrdev/specs-html"
fi

HTML_FILE=".claude/skills/specs/.out/plan.html"   # or the resolved .html path from Step 1
```

### First publish — always use --json to capture the update token

```bash
RESPONSE=$($SPECS_BIN "$HTML_FILE" --json)
# { "ok": true, "slug": "brave-otter", "url": "https://…/brave-otter", "update_token": "abc123…" }
URL=$(echo "$RESPONSE" | grep -o '"url":"[^"]*"' | cut -d'"' -f4)
UPDATE_TOKEN=$(echo "$RESPONSE" | grep -o '"update_token":"[^"]*"' | cut -d'"' -f4)
echo "$URL"
echo "update_token: $UPDATE_TOKEN"
```

Or with a custom slug:

```bash
RESPONSE=$($SPECS_BIN "$HTML_FILE" --name my-plan --json)
```

### Updating an existing plan

```bash
$SPECS_BIN "$HTML_FILE" --name <slug> --update-token <token>
# returns the same URL — no new token issued
```

The binary reads config from:
- Env vars `SPECS_HTML_URL` and `SPECS_HTML_TOKEN`
- `~/.specs-html` (format: `URL=https://…` / `TOKEN=…`)

If it exits with a config error, tell the user to create `~/.specs-html` or export
those two env vars. Never print the token.

## 4. Report back

- Output the returned URL on its own line.
- On first publish: show the `update_token` clearly and tell the user to save it —
  it won't be shown again, and it's the only way to update this plan later.
- Offer to open the URL in the browser.

You may remove `.claude/skills/specs/.out/plan.html` afterward or leave it for re-runs.
