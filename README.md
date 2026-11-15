# Laravel Demo: Support Tickets

This is a demo project for junior Laravel developers to practice their skills.

The task is fully described [in this article](https://laraveldaily.com/post/demo-project-laravel-support-ticket-system).

A few screenshots:

![Screenshot 1](https://laraveldaily.com/uploads/2022/11/laravel-support-tickets-01.png)

![Screenshot 2](https://laraveldaily.com/uploads/2022/11/laravel-support-tickets-02.png)

- - - - -

## How to use

- Clone the repository with __git clone__
- Copy __.env.example__ file to __.env__ and edit database credentials there
- Run __composer install__
- Run __php artisan key:generate__
- Run __php artisan migrate --seed__ (it has some seeded data for your testing)
- Run __npm install__
- Run __npm run build__ or __npm run dev__ - whichever you prefer
- That's it: launch the main URL.
- You can register as regular user or login as admin to manage data with default credentials __admin@admin.com__ - __password__


## PrivacyLib GDPR integration

Original GDPR framework: **[PrivacyLib-REGO](https://github.com/CS-UCY-SEIT-lab/PrivacyLib-REGO)**. The unchanged PHP router and eleven OPA/Rego modules are bundled in `integration/privacy-lib/`; no sibling checkout is needed.

### Requirements and setup

Use PHP 8.1 with cURL, PDO SQLite (or the original MySQL driver), sessions and the extensions required by this application's existing Composer lock file. The lock file requires PHP below 8.2; it has not been upgraded or bypassed for this integration. PHP downloads: https://www.php.net/downloads.php

Install OPA 1.x: https://www.openpolicyagent.org/docs/latest/#running-opa

Follow the original application setup above. On an existing installation, `php artisan migrate` adds only the separate `gdpr_consents` table; it does not modify existing users or ticket records. Keep `.env`, application keys, databases and storage/build/dependency files out of Git. For local tests, set `MAIL_MAILER=log` so existing ticket notifications stay in the local log.

Start the policy server from this directory:

```sh
opa run --server --addr 127.0.0.1:8181 integration/privacy-lib/gdpr
```

`OPA_QUERY_URL` in `.env` defaults to `http://localhost:8181/v1/query`. Configure the synthetic controller, purpose, retention and request-check metadata in `integration/privacy-config.json` for the intended application.

Start the original application:

```sh
php artisan serve --host=127.0.0.1 --port=8022
```

Sign in through the original login page (the seeded demo credentials above still work), then follow **Privacy checks** in the top bar or sidebar. Dummy-email registration is supported by the original application; email verification is not required. Use the same browser tab/origin for login and privacy checks.

### Routes, data scope and limitations

The authenticated Laravel web routes are `GET /gdpr`, `GET /gdpr/csrf`, `PATCH /gdpr/consent` and eleven `POST /gdpr/{policy}` operations: consent, lawful, information, access, rectification, erasure, restriction, portability, objection, complaint and breach. POST/PATCH requires Laravel's session CSRF token in `X-CSRF-TOKEN`, JSON content type and an `Accept: application/json` header. Laravel normally returns 419 for invalid CSRF and 422 for invalid input.

The adapter uses the existing authenticated database account. Client-provided user IDs, permissions and trusted policy checks are rejected. Breach assessment requires the host's existing `admin` role. Even administrator exports remain scoped to their own account.

Mapped fields are name, email, owned tickets (selected title/body/status/priority/timestamp fields) and the user's own messages on those owned tickets. Queries explicitly filter authenticated ownership and authorship. Other accounts, other participants' replies, attachments/binaries, password hashes, tokens, role data and internal audit records are not exported.

Consent is saved in the separate table. All other responses are policy decisions and proposed action plans; account changes, ticket edits, erasure, transfers, complaint delivery, audit writes and notifications are not executed by the GDPR routes. Access returns metadata; portability returns selected stored values. This demonstration does not establish legal compliance or change unrelated host authorization behavior.

### Verify independently

With PHP and OPA on PATH:

```sh
php integration/test.php
```

The native PHP runner uses temporary SQLite, a random application key/session cookie and local ports. It applies the original migrations/seeders, starts the actual Laravel HTTP application and OPA, and exercises original registration/login/ticket/message handlers and the GDPR routes. Mail is captured by an array transport; no email is delivered. It never uses your configured application database or changes existing accounts. Test fixture files and processes are removed afterward.

Optional `PHP_BINARY`, `PHP_INI` and `OPA_BINARY` select runtime paths. No extra Composer packages, Python or JavaScript test framework are required. Evaluation reports are not written into the repository.

The original scaffold authentication test has an existing redirect mismatch: it expects a regular user to land on `/dashboard`, while the host controller sends regular users to `/tickets`. That original test and controller are unchanged; this HTTP suite checks the actual role-aware flow.
