create table if not exists public.uploaded_files (
  id uuid primary key,
  user_id uuid not null references auth.users(id) on delete cascade,
  filename text not null,
  s3_bucket text not null,
  s3_key text not null,
  status text not null default 'uploaded'
    check (status in ('uploaded', 'queued', 'processing', 'completed', 'failed')),
  narrator_voice text,
  output_format text not null default 'mp3'
    check (output_format in ('mp3', 'wav')),
  also_wav boolean not null default false,
  translate boolean not null default false,
  translation_languages text[] not null default '{}',
  make_video boolean not null default false,
  source_language text,
  created_at timestamptz not null default now(),
  processed_at timestamptz,
  result_text text,
  result_path text
);

create index if not exists uploaded_files_user_created_idx
  on public.uploaded_files (user_id, created_at desc);

alter table public.uploaded_files enable row level security;

drop policy if exists "Users can read their uploaded files" on public.uploaded_files;
create policy "Users can read their uploaded files"
  on public.uploaded_files
  for select
  to authenticated
  using (auth.uid() = user_id);

drop policy if exists "Users can insert their uploaded files" on public.uploaded_files;
create policy "Users can insert their uploaded files"
  on public.uploaded_files
  for insert
  to authenticated
  with check (auth.uid() = user_id);

drop policy if exists "Users can update their requested options" on public.uploaded_files;
create policy "Users can update their requested options"
  on public.uploaded_files
  for update
  to authenticated
  using (auth.uid() = user_id)
  with check (auth.uid() = user_id);
