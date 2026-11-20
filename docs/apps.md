# Mac and Android apps

Native companions for a Ferrite server: browse, search, upload and open your files from a Mac or an Android phone. They are not sync clients and not mounts: nothing is mirrored to the device, a file is fetched when you open it and kept in a cache for a while. Both talk to the server's token [API](api.md).

Download them from the **Apps** page of the project site (`/download` on an instance that runs the project page). An iOS app, and Linux and Windows apps, are planned.

## What you need

- A Ferrite server of version **0.4.0 or newer**, reachable over HTTPS.
- macOS 14 or newer, or Android 8 or newer.
- Some features need a recent server and say "needs a newer version" on an older one: zip downloads, copy, favourites, trash and the storage overview.

## Connecting

1. Open the app and enter your server address.
2. The app checks that it is a Ferrite server, then opens your **browser** on the server's approval page. You sign in as usual (two-factor authentication and passkeys included) and confirm your password.
3. You choose the **folder** the app may reach, the **access** (read; write; replace; trash) and an expiry, and allow it.
4. The browser hands control back to the app, which receives a token. The app never sees your password.

The token shows up under **Settings > API tokens** named after the app and the device. Revoke it there and the app is signed out at its next request. A token reaches only its folder and what is inside it. Details, including the PKCE exchange, are in the [API reference](api.md#connecting-an-app-browser-approval).

If you prefer, you can paste a token you created yourself in Settings instead. The browser approval is off on the public demo.

## What they do

| | Mac | Android |
| --- | --- | --- |
| Browse folders (list and grid), thumbnails, folder tree | yes | yes |
| Search by name and inside files | yes | yes |
| Upload (chunked and resumable), with progress and cancel | drag and drop, "Upload to Ferrite" in Finder | picker, photo picker, share sheet |
| Open a file, edit in place and save back as a new version | yes | open in another app |
| Rename, move, copy, duplicate, trash, restore | yes, with undo | yes |
| Favourites | yes | yes |
| Download files and folders (a folder arrives as a ZIP) | yes | yes |
| Storage overview (quota, by type, biggest folders and files) | yes | yes |
| Themes | seven, plus the system look | seven, plus the system look |
| Menu bar item with the last uploads | yes | notification with progress |

Not in the apps: sharing links and users (use the web app), and sync.

## Installing

**Mac.** Open the zip and drag Ferrite to Applications. The build is not notarized yet, so the first time right-click the app, choose Open and confirm. To get "Upload to Ferrite" in Finder's right-click menu, open the app once from Applications.

**Android.** Open the `.apk` on the phone. Android asks to allow installs from the app you opened it with (your browser or a file manager): allow it for that one install. Updates are installed the same way, over the existing app, as long as they come from the same publisher.

## Troubleshooting

- **"Not a Ferrite server"**: check the address, with `https://` and no path. Open `https://your-server/api/v1/ping` in a browser: it answers a short JSON with `"app": "ferrite"`.
- **The browser does not return to the app**: the server only sends the approval back to the addresses in `FERRITE_CONNECT_REDIRECTS` (default `ferrite://auth`). Leave it at the default unless you know why not.
- **"Needs a newer version"**: update the server.
- **Signed out suddenly**: the token expired or was revoked in Settings. Sign in again.
- **A big upload is interrupted** (network change, app closed): uploads are chunked and resumable, so uploading the same file again continues where it stopped. On Android the transfer runs as a notification, so you can leave the app.

## Publishing the downloads (for server owners)

The download page lists whatever is in one folder on your server. Nothing is stored in the repository.

```
FERRITE_LANDING=true                     # the project page, docs and downloads are public
FERRITE_DOWNLOADS_PATH=/var/www/ferrite-downloads   # default: storage/app/downloads
```

Put the builds there named `Ferrite-<version>.<extension>`: `.dmg` or `.zip` for the Mac, `.apk` for Android.

```
Ferrite-0.1.0.dmg
Ferrite-0.1.0.apk
```

The newest version of each platform is offered, with its size and SHA-256 (computed once and cached until the file changes). A platform with no file shows "Not published on this server yet". There is nothing to migrate or restart: copy a file in and reload the page.

A build you make yourself must be signed to be useful: a Mac build outside the App Store needs a Developer ID certificate and notarization to open without a warning, and an Android build needs a release key that you keep, because an update signed with a different key cannot be installed over the old app.
