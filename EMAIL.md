# Hostinger Email Notes

## Mailbox

- Email address: `audio@accessibleaudio.co.za`
- Webmail: `https://mail.hostinger.com`

## Incoming Mail

- Protocol: `IMAP`
- Host: `imap.hostinger.com`
- Port: `993`
- Security: `SSL/TLS`

## Outgoing Mail

- Protocol: `SMTP`
- Host: `smtp.hostinger.com`
- Port: `465`
- Security: `SSL/TLS`

## Local Secrets

Private email credentials are stored in `.env.local`, which is ignored by Git.

Do not commit mailbox passwords or saved email contents.

## Local Email Snapshots

Saved email message details are stored under `mail/`. That folder is ignored by Git because messages can contain private data.
