# XAMPP setup notes (Windows)

Settings in `C:\xampp\php\php.ini` that the project needs on a developer's Windows machine. After every change, close
and reopen the terminal (and restart Apache and `php artisan serve` if they are running). Check what PHP really loaded
with `php --ini` and `php -i`.

## Certificates for the AI provider (cURL error 60)

XAMPP's PHP ships without a list of trusted certificate authorities. Without one, PHP cannot check the certificate of
`generativelanguage.googleapis.com`, every request fails with **cURL error 60** ("unable to get local issuer
certificate") before it reaches the provider, and Lumi always answers with the fallback text. The log line
`assistant.provider_failed` then shows `"kind":"connection","curl_error":60`.

1. Download `cacert.pem` from https://curl.se/ca/cacert.pem and save it as `C:\xampp\php\extras\ssl\cacert.pem`.
2. In `php.ini`, set these two lines (remove the `;` at the start):

   ```ini
   curl.cainfo = "C:\xampp\php\extras\ssl\cacert.pem"
   openssl.cafile = "C:\xampp\php\extras\ssl\cacert.pem"
   ```

3. Restart the terminal. Never turn certificate checking off in the code instead.

## Memory and upload limits for the test suite

```ini
memory_limit = 512M
upload_max_filesize = 10M
post_max_size = 40M
```

- `memory_limit`: the full suite (more than 1600 tests) can run out of memory at the default 128M.
- `upload_max_filesize` and `post_max_size`: the upload limits the shop uses are capped by these values
  (`App\Support\ServerUploadLimits`), so with smaller values the upload tests fail. Keep them at least as high as the
  values in `config/printing.php` and `config/booking.php`.
