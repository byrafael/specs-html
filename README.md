# 📮 @byrafael/specs-html

A tiny PHP application to host AI-generated specs and plans: `curl` POST
an HTML file, get back a shareable URL, open it in a browser. One PHP file, no
database, and no dependencies.

You're right. PHP isn't the trendy new framework. This is intentional: the goal is a
single-file tool that runs on inexpensive/shared hosts without extra runtime or build
steps. It works everywhere PHP does (which is [most](https://w3techs.com/technologies/details/pl-php) of the internet).

## Quickstart

1. Copy `main.php` to any PHP host (shared hosting, a VPS, etc.).
2. Edit `main.php` and set `UPLOAD_TOKEN` to a long random secret.

Generate a token with:

```bash
openssl rand -hex 24
```

Upload a plan:

```bash
curl -X POST --data-binary @plan.html \
     -H "Authorization: Bearer YOUR_TOKEN" \
     https://example.com/main.php
```

The response is the public URL on its own line, e.g.:

```
https://example.com/main.php/brave-otter
```

## Options

- Multipart upload: use `-F file=@plan.html` instead of `--data-binary`.
- Choose a name: add `?name=my-plan` to the upload URL.
- JSON response: add `?json` to the upload request.

## Tips & notes

- Max upload size is 5 MB (`MAX_BYTES`) — also limited by your php.ini.
- Plan names are restricted to `[a-z0-9-]` and files are confined to `plans/`.
- Uploaded plans are served as `text/html`, so any JS/CSS in them runs.
- Verify the PHP syntax locally with `php -l main.php` before deploying.

That's it — small, simple, and portable.
MIT Licensed. Feel free to contribute, fork, or whatever.