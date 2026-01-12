# Approov Backend Quickstart - PHP (No Framework)

This project provides a plain PHP implementation of Approov token verification for a protected backend API. It mirrors the Java Spring quickstart logic and endpoints, including token binding and double binding checks, while keeping everything in a single file.

## Endpoints

- `/unprotected` - no Approov token required.
- `/token-check` - requires a valid Approov token.
- `/token-binding` - requires a valid Approov token bound to the `Authorization` header.
- `/token-double-binding` - requires a valid Approov token bound to `Authorization` + `Content-Digest`.
- `/approov-state` - shows Approov/token-binding enablement state.
- `/approov/enable` and `/approov/disable` - toggle Approov protection.
- `/token-binding/enable` and `/token-binding/disable` - toggle token binding checks only.

## Requirements

1. Approov account and CLI initialized (`approov whoami` works).
2. PHP 8.1+ with Composer.
3. curl installed.

## Setup

1. Copy `.env.example` to `.env`:

```bash
cp .env.example .env
```

2. Fetch the Approov secret and paste it into `.env` as `APPROOV_BASE64URL_SECRET`:

```bash
approov secret -get base64url
```

3. Install dependencies:

```bash
composer install
```

## Run the server

From the `PHP` folder:

```bash
php -S 0.0.0.0:8080 server.php
```

The Approov state is stored in `PHP/var/approov_state.json` so that enable/disable toggles persist across requests.

## Test the endpoints

In a second terminal (from the `PHP` folder):

```bash
bash test.sh
```

This uses the Approov CLI to generate valid and invalid tokens, then runs the same test flow as the Java quickstart.

## Notes on the middleware

`server.php` contains a simple request router plus an `ApproovTokenMiddleware` that enforces:

- Approov token validation (`Approov-Token` header, JWT HS256).
- Optional token binding validation against the `pay` claim.
- Optional double-binding validation using `Authorization` + `Content-Digest`.

## Useful Links

- https://approov.io/resource/quickstarts/
- https://ext.approov.io/docs
- https://approov.io/blog
