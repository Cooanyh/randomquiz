# RandomQuiz.Pro

RandomQuiz.Pro is a self-hosted quiz practice app. It includes local and cloud question banks, accounts, sharing links, progress sync, and optional AI assistance. The interface and project documentation are primarily in Chinese.

## Requirements

- PHP 8.0 or newer, with `cURL`, `JSON`, `mbstring`, and OpenSSL extensions
- Composer
- A web server configured to run PHP

## Install

1. Install PHP dependencies from the project directory:

   ```sh
   composer install --no-dev --optimize-autoloader
   ```

2. Create a private configuration file:

   ```sh
   cp config.example.php config.php
   ```

   Edit `config.php` and replace the placeholders. Configure SMTP if you want email verification, and configure an AI provider if you want server-backed AI features.

3. Create the runtime data directory and make it writable by the PHP process:

   ```sh
   mkdir -p data
   chmod 750 data
   ```

   The API creates its subdirectories as needed. Keep `data/` private and out of public downloads. The included `.htaccess` blocks it on Apache; Nginx and other servers need equivalent deny rules.

4. Serve the project directory through PHP. The frontend uses relative paths, so the app can live at a domain root or in a subdirectory. Use HTTPS for any deployment that handles accounts or API credentials.

## Configuration

`config.example.php` documents the supported settings:

- SMTP host, account, password, sender, and verification-code expiry
- Server-side AI provider keys, default models, and optional quiz content review
- `FREE_MODEL_DAILY_LIMIT` and request-rate settings for self-hosted traffic control
- Per-user cloud quiz, question, and image limits
- `CORS_ALLOWED_ORIGINS` for a separately hosted frontend; same-origin hosting needs no CORS entries

Leave optional provider keys empty when a provider is not configured. A provider may charge according to its own account terms. The application has no subscription or payment system.

The `data/` directory contains account records, sessions, user-supplied provider keys, synchronized progress, uploaded quizzes, and share links. These files are not encrypted by the application. Restrict filesystem access and protect backups accordingly. Server-side AI requests send the relevant prompt and attached images to the provider configured by the site owner.

## Security

- Never commit `config.php`, runtime `data/`, `vendor/`, logs, or backups. The repository ignores these paths.
- Do not publish `data/` or `vendor/` through the web server. If using Nginx, explicitly deny access to those directories, `.git/`, `doc/`, `config.php`, Composer files, and runtime JSON files while allowing `manifest.json`.
- Keep `config.php` readable only by the deployment owner and PHP process. Rotate provider or SMTP credentials if they are exposed.
- Keep CORS restricted to exact frontend origins when cross-origin hosting is required.
- Use HTTPS and take encrypted, access-controlled backups of `data/`.

## License

GNU General Public License version 3. See [LICENSE](LICENSE).
