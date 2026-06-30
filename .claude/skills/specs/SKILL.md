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
```

An optional `--name <slug>` suffix requests a specific URL slug.

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
HTML_FILE=".claude/skills/specs/.out/plan.html"   # or the resolved .html path from Step 1

if command -v specs &>/dev/null; then
  SPECS_BIN="specs"
else
  SPECS_BIN="npx --yes @rsrdev/specs-html"
fi

# If the user supplied --name <slug>, forward it:
#   $SPECS_BIN "$HTML_FILE" --name "$SLUG"
URL=$($SPECS_BIN "$HTML_FILE")
echo "$URL"
```

The binary reads config from:
- Env vars `SPECS_HTML_URL` and `SPECS_HTML_TOKEN`
- `~/.specs-html` (format: `URL=https://…` / `TOKEN=…`)

If it exits with a config error, tell the user to create `~/.specs-html` (see the
package README) or export those two env vars. Never print the token.

## 4. Report back

Output the returned URL on its own line and offer to open it in the browser.
You may remove `.claude/skills/specs/.out/plan.html` afterward or leave it for re-runs.
