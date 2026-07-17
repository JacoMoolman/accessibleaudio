# Submit Audiobook Setup

The submit flow is now packaged for the existing Hostinger website instead of
Render. The browser app lives in `submit/`, the PHP endpoints live in `api/`,
and uploaded TXT files are stored locally under `private_uploads/`.

## Runtime Shape

- `https://accessibleaudio.co.za/submit/` serves the submit page.
- `/api/config.php` returns public Supabase Auth config.
- `/api/process-file.php` verifies the Supabase user session, saves the TXT
  file locally, writes `options.txt`, and returns the price/payment payload.
- `/api/files.php` lists the logged-in user's local upload records.
- `private_uploads/.htaccess` blocks direct HTTP reads of stored uploads.

The submit page does chapter detection, language detection, and word counting in
browser JavaScript before upload. The server repeats only validation, pricing,
storage, and PayFast payload generation.

## Supabase

Supabase is still used for login and Google Auth. The browser and PHP backend
use only the public anon key. The current public values are committed in
`api/config.public.php` so the Hostinger deploy works without a private config
file.

Use `api/config.local.php` only for optional server-local overrides:

```php
<?php
return [
    'SUPABASE_URL' => 'https://your-project.supabase.co',
    'SUPABASE_ANON_KEY' => 'your-public-anon-key',
    'TURNSTILE_SITE_KEY' => null,
];
```

`api/config.local.php` is ignored by Git and the deploy script does not upload it
automatically.

Update Supabase Auth redirect/site settings to include:

```text
https://accessibleaudio.co.za/submit/
https://www.accessibleaudio.co.za/submit/
```

## Local Upload Storage

Uploads are stored on Hostinger at:

```text
private_uploads/users/{sha256_user_id}/uploads/{upload_id}/{safe_filename}.txt
```

Do not put AWS keys in this repo or on Hostinger for this flow. The Hostinger
submit backend does not read S3 or AWS environment variables.

## PayFast

PayFast is optional. If these values are absent, uploads and pricing still work
but no PayFast form is returned:

```php
'PAYFAST_MERCHANT_ID' => null,
'PAYFAST_MERCHANT_KEY' => null,
'PAYFAST_PASSPHRASE' => null,
'PAYFAST_SANDBOX' => true,
'PAYFAST_RETURN_URL' => 'https://accessibleaudio.co.za/submit/?payment=success',
'PAYFAST_CANCEL_URL' => 'https://accessibleaudio.co.za/submit/?payment=cancelled',
'PAYFAST_NOTIFY_URL' => 'https://accessibleaudio.co.za/api/payfast-notify.php',
```

## Automated MP3 Production

Paid cloud-voice orders are placed in a resumable local production queue. A
Hostinger PHP cron job calls `api/process-queue.php`; each invocation generates
one or more Grok Voice MP3 chunks through OpenRouter, stores progress in the
private upload folder, and joins completed chunks into one MP3 per chapter.
The customer's browser does not perform production and may be closed after
payment.

Private server configuration:

```php
'OPENROUTER_API_KEY' => 'server-only-key',
'OPENROUTER_TTS_MODEL' => 'x-ai/grok-voice-tts-1.0',
'TTS_CHUNK_CHARACTERS' => 4500,
'TTS_REQUEST_TIMEOUT' => 300,
'WORKER_CHUNKS_PER_RUN' => 1,
```

Configure the Hostinger cron job to run every minute using the account's actual
home path, for example:

```text
php /home/USER/domains/accessibleaudio.co.za/public_html/api/process-queue.php
```

Only PHP CLI may execute the worker. Direct web access is blocked by
`api/.htaccess`. Finished chapter files remain under `private_uploads` and are
served only through an authenticated download endpoint. The worker emails the
uploading account after all chapter MP3s are ready.

The current Grok Voice TTS endpoint is not listed by OpenRouter as a Zero Data
Retention endpoint. Manuscript chunks therefore pass through OpenRouter and
xAI under their current provider data policies.

## Deploy

Use the existing Hostinger FTP deploy path:

```powershell
.\scripts\deploy-hostinger.ps1
```

The deploy script uploads the public site, `submit/`, `api/`, and
`private_uploads/.htaccess`. It does not upload `api/config.local.php`, `.env`,
or ignored upload contents.
