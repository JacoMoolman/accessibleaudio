# Hostinger Deployment Notes

## Website

- Domain: `accessibleaudio.co.za`
- Website URL: `http://accessibleaudio.co.za`
- WWW URL: `http://www.accessibleaudio.co.za`
- Website IP: `89.117.169.254`
- Upload path: `public_html`

## Hosting

- Provider: Hostinger
- hPanel details page: `https://hpanel.hostinger.com/websites/accessibleaudio.co.za/order/details`
- Server name: `server1472`
- Server location: `Europe (France)`
- Backups location: `Lithuania`

## Plan Limits

- Disk space: `50 GB`
- RAM: `1024 MB`
- CPU cores: `1`
- Inodes: `200000`
- Addons/websites: `1`
- Max processes: `40`
- PHP workers: `25`
- Bandwidth: `100 GB`

## Nameservers

- Current nameserver 1: `hermes.dns-parking.com`
- Current nameserver 2: `artemis.dns-parking.com`

Hostinger notes that DNS propagation can take up to 24 hours.

## FTP

- FTP IP: `ftp://89.117.169.254`
- FTP hostname: `ftp://accessibleaudio.co.za`
- FTP username: `u428615841.accessibleaudio.co.za`
- Remote upload path: `public_html`

Do not save FTP passwords or Hostinger account passwords in this repo.

Private FTP connection values are stored locally in `.env.local`. That file is ignored by Git. Keep the safe variable template in `.env.example`.

## Deployment Reminder

For a static site, upload the built website files into `public_html`.

Typical build output folders:

- `dist`
- `build`
- `out`

The homepage should normally be named `index.html`.

This repo includes a deployment script:

```powershell
.\scripts\deploy-hostinger.ps1
```

The script reads FTP connection values from `.env.local` and uploads the static site files to `public_html`.

## Preview Before DNS

While DNS is still registering or propagating, use Hostinger's preview option in hPanel:

`Websites -> accessibleaudio.co.za -> Dashboard -> Preview`

Hostinger also supports previewing by editing the local hosts file so `accessibleaudio.co.za` points to `89.117.169.254` on your own machine.
