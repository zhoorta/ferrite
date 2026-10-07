# Public site and demo (ferrite.stackcare.pt)

Two separate installs of the same code, both behind the same reverse proxy.

## ferrite.stackcare.pt: project page and private instance

One install, one address. Everyone sees the project page at `/`; the button top right says **Sign in** (to `/login`) or, once signed in, **Open my files**. Registration stays closed (`FERRITE_REGISTRATION=false`), so the page is the only thing strangers can reach: the login form, nothing else.

```
FERRITE_LANDING=true
FERRITE_DEMO_URL=https://demo.ferrite.stackcare.pt
FERRITE_REGISTRATION=false
```

Create the first account from the shell (`php artisan ferrite:user you@example.com --admin`), since the web sign-up is closed once registration is off and the landing page is on. Turn on 2FA or a passkey for it: this instance is on the open internet.

## demo.ferrite.stackcare.pt: try-out instance

A second container with its own volume and its own `APP_KEY`, never sharing a database or storage with the private one.

```
FERRITE_DEMO=true
FERRITE_DEMO_TTL_MINUTES=60
FERRITE_DEMO_QUOTA_MB=20
FERRITE_DEMO_MAX_ACCOUNTS=100
FERRITE_DEMO_MAX_FILE_MB=2
FERRITE_DEMO_CONTACT=abuse@stackcare.pt   # shown in the banner for takedown requests
```

- The sign-in page gets a **Try the demo** button. It creates a throwaway user (random address under `demo.ferrite.invalid`), a 20 MB quota and a few sample files, signs them in and sends them to their files. Limited to 6 starts per hour per IP.
- `demo:prune` runs every 10 minutes from the scheduler and deletes accounts older than the lifetime together with their files.
- Registration is closed, and the profile and security settings (e-mail, password, 2FA, passkeys) redirect to the appearance page.
- **Nothing is ever shared.** The Share action is removed (`NodePolicy::share` denies demo users), and `/s/{token}` returns 404 on a demo instance. A visitor's files are visible only to that visitor.
- **Uploads are restricted**: images (jpg, png, gif, webp), PDF and plain text (txt, md, csv, json), at most `FERRITE_DEMO_MAX_FILE_MB` (2) each. The extension and size are checked before the upload starts, and the detected content type again when it finishes, so an executable renamed to `.png` fails. No archives, scripts, HTML or SVG.
- A banner on every page says it is a public demo, that data is deleted after the lifetime, not to upload anything private or illegal, and shows `FERRITE_DEMO_CONTACT`.
- No admin account exists. If you need one, `ferrite:user` still works.

Why so strict: an open sign-up that stores files on your domain can be abused to hold illegal or malicious content, and a flagged host can hurt the parent domain's reputation. With sharing off the demo stores but never distributes, with a short lifetime. Remaining: a visitor could still upload a bad image or PDF that only they can see, for an hour. If a report ever comes in, delete the account (`demo:prune` with a past lifetime, or remove the user) and keep the web server access log for the period your obligations require. A separate domain for the demo protects `stackcare.pt` further.

## Caddy

```
ferrite.stackcare.pt      { reverse_proxy ferrite:8080 }
demo.ferrite.stackcare.pt { reverse_proxy ferrite-demo:8080 }
```

Run the demo as a second service in the compose file with `env_file: ferrite-demo.env` and its own volume.

Ready-made nginx configs, systemd units, a demo compose file and env examples for an Ubuntu host with nginx are in `deploy/` (see `deploy/README.md`): private instance native, demo in Docker.
