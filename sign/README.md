# sign/ — Sovereign Rights Acknowledgment Form (PHP)

Standalone PHP form that renders the four-clause acknowledgment, validates submissions, and writes them to an append-only JSONL store.

## Why PHP?

GitHub Pages doesn't run server-side code, so the GitHub Issue Form (in [`../.github/ISSUE_TEMPLATE/`](../.github/ISSUE_TEMPLATE/sovereign-rights-acknowledgment.yml)) is the GitHub-native channel. That route requires a GitHub login.

This PHP version is the **GitHub-login-free** alternative — anyone with a browser can sign. It needs a PHP-capable host (shared hosting, VPS, or your own webserver). Both routes are valid; signers may pick whichever fits.

## Files

| File | Purpose |
|---|---|
| `sign.php` | The form + handler (single file, self-contained). |
| `.htaccess` | Apache config — blocks data files, sets security headers, caches off. |
| `signatures.jsonl` | Append-only signature store. **Gitignored.** Created on first submit. |
| `.ratelimit.json` | Per-IP-hash rate-limit bucket. **Gitignored.** |
| This `README.md` | Deployment instructions. |

## Requirements

- PHP **7.4+** (8.x recommended)
- Apache (with `mod_headers` for security headers) **OR** nginx (snippet below)
- Writable directory permissions on `sign/` for the PHP process (so JSONL + ratelimit can be written)
- HTTPS (the session cookie is `Secure` flag when over HTTPS)

## Deployment — Apache (shared hosting)

```bash
# 1. Upload the sign/ directory to your webroot, e.g.,
#    /var/www/html/sign/
# 2. Make the directory writable by the PHP process:
chown -R www-data:www-data /var/www/html/sign/
chmod 750 /var/www/html/sign/
# 3. Verify:
curl -I https://yourdomain.example/sign/sign.php
# Expect: HTTP/2 200, Content-Security-Policy header present.
```

## Deployment — nginx + php-fpm

Add to your server block:

```nginx
location /sign/ {
    index sign.php;

    # Block direct access to data files
    location ~* \.(jsonl|json|txt|log|md)$ {
        deny all;
    }

    # Only sign.php is allowed to execute as PHP
    location ~ ^/sign/sign\.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/var/run/php/php-fpm.sock;
        add_header Cache-Control "no-store, no-cache, must-revalidate, max-age=0" always;
        add_header X-Content-Type-Options "nosniff" always;
        add_header X-Frame-Options "DENY" always;
        add_header Referrer-Policy "strict-origin-when-cross-origin" always;
        add_header Content-Security-Policy "default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; script-src 'none'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'" always;
    }

    # Other .php files are explicitly denied
    location ~ \.php$ {
        deny all;
    }
}
```

## Configuration knobs

In `sign.php`, near the top:

```php
const STORAGE_FILE       = __DIR__ . '/signatures.jsonl';
const RATE_LIMIT_FILE    = __DIR__ . '/.ratelimit.json';
const RATE_LIMIT_PER_HR  = 3;
const SCHEMA_VERSION     = 'sign.v1';
```

Adjust the rate limit if needed. Don't move `STORAGE_FILE` outside the `sign/` directory unless you also adjust `.htaccess` / nginx rules to keep the new path private.

## Storage format

`signatures.jsonl` is JSON Lines — one JSON object per line, append-only. Each record:

```json
{
  "schema": "sign.v1",
  "signed_at": "2026-05-09T12:34:56Z",
  "handle": "Jane Doe / @jane",
  "affiliation": "Independent",
  "use_case": "academic",
  "notes": "Citing in upcoming paper.",
  "ack": {"c1": true, "c2": true, "c3": true, "c4": true, "final": true},
  "ip_hash": "sha256(IP)",
  "ua_hash": "sha256(User-Agent)"
}
```

Notes:
- IP and User-Agent are **hashed**, never stored raw. The hash is for dedupe / rate-limit forensics; it is not reversible.
- Records are never edited or deleted. To handle a takedown request, append a tombstone record referencing the original `signed_at` (don't mutate history).

## Security notes (read before deploying)

- **CSRF token** is session-bound and rotates on success.
- **Honeypot field** (`website_url`, hidden via CSS) catches naive bots.
- **Rate limit** is 3 submissions / hour per IP-hash. Tunable.
- **Input validation:** `handle` ≤120, `affiliation` ≤240, `notes` ≤1200, `use_case` enum.
- **Sanitization:** control characters stripped on every text field.
- **No JavaScript required.** The form works without JS; JS is enhancement only (and there is none in this minimal version).
- **No third-party scripts** (CSP `script-src 'none'`).
- **No analytics, no trackers, no tag managers.** Adding any of these is a discipline failure.

## Backup / audit

`signatures.jsonl` is the durable record. Treat it like an audit-store extract:
- Back it up off-host.
- Rotate it monthly (rename + start a fresh file). Keep all rotations.
- Never edit a row; append-only is binding.

## Companion: GitHub-login route

If a signer prefers not to type their handle into a form, the equivalent GitHub-identity-bound route is:

> https://github.com/ametemre/yrenn-hybrid/issues/new?template=sovereign-rights-acknowledgment.yml

Both routes are equally valid. Both are public, durable, and not retroactively revocable.

## License

This `sign/` directory is licensed CC-BY-SA-4.0 (consistent with the README at the repo root).
