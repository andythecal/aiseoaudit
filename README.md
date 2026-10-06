# X-Ray SEO Auditor

A single-page PHP/MySQL SEO and GEO diagnostic tool. Runs on the existing Laragon Apache/MySQL services and deploys to ordinary Apache/PHP hosting. No Node runtime, build step, queue worker, separate server or paid API is needed.

## Local use

Open **http://aiseoauditor.test/**.

The local database is **aiseoauditor**. A dedicated MySQL user and random password are configured in **config/local.php**. This file is private and excluded from deployment. Laragon's existing database was used; setup only created this application's tables.

Every accepted scan attempt is recorded before fetching, including failed scans. Completed findings and extracted snippets are stored in MySQL, not public files. Protected local storage contains sessions, logs and deployment state.

- Enter a public page URL; a full scan can take up to a minute.
- Review SEO findings, separate GEO indicators, the HTML x-ray and heading tree.
- Filter basic findings or export JSON. Registered owners can download server-generated PDFs without spending an in-depth credit.
- Saved audits remembers unclaimed reports using a signed essential browser cookie.
- Register/sign in, then choose whether to save earlier browser audits to your account for access across devices. New signed-in audits are automatically account-owned.
- Account-owned reports remain private after logout, even in the same browser.
- Delete individual audits from Saved audits. Deletion does not reset quotas.
- Visitors: 5 basic attempts daily, enforced per signed browser identity and hashed network address.
- Registered accounts: 100 basic attempts and 3 in-depth scans daily.
- Allowances reset at midnight Europe/London time (including daylight-saving changes).
- Failed basic attempts count to discourage repeated expensive requests. In-depth page-fetch failures refund the credit; a partial sitemap review with a valid page and image analysis still counts.
- Each saved basic report can be upgraded once. Reopening completed basic/in-depth reports is free.
- In-depth includes sitemap URL heuristics, an image alt inventory, and curated explanations/action steps for all findings. It does not overwrite the saved basic result or score.

## Requirements

Use PHP **8.3 or later** on production hosting with cURL, DOM/libxml, mbstring, PDO MySQL, OpenSSL and sessions. The code also runs on Laragon's existing PHP 7.4 CLI, but that version is not recommended for production. MySQL 8 or MariaDB with InnoDB and utf8mb4; Apache 2.4 with AllowOverride enabled and mod_rewrite. No database passwords are exposed to the browser.

**config/local.php** is per-environment:

- environment: local or production. Production API requests require HTTPS.
- app_key: 64 random hexadecimal characters; keep stable to preserve visitor cookies.
- db: the hosting account's database connection details.
- The legacy gate_details setting is ignored: the existing basic report is fixed as the free report. In-depth data and PDF generation require an owning account.
- registration_enabled: enables account creation.
- Daily limits are centralised in src/Allowance.php. The former audit_limit_per_hour setting is ignored. Authentication and PDF anti-abuse throttles remain separate.
- fetch_timeout: per HTTP request, default 8 seconds, with an overall network budget.
- max_response_bytes: 2 MiB decompressed response cap.
- storage_path: writable directory, preferably outside public_html in production. Local default is ./storage with direct HTTP access denied.

The application expects direct HTTPS at Apache. If adding a reverse proxy later, explicitly configure trusted proxy handling rather than trusting arbitrary forwarded headers.

## First deployment to Krystal

1. In cPanel, create a MySQL database and a database user with privileges on that database. Choose a current PHP version and enable the required extensions.
2. Enable HTTPS for the intended domain. Use a dedicated document root/subdirectory for this application. Back up any existing site before replacing files.
3. Copy config/deploy.example.php to **config/deploy.php** locally. Enter the FTPS hostname, username, password, port and FTP-root absolute remote_path (often /public_html). The script uses explicit TLS for control and data, validates certificates, and never falls back to plain FTP.
4. Run **php deploy.php --dry-run**. Inspect storage/deploy-plan.json. Run **php deploy.php --upload** to deploy.
5. On the host, manually create **config/local.php** from the deployed config/app.example.php, using production DB credentials and a fresh app key. Generate a key with the PHP CLI command below. Do not copy the local config to the host.
6. Set `storage_path` to a writable private directory. Run **php bin/setup.php** in the hosting terminal. Without a terminal, import `database/schema.sql`, `database/002-membership.sql`, `database/003-account.sql` and `database/004-research.sql`, in that order, using phpMyAdmin; create the configured `storage/sessions` directory manually.
7. Visit the HTTPS URL, run an audit and verify that /config/local.php, /storage/, /database/schema.sql and /src/Engine.php return 403/404.

