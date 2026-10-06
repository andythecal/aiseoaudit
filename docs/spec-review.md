# Functional review of auditor.md

The specified UI and raw-HTML audit flow are a useful starting point. The latest user instructions take precedence over its stateless edge-runtime constraints: this implementation uses PHP/MySQL and local file storage on Laragon/Apache, with portable deployment to LAMP hosting.

## Preserved

- Public URL entry, server-side HTML audit, safe text-only x-ray snapshot and 2.4-second scan reveal.
- Title/description/canonical, indexing directives, viewport/HTTPS/URL, H1 content and count, heading order/subheadings, alt text, links, social metadata, JSON-LD, favicon, word count, document language, trailing slash, robots and sitemap checks.
- Heading depth may increase by only one level at a time; decreases are allowed.
- Displayed content count uses only p/h1/h2/h3/h4/h5/h6 element text. Inline descendants count once; whitespace is collapsed and space-separated tokens counted. Thresholds remain 100/300. PHP DOM/XPath implements the selector rather than Cheerio; malformed HTML can be repaired differently by the two parsers.
- Raw-HTML limitations and helpful failure reports.
- Light design, violet accent, expandable findings, status text, accessible forms and reduced-motion support.

## Corrections and extensions

| Specification issue | Implemented behaviour |
| --- | --- |
| Stateless React/edge stack conflicts with LAMP and persistence | PHP backend, plain JS/CSS, MySQL and private session/log storage. |
| Selector claimed to exclude all footer/navigation content | Exact selector preserved. Paragraphs/headings in those containers still count; UI explains this. No claim that count represents only main content. |
| Fixed minimum word counts imply ranking requirements | Thresholds remain diagnostic heuristics, with page-purpose/language caveats. Google does not prescribe a preferred word count. |
| Warnings excluded from score, permitting misleading 100 | Warnings count half; GEO has a separate indicator score. Method and engine versions saved. |
| Failed fetch mistaken for bad SEO score | Saved failed attempt, no numeric score; blocking/timeouts explained separately. |
| Fetch arbitrary URLs without network controls | HTTP/S only, standard ports, no credentials, public-IP checks on every redirect, pinned DNS, TLS verification, proxy disabled, bounded responses/time/redirects. |
| Indexability checks only one robots meta element | All robots/Googlebot meta plus X-Robots-Tag inspected; absence of noindex does not guarantee indexing. |
| JSON-LD presence called success even when broken | Parse JSON, discover types/graphs, surface malformed JSON; do not claim full schema validation. |
| Robots disallow regex crosses user-agent groups | Group-aware matching, wildcards, longest-match precedence, allow-on-tie, Googlebot-specific evaluation. Common rules covered; not a complete crawler conformance implementation. |
| Always fetch /sitemap.xml and loosely regex XML | Prefer declared same-origin sitemap; parse real XML, validate root/locations, reject DTD/entities, flag HTML fallbacks. |
| All HTTP 4xx slash variants treated as absent | Only 404/410 prove absence; 403/429 need review. |
| Shared canonical still gets hard duplicate failure | Similarity is labelled heuristic; shared canonical softens to review. |
| Empty alt always means failure | Distinguish absent attribute from empty decorative alt and request human review. |
| GEO largely absent | Structural extractability, authorship, date and snippet signals, separate score and no AI-visibility promise. |
| No persistence/access controls | Save every accepted attempt; private browser history, accounts/ownership, gated API projection, exports and deletion. |
| No deployment plan | File allowlist, checksums, dry run, staged verified FTPS, local backups/manifests; per-environment secrets excluded. |

## Deliberate limits

No browser JavaScript execution; full-site discovery; link checking; Core Web Vitals; backlink analysis; keyword/ranking data; AI answer queries; recursive sitemap crawl; rich-result eligibility; or GHL integration. Snapshot output is bounded to 100 blocks and heading display to 500 entries, while checks evaluate all parsed headings.

Email verification and password recovery remain follow-up account work before public launch. Gating is off by default and can be enabled via configuration. The gate is account-based, not payment-based.

## Primary references

