# Submit Audiobook Setup

This repo now contains a Render-ready FastAPI app for authenticated `.txt`
submissions. The app stores the uploaded file in AWS S3 and writes metadata to
Supabase Postgres.

## Supabase

1. Create or open the Accessible Audio Supabase project.
2. In SQL Editor, run `supabase/uploaded_files.sql`.
3. Enable email/password authentication in Supabase Auth.
4. Add these frontend URLs to Auth redirect/site settings after Render deploy:
   - `https://accessible-audio-submit.onrender.com`
   - `https://accessibleaudio.co.za`
   - `https://www.accessibleaudio.co.za`

## S3

Create a private bucket for submissions. The planned bucket name in config is:

```text
accessible-audio-submissions
```

If that global S3 bucket name is unavailable, create a unique variant and set
Render `S3_BUCKET_NAME` to the exact bucket name.

Uploaded objects are stored under:

```text
users/{user_id}/uploads/{upload_id}/{safe_filename}.txt
```

Use a restricted AWS IAM access key for Render. It only needs object write/read
permissions for this one bucket.

## Render

Create a free Render Web Service from this GitHub repo. Render can read
`render.yaml`, or configure manually:

```text
Build Command: pip install -r requirements.txt
Start Command: uvicorn backend.main:app --host 0.0.0.0 --port $PORT
```

Set these Render environment variables:

```text
SUPABASE_URL
SUPABASE_ANON_KEY
SUPABASE_SERVICE_ROLE_KEY
SUPABASE_JWT_SECRET
AWS_ACCESS_KEY_ID
AWS_SECRET_ACCESS_KEY
AWS_REGION=us-east-1
S3_BUCKET_NAME=accessible-audio-submissions
MAX_UPLOAD_BYTES=10485760
ALLOWED_ORIGINS=https://accessibleaudio.co.za,https://www.accessibleaudio.co.za,https://accessible-audio-submit.onrender.com
```

Do not put AWS, Supabase service role, OpenAI, Anthropic, or Render tokens in
frontend code.

## Local Run

Install dependencies:

```powershell
python -m pip install -r requirements.txt
```

Set the same environment variables locally, then run:

```powershell
uvicorn backend.main:app --reload
```

Open:

```text
http://127.0.0.1:8000
```