Generate an application key:

    php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"

The deployer does not change remote databases or replace remote configuration. Run **php bin/setup.php** after deploying to apply all four idempotent schema files, including account and research tables. The deployment script does not run remote migrations automatically.

### Routine deploys

Run **php deploy.php --upload** after reviewing a dry run. The SHA-256 manifest is local, under storage/deploy-manifest-TARGET.json, and is specific to host, port, user and destination. Only files changed since the last successful upload are sent.

The deployment allowlist is in deploy_files() in deploy.php. Add new production files there deliberately. Secrets, .git, local specs, tests, sessions, logs, audit data, the deploy script and deploy credentials are excluded. Sensitive directories also contain deny-all .htaccess files.

Uploads are staged and read back over FTPS to verify hashes before activation. The script backs up destination files locally and attempts rollback if activation fails. The manifest advances only after success. Removed files are reported for manual cleanup and never automatically deleted. This is a small shared-host deployer, not an atomic multi-file release system: use a low-traffic deployment window. It does not detect remote edits to locally unchanged files; keep production changes in this source tree.

Backups are in storage/deploy-backups/RELEASE/, keyed by the SHA-256 hash of each relative filename. Keep backups and manifests private. Their contents are never deployed. A deploy lock prevents concurrent local uploads. Remote upload/rollback can only be verified once host credentials are configured.

## Scoring and audit scope

See [the specification review](docs/spec-review.md) for changes to the original design.

Crawlability checks carry five times the weight of other SEO checks: pass = 1 point, review = 0.5, fail = 0. The lowest applicable critical cap wins: noindex 20/100, failed robots.txt 35/100, page-level nofollow 40/100. All crawlability failures show an urgent warning. This is an application diagnostic model, not a search-engine ranking formula. Existing saved audits retain their original scores; run a fresh scan to use engine 1.1.0. GEO checks use a separate denominator. A failed fetch has no score. Reports store engine/scoring versions so future comparisons can account for changed rules.

This is a single-page raw-HTML audit, not a browser render, full-site crawl or ranking report. It does not measure Core Web Vitals, backlinks, AI citations or actual search engine indexing. It does not request a target site's images, CSS, scripts or link destinations. The word count intentionally follows the specified paragraph/heading selector, including those elements inside footers. Language and page-purpose limitations are explained in the interface.

Sitemaps are parsed without external entity loading. Only one sitemap is inspected: a same-origin declaration in robots.txt takes priority over /sitemap.xml. Basic audits count sitemap indexes without following them. In-depth scans follow up to 4 same-host sitemap files and review up to 1,000 entries (maximum 200 flagged rows). Compressed sitemaps are not supported yet. In-depth inventories list the first 250 HTML img elements and count missing/empty alt attributes separately.

## Accounts, leads and next steps

Account emails are not yet verified; automated password-reset email is not implemented. The interface discloses this. Before a public account launch, add verified-email/recovery workflows and a real operator/contact/privacy policy. Account passwords are hashed; optional marketing consent has its own timestamp and version. Signing up does not imply marketing consent.

The leads table has a nullable ghl_contact_id ready for the future integration. Nothing is transmitted to GHL. Add a retryable outbound queue and consent-aware field mapping when integrating; no CRM credentials or external account were created.

Reports are retained until explicitly deleted. Clearing a browser cookie does not delete unclaimed server records. A production retention policy, account erasure flow and backups should be chosen when the public service is launched. For now, audit deletion is available to owners in the UI.

## Verification

Run against the existing Laragon services:

    php tests/run.php
    php tests/integration.php
    php tests/membership.php
    php tests/member-api.php
php tests/account-api.php
node tests/account-ui.cjs
    php tests/pdf.php
    node tests/crawlability-ui.cjs
    node --check assets/app.js
    php deploy.php --dry-run

Integration tests create temporary records, check a public URL, then delete only those test records. They exercise private report access, CSRF, failed-attempt persistence, account claiming, consent, login/logout, exports, deletion and blocked private files. They consume two local audit rate-limit requests. Deterministic tests cover word-count boundaries, heading order, malformed schema/XML, robots precedence, private network protection, scoring, gating and trailing-slash decisions.

