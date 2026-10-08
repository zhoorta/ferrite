# Project page and demo mode

Optional features for running Ferrite as a public project site. An ordinary install needs none of this.

## Project page at `/`

With `FERRITE_LANDING=true` the landing page is shown at `/` to everyone instead of redirecting to the files. The button top right says **Sign in** (to `/login`) or, once signed in, **Open my files**. The page links to the source (`FERRITE_REPO_URL`) and to a demo (`FERRITE_DEMO_URL`) when set, and lets visitors try the thirteen themes live.

The same install can be your private instance and the public page at one address: keep `FERRITE_REGISTRATION=false`, so strangers can only reach the page and the sign-in form. On a fresh install `/` sends you to the Welcome page to create the admin account; once it exists, `/` shows the landing page. Turn on 2FA or a passkey for it.

## Demo mode

Run a second, separate install (its own database, storage and `APP_KEY`; never shared with a real instance) with:

```
FERRITE_DEMO=true
FERRITE_DEMO_TTL_MINUTES=60
FERRITE_DEMO_QUOTA_MB=20
FERRITE_DEMO_MAX_ACCOUNTS=100
FERRITE_DEMO_MAX_FILE_MB=2
FERRITE_DEMO_CONTACT=abuse@example.com   # shown in the banner for takedown requests
```

- The sign-in page gets a **Try the demo** button. It creates a throwaway user (random address under `demo.ferrite.invalid`), a 20 MB quota and a few sample files, signs them in and sends them to their files. Limited to 6 starts per hour per IP.
- `demo:prune` runs every 10 minutes from the scheduler and deletes accounts older than the lifetime together with their files.
- Registration is closed, and the profile and security settings (e-mail, password, 2FA, passkeys) redirect to the appearance page.
- **Nothing is ever shared.** The Share action is removed (`NodePolicy::share` denies demo users), and `/s/{token}` returns 404 on a demo instance. A visitor's files are visible only to that visitor.
- **Uploads are restricted**: images (jpg, png, gif, webp), PDF and plain text (txt, md, csv, json), at most `FERRITE_DEMO_MAX_FILE_MB` each. The extension and size are checked before the upload starts, and the detected content type again when it finishes, so an executable renamed to `.png` fails. No archives, scripts, HTML or SVG.
- A banner on every page says it is a public demo, that data is deleted after the lifetime, not to upload anything private or illegal, and shows `FERRITE_DEMO_CONTACT`.
- No admin account exists. If you need one, `ferrite:user` still works.

Why so strict: an open sign-up that stores files on your domain can be abused to hold illegal or malicious content, and a flagged host can hurt the parent domain's reputation. With sharing off the demo stores but never distributes, with a short lifetime. Remaining: a visitor could still upload a bad image or PDF that only they can see, for an hour. If a report comes in, delete the account and keep the web server access log for the period your obligations require. Using a separate domain for the demo protects your main domain further.

Install it like any other instance ([install.md](install.md)); with Docker, a second service in the compose file with its own env file and volume is enough, and keep the request size limit of its reverse proxy modest.

The sitemap at `/sitemap.xml` (the landing page only) and `/robots.txt`, which points to it, are served by the app while `FERRITE_LANDING` is on; the sitemap returns 404 otherwise.
