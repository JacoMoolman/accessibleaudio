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

## Deploy

Use the existing Hostinger FTP deploy path:

```powershell
.\scripts\deploy-hostinger.ps1
```

The deploy script uploads the public site, `submit/`, `api/`, and
`private_uploads/.htaccess`. It does not upload `api/config.local.php`, `.env`,
or ignored upload contents.