No browser was connected during the initial build, so desktop/mobile visual and click-through verification remains a manual check. All automated HTTP checks use Laragon; no development server is started.


## PDF runtime and production upgrade

Dompdf 3.1.6 is bundled under vendor/dompdf from the official GitHub release, with all required runtime dependencies and fonts. The release SHA-256 was checked against its published digest. Host-side Composer is not needed. The deployer includes the bundled PHP/font/runtime files and licences, while vendor/.htaccess blocks public access. Remote fetching, embedded PHP and JavaScript are disabled in the PDF renderer; audited content is escaped. Runtime temporary/font-cache files live in protected storage/pdf-cache.

Download is an authenticated ownership-checked endpoint. Browser-native printing cannot be prevented; the registration restriction applies to the application's PDF generation feature. Thirty PDF requests per account per hour are allowed as an abuse guard.

The daily_usage table stores counters independently of reports. advanced_audits records running/completed/failed member reviews. In-depth reservations and refunds are transactional; interrupted runs older than two minutes are recovered on session/report access. Three in-depth credits reset daily, not lifetime. Basic and in-depth credits are separate.

The original basic reports remain unchanged. New sitemap/image inventories show their own fetch time and may differ from an older basic report if the page changed. These extras use deterministic parsing and authored guidance; no AI provider is used.

PDF output bounds large inventories to 200 headings, 50 sitemap flags and 100 images (missing alt first), with a visible truncation note. The saved web report and JSON export retain the full bounded in-depth inventory.


## Accessibility and ARIA (engine 1.3.0)

Every new basic audit saves deterministic accessibility findings alongside its original result in `audits.result_json`; no database migration or extra service is required. Guests receive only a pass/fail/not-checked summary. Registration unlocks affected elements, escaped snippets, suggested fixes, examples and manual checks on owned reports, in JSON exports and PDFs. These details do not use in-depth credits or change SEO/GEO scores. Legacy reports need a new audit; they are never silently re-scored or labelled as passing.

Checks cover main/navigation landmarks, names for forms/links/buttons/frames, image alternatives, document title/language presence, empty/skipped headings, selected ARIA required states and value formats, unknown roles/attributes, broken/ambiguous ID references, potential focus within aria-hidden regions, table-header review, positive tabindex, inline custom click controls and missing skip-link targets. Native HTML and role equivalents are accepted; empty decorative alt, hidden/inert elements, labelledby targets and native control defaults are handled separately. A pass means no definite automated failures found, even if manual-review warnings remain.

This is a source-HTML heuristic checker, not a complete accessible-name implementation, rendered DOM scanner or WCAG conformance assessment. It does not prove full ARIA role/property compatibility, contrast, keyboard behaviour, meaningful alt wording, visible labels or JavaScript states. Rules are bounded to 5,000 elements and 150 saved findings; incomplete scans cannot receive a pass. Public payloads explicitly allowlist summary fields; server-side filtering applies to reports and JSON exports. Evidence never copies form field values. PDFs use the same authenticated report projection and escape all evidence.

