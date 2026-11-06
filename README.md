# PlusChat PHP API

The API and static app are served from the same HTTPS origin so the HTTP-only session cookie works without cross-origin cookie settings. In this repository, the document root must point to the `public/` directory; keep `src/`, `database/`, `.env`, and `storage/` outside it.

PlusChat is one responsive installable web app. Its service worker caches only the app shell and icon files; authenticated API responses and user media are never cached. The app needs a secure HTTPS origin (or localhost during local development) for installation and geolocation.

Requires PHP 8.1+ with PDO MySQL, Fileinfo, OpenSSL, and Intl enabled. Intl is needed to normalize internationalized email domains such as `плюсчат.рф` for SMTP delivery and address comparison.

For uploads, set PHP `upload_max_filesize` to at least `4M` and `post_max_size` to at least `5M`. The PHP process must be able to create and write to the repository-level `storage/` directory; keep that directory outside `public/`.

## Database setup

1. Run `database/schema.sql` in the `pluschat` MySQL database.
   For an existing database created before sticker-history protection was added, also run `database/migrations/001_sticker_history_restrict.sql` once.
2. Configure the web server environment variables from `.env.example` in the hosting control panel, or place a private `.env` in `Server/` (the loader uses hosting environment values first). Set the mail sender fields to the provided mailboxes. For SMTP, set `SMTP_HOST=mail.xn--80avljg2a1c.xn--p1ai`, `SMTP_PORT=25`, and `SMTP_ENCRYPTION=auto`. Set `SMTP_REGISTER_USER` / `SMTP_REGISTER_PASSWORD` for `register@плюсчат.рф` and `SMTP_LOGIN_USER` / `SMTP_LOGIN_PASSWORD` for `login@плюсчат.рф`. The client uses STARTTLS when the server advertises it and refuses to send SMTP AUTH credentials over an unencrypted connection. If the server does not offer STARTTLS, ask the mail provider for its supported secure SMTP port/settings rather than setting plaintext authentication. Generate a unique `APP_SECRET` for the host; keep `.env`, credentials, and API keys out of the public web root and repository.
3. The static web app is already in `public/`; no separate build or copy step is required. Point the HTTPS document root at this directory.

To make a clean upload archive from Windows, run `./package-release.ps1` from the repository root. It creates `./release/pluschat-release-<timestamp>.zip` with the public app, PHP source, SQL schema, and setup notes. It does not include `.env`, local credentials, or runtime storage. Extract it outside the current web root, configure `.env` in the extracted repository root beside `public/`, import `database/schema.sql`, grant PHP write access to the root-level `storage/` directory, and set the site's document root to the extracted `public/` directory.

The GitHub Actions workflow at `.github/workflows/package-release.yml` builds the same deployment archive when run manually or when a `v*` tag is pushed, then attaches it to the workflow run as an artifact. The workflow packages source for download; it does not deploy the website.

If the hosting plan fixes the domain root at `public_html`, extract the archive in the account home directory, copy the **contents** of its `public/` folder into the domain's document root (including the hidden `.htaccess` file), and leave `src/`, `database/`, `.env`, and the generated `storage/` folder in the account home directory beside `public_html/`. Never put `.env` or `src/` inside the public web root.

For a local preview, run `php -S 127.0.0.1:8080 -t public` from the repository root after configuring the database and mail environment. HTTP localhost requires `SESSION_SECURE_COOKIE=false` in the shell environment; do not use that setting on the HTTPS production site. The secure-cookie setting defaults to `true`.

## Authentication endpoints

- `POST /api/auth/login.php` accepts JSON `{ "email": "...", "password": "..." }`.
- `POST /api/auth/request-code.php` accepts JSON `{ "email": "..." }`.
- `POST /api/auth/verify-code.php` accepts JSON `{ "email": "...", "code": "123456" }`.
- `POST /api/auth/register.php` accepts multipart fields `email`, `username`, `displayName`, `bio`, `password`, and optional `avatar`.
- `POST /api/auth/request-password-reset.php` and `POST /api/auth/reset-password.php` reset a password by email code and expire prior sessions.
- `GET /api/auth/me.php` returns the signed-in account.
- `POST /api/auth/logout.php` clears the current device session.
- `GET /api/users/username.php?username=...` checks username availability.
- `GET /api/users/avatar.php?id=...` serves the signed-in user's avatar.
- `POST /api/users/avatar-upload.php` replaces the signed-in user's avatar.
- `GET/DELETE /api/users/devices.php` lists saved devices and revokes another device.
- `GET/POST /api/users/profile.php` reads or updates the profile and optional age.
- `GET/POST/DELETE /api/users/location.php` opts into or clears coarse nearby location.
- `GET/POST /api/chats/index.php` lists chats or creates a direct/group chat; `POST /api/chats/local.php` joins a nearby age-group chat; `POST /api/chats/leave.php` leaves a chat and transfers its owner role if needed.
- `GET/POST /api/chats/messages.php` reads the latest 100 messages (older history loads while scrolling up), reads messages after a cursor, or sends text, GIPHY GIF, or sticker messages. Reading advances the per-member read marker, and the chat list returns unread counts.
- `GET/POST/DELETE /api/stickers/packs.php` lists, creates, or deletes packs; `POST/DELETE /api/stickers/install.php` installs or removes a public pack from the user's library; `POST/DELETE /api/stickers/add.php` adds or removes a sticker. A pack or sticker already used in message history cannot be deleted; installed-pack removal keeps historical sticker images accessible to conversation members.
- `GET /api/config.php` provides the GIPHY browser key.

Registration stores passwords using PHP `password_hash()`, accepts only a recently verified email code, and creates the signed-in session immediately. The browser keeps a secure, HTTP-only 256-bit device token; the database stores only its SHA-256 hash. The token expires after 30 days of inactivity and appears in the saved devices list. Verification and successful registration email use configured SMTP when `SMTP_HOST` is set, otherwise PHP `mail()`. SMTP diagnostics are written to the PHP error log with the failing protocol phase and response status, without logging passwords or message contents.

Passwords must contain 8–72 UTF-8 bytes, at least one lowercase and uppercase Latin letter, one ASCII digit, and one character that is neither a letter nor a number. The byte limit avoids bcrypt truncation; the browser shows the same rules during registration and password recovery.

Authentication endpoints use database-backed throttling: login is limited to 10 attempts per email/IP pair and 30 attempts per IP per 15 minutes; registration-code requests are limited to five per email and 20 per IP per hour; password-reset requests are limited to five per email and 20 per IP per hour. Message sending is limited to 120 messages per account per minute; sticker uploads are limited to 50 per account per hour and 100 stickers per pack. Verification and reset codes allow five incorrect guesses. Import the current `database/schema.sql` to create its `auth_rate_limits` table; old rate-limit buckets are pruned opportunistically.

The age number is optional and private. Local chats are separated into self-declared age groups. Direct and group chat creation blocks a known adult/minor mix; age is self-reported, not identity-verified. GIPHY requests use the `G` rating for under-16 and unknown-age accounts, `PG` for ages 16–17, and `PG-13` for adults. Local chat uses a user-selected city and a keyed hash of a roughly 1 km geolocation cell; raw coordinates are not stored, and device location does not prove residence. The location confirmation expires after 24 hours and must be refreshed before rejoining or continuing to read and send in a local chat.

GIPHY requests run in the browser with the configured API key and show “Powered by GIPHY” in the picker. Keep the key restricted in the GIPHY dashboard. Sticker images and avatars are stored outside the public document root and served through authenticated endpoints.