- [Google: AI features and your website](https://developers.google.com/search/docs/appearance/ai-features): SEO fundamentals remain relevant; indicators do not guarantee inclusion.
- [Google: helpful, reliable, people-first content](https://developers.google.com/search/docs/fundamentals/creating-helpful-content): no preferred word-count target.
- [Google: robots.txt interpretation](https://developers.google.com/crawling/docs/robots-txt/robots-txt-spec): user-agent selection and allow/disallow matching.
- [PHP cURL options](https://www.php.net/manual/en/function.curl-setopt.php): pinned resolution, TLS verification and transport restrictions.

These are design references, not live inputs to saved audits.

## Crawlability revision (engine 1.1.0)

Crawlability file cards and all Crawlability checklist findings now share a dedicated section. Failures show a warning triangle and "Needs urgent attention". Page-level nofollow is checked separately from noindex, including the combined none directive and HTTP headers. Crawlability checks carry 5x weight; critical caps are 20 for noindex, 35 for robots.txt failures and 40 for page-level nofollow. The lowest cap wins; missing/unconfirmed files remain warnings. Existing audits retain their original score and scoring-version explanation.

## Sparse initial HTML (engine 1.2.0)

A successful HTML fetch with fewer than 10 paragraph/heading words and no H1-H6 elements adds a failed Crawlability check and an always-visible explanatory note in that section. It receives the existing 5x weighting, without a new score cap. The note explains potential JavaScript, embedded-content or non-semantic markup issues and recommends crawlable initial HTML. It explicitly distinguishes this selector-based observation from proof of non-indexability. Exactly 10 words or any heading does not trigger this specific check.

Reference: [Google JavaScript SEO basics](https://developers.google.com/search/docs/crawling-indexing/javascript/javascript-seo-basics) recommends server-side rendering or prerendering because not all bots execute JavaScript.


## Registered-member layer

The basic report is frozen as the free information tier. Its former optional detail gate is superseded by membership-only extras. Visitors receive 5 basic audits per UK calendar day; registered accounts receive 100 basic plus 3 in-depth audits daily. In-depth credits add bounded sitemap review, an alt-text image inventory and curated per-section/per-check guidance. Registered owners can generate PDFs from basic or completed in-depth reports without consuming an in-depth credit. Test/staging flags are labelled potential issues requiring review, never asserted to be confirmed mistakes.

Sitemap presence helps discovery but does not guarantee indexing: [Google sitemap guidance](https://developers.google.com/search/docs/crawling-indexing/sitemaps/overview).


### Free member accessibility details

New basic audits include an independent Accessibility & ARIA result. Visitors get an automated pass/fail summary; registered owners get evidence and fixes in the report, JSON export and PDF without spending an in-depth credit. The source HTML checks distinguish failures from manual reviews, preserve native semantics and avoid treating decorative alt or hidden content as automatic failures. This is not a WCAG certificate or browser accessibility test. Older saved reports show Not checked until rerun. Results reuse audit JSON storage and the existing ownership checks; no schema or hosting changes are needed.


### My Account and explicit browser import

The dashboard separates persistent account history from signed-cookie browser history. Account reports are available across devices, with search, status filters, pagination, PDF downloads and deletion. All signed-in scans save with the authenticated user ID. Registration/login offers a choice before claiming earlier browser history; a decision is remembered per account/browser and import remains available later. No scans or credits are used during transfer, and report IDs, evidence and timestamps remain intact. Migration 003 adds the browser-choice table without altering existing reports.


### Research administration and professional roles

Migration 004 adds professional profiles, separately granted super-admin permissions, per-audit client context and timestamped research events. The role question is required at registration; CEO/agency/etc. are research categories, never privileges. The admin dashboard offers user/activity/audit views, UTC date ranges, filters, role/site summaries, read-only full reports and protected CSV exports. Original account ownership controls continue to apply outside the dedicated admin read endpoints. New UI privacy copy describes IP/user-agent/OS/browser/language collection and the separate retention of event logs when reports are deleted. Existing history is shown without inventing old device/IP information. No extra server, service, AI provider or external analytics dependency is introduced.
