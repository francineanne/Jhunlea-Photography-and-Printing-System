# Email setup (Phase 10C)

The system sends three kinds of email, all from the shop's own address:

- **Verify your email**: after a customer registers, and after anyone changes their email on the account page. The link works for 60 minutes. "Resend" is limited to 3 times every 10 minutes.
- **Your password reset code**: a 6-digit code for "Forgot password". It works for 10 minutes and for 5 tries.
- **Your password was changed**: after a reset with a code.

Walk-in customers have no email and are never sent anything. Everything else stays in-app notifications only.

Put the values below in `.env` yourself. Never commit `.env`, and never paste the password into chat, code or a document.

## 1. Gmail with an App Password

Gmail does not accept your normal Gmail password from an app. It needs an **App Password**, and that needs 2-Step Verification on the account.

1. Use the Google account the shop will send from (best: a separate account just for the shop).
2. Open Google Account > **Security** > turn on **2-Step Verification**.
3. Open Google Account > Security > **App passwords** (or search "App passwords" in the account settings).
4. Create one named, for example, "Jhunlea website". Google shows a 16-letter password once. Copy it into `.env` (next step) without the spaces, and do not save it anywhere else.
5. If the shop ever stops using the site, or the password leaks, delete that App Password in the same screen. Nothing else on the account changes.

## 2. The `.env` keys (names only)

```
MAIL_MAILER=smtp
MAIL_SCHEME=
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME="${APP_NAME}"
```

- `MAIL_USERNAME` and `MAIL_FROM_ADDRESS`: the shop's Gmail address (they should be the same, or Gmail rewrites the sender).
- `MAIL_PASSWORD`: the App Password from step 1.
- `MAIL_SCHEME`: leave empty for port 587 (Laravel starts TLS by itself). For port 465 use `MAIL_PORT=465` and `MAIL_SCHEME=smtps`.
- `APP_URL` must be the real address of the site: the verification link is built from it.

After changing `.env` on the hosting: `php artisan config:clear` (or `php artisan config:cache` again if the config is cached).

Gmail sends a few hundred messages a day from a normal account, which is far more than a small shop needs.

## 3. Testing on your own computer (no real email)

Keep `MAIL_MAILER=log` in your local `.env` (the default in `.env.example`). Every email is then written into `storage/logs/laravel.log` instead of being sent:

1. Register a test customer, or ask for a password code.
2. Open `storage/logs/laravel.log` and look at the newest lines: the verification link (`/email/verify/...`) and the 6-digit code are there.
3. Open the link in the same browser, or type the code on the reset page.

The automated tests never send email: they use `Notification::fake()`.

## 4. Before going live

Run `php artisan jhunlea:preflight`. It FAILs when the mailer is `log` or `array` in production, or `MAIL_FROM_ADDRESS` is missing, and WARNs when the SMTP user name or password is missing. It only prints "set" or "missing", never the address or the password.

Then send yourself a real test: register with your own email on the live site and check that the verification email arrives (look in Spam the first time, and mark it "Not spam").