Reference: [WAI-ARIA 1.2](https://www.w3.org/TR/wai-aria-1.2/), [WAI landmark guidance](https://www.w3.org/WAI/ARIA/apg/practices/landmark-regions/), [WAI names and descriptions](https://www.w3.org/WAI/ARIA/apg/practices/names-and-descriptions/).

Run `php tests/accessibility.php`, `node tests/crawlability-ui.cjs`, `php tests/member-api.php` and `php tests/pdf.php` alongside the existing suites. Test PDF fixtures stay under protected `storage/` and are not deployed.


## My Account and browser-history import

`?view=account` opens the signed-in dashboard with account email/join date, daily allowances, saved-report totals, URL search, status filters, stable cursor pagination, report/PDF access and deletion. Account history contains only account-owned rows. The separate Browser history view contains only unclaimed rows belonging to this signed browser cookie. Reports explicitly state whether they are saved to the account or this browser history.

All accepted audits already live in MySQL. Signing in no longer silently transfers unclaimed audits. On encountering browser history for the first time on that account/browser, registration, login and session restoration offer Save all or Keep in this browser. An explicit choice is persisted in `browser_audit_choices`, so subsequent logins do not repeat the prompt on the same browser. Dismissing without choosing leaves the choice pending. Declined history can still be imported later from My Account; another browser has its own choice. Signing in on a shared browser never grants access to another account's reports.

Import is a CSRF-protected, authenticated transaction that only claims rows with matching server-derived visitor hash and `user_id IS NULL`. It preserves IDs, timestamps and results, is idempotent, and does not charge credits. Signed-in scans are immediately account-owned and can be opened from another device. An in-depth review of an unclaimed browser audit first requires explicit import. Browser cookie loss removes access to unclaimed history; account-owned reports remain available after sign-in.

Run `php bin/setup.php` when deploying this version to apply migration 003. The migration and new Account class are included in the deployment manifest; the deployer does not run remote migrations. Existing reports and accounts are preserved, including reports claimed by earlier versions. Local API tests cover consent/decline, cross-device access, account isolation, search/pagination, deletion and automatic ownership. No browser was connected for desktop/mobile visual verification of this dashboard; the UI logic and DOM hooks have automated coverage.


## Super-admin research dashboard

Open `?view=admin` or Research admin in the navigation after signing in with an authorised account. Professional roles and administrative permissions are separate: public registration only accepts a fixed professional-role list and cannot grant admin access. Admin permissions live in `super_admins` and are checked against the database on every request, including exports and saved-report access. Revocation takes effect immediately on the next request.

Provision a new super admin from a trusted hosting terminal with `php bin/create-admin.php --email=you@example.com`. The CLI-only helper creates a randomly generated password, prints it once and stores only its password hash. To grant access to an existing account without changing its password, explicitly add `--promote-existing`. No public provisioning endpoint exists. Do not put admin passwords in configuration or deploy files. Local accounts live only in the local database; provision production accounts separately after applying migration 004.

The dashboard contains period totals, top 20 checked hosts, registration-role breakdowns, and searchable/paginated Users, Audits and Activity tables. Every displayed timestamp and date boundary is UTC. Date filters affect period totals; search, role, event and audit-state filters affect the selected table and its export. CSV exports contain at most 5,000 matching records, with a truncation indication and formula-like cells neutralised. Password hashes, session tokens and application credentials are never selected for exports. Admin report inspection uses a separate read-only endpoint; ordinary report mutation endpoints still enforce account ownership.

Registration requires Marketing, Tech advisor, CEO/director, Freelancer, Agency, Business owner, Developer, SEO consultant, Student/researcher or Other. Registration and new audit contexts store the server-observed connection IP, bounded user-agent, estimated OS/browser/device and primary Accept-Language value. No fingerprinting, location lookup or third-party analytics service is used. `REMOTE_ADDR` is used without trusting forwarding headers; behind a proxy this may be the proxy address, and Laragon usually records localhost. These values are client-reported/inferred and must not be treated as verified identity. Missing historical context remains Not recorded.

Research events record visits (at most once per 30 minutes per session when the session endpoint is requested), registration, login/logout, audit start/completion/failure, in-depth reviews, report views, PDF/JSON downloads, deletion, browser-history decisions and privileged report/CSV access. Times come from the UTC database connection. Distinct signed visitor-cookie hashes are browser identities, not unique people. In-progress scans interrupted by shutdown are recorded when the failure is detected; an abrupt process termination that skips shutdown cannot guarantee an immediate event. Existing saved audits and registrations remain available with their original dates, but historical event/client data is not backfilled or invented.

Research URLs omit userinfo, query strings and fragments. Original saved reports retain the original report content and URLs. Deleting an audit removes the report and its per-audit client row; the separate research activity log, including its redacted page URL, remains. Profiles and research events have no scheduled retention purge in this version; the visible privacy notice describes the collection and separate retention. Registration research is independent of optional marketing consent. All research client details are server-gated to super admins; ordinary account responses do not include them.

Validate with `php tests/research.php`, `php tests/research-api.php` and `node tests/research-ui.cjs`. Research fixtures remove their own event data after tests so research results are not polluted by temporary users. The local database migration is applied; deployment includes the new code and schema but excludes database contents, private config, test files and credentials.


## Repository contents

Deployment scripts, deployment configuration/manifests, local database credentials, local provisioning helpers and all runtime storage are intentionally excluded from Git. Deployment commands documented above refer to local-only tooling and are not available in a fresh clone. The repository includes the application, database schemas/migrations, safe application configuration example, account-administration helper, tests and bundled PDF runtime. Use `config/app.example.php` as the starting point for private `config/local.php`, then run `php bin/setup.php` against your configured database. Database contents, research records and generated PDFs are not published to GitHub.
