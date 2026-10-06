# On-Page SEO Audit — Complete App Specification

A self-contained blueprint for rebuilding the **On-Page SEO Audit** ("X-Ray SEO Readiness Scanner") tool. This document describes the purpose, technology stack, design system, every UI component, the audit animation, and — in full detail — how every SEO check is performed (with special emphasis on the **textual content / word-count** measure, which must be reproduced exactly).

---

## 1. Overview & Purpose

A single-page web tool that performs an on-page SEO readiness audit of any public URL, modelled on the Semrush on-page SEO checklist (`https://www.semrush.com/blog/on-page-seo-checklist/`).

**User flow:**
1. The user enters a page URL into a search-style input.
2. The tool fetches the page server-side and renders a lightweight "x-ray" skeleton of the page.
3. A glowing horizontal scan line sweeps top-to-bottom across the skeleton (the signature animation).
4. Below the skeleton, a report appears: a circular score gauge, an audit summary, crawlability-file cards (robots.txt & sitemap.xml), a heading-structure visualisation, and a categorized pass/fail/warn checklist. Each checklist row expands to explain why it passed or failed.

**Single route only:** `/` (`src/routes/index.tsx`). There are no other pages, no login, and no persistent backend database. The audit runs entirely server-side on the raw HTML (it does not execute the page's JavaScript), so JS-rendered content is not captured.

---

## 2. Technology Stack

| Concern | Choice |
|---|---|
| Framework | TanStack Start v1 (full-stack React 19) |
| Router | TanStack Router (file-based, single route `/`) |
| Build tool | Vite (Lightning CSS) |
| Styling | Tailwind CSS v4 (CSS-first config in `src/styles.css`, no `tailwind.config.js`) |
| Animation | framer-motion |
| Icons | lucide-react |
| HTML parsing | cheerio (`load` from `cheerio`) |
| Server-side logic | `createServerFn` from `@tanstack/react-start` (type-safe RPC) |
| Runtime target | Edge function (Cloudflare Workers, `nodejs_compat) — no external DB/auth |
| Validation | zod |
| Font | Plus Jakarta Sans (loaded via `<link>` in root route head) |

> The stack is **stateless and serverless**. Server functions read `process.env` only inside `.handler()`. There is no filesystem persistence and no auth system. Do not add a database, auth provider, or `react-router-dom`.

### File map (what to create)

```
src/
├── routes/
│   ├── __root.tsx          # root layout, font <link>, <HeadContent/>, error/404 components
│   └── index.tsx           # the entire audit page (input, animation, report)
├── components/
│   ├── AuditSnapshot.tsx   # x-ray skeleton + scan-line animation
│   ├── AuditResultsTable.tsx# expandable pass/fail checklist
│   ├── HeadingStructure.tsx # heading tree visualisation
│   ├── ScoreGauge.tsx       # circular SVG score gauge
│   └── AuxiliaryFiles.tsx   # robots.txt & sitemap.xml cards
├── lib/
│   ├── seo-audit.functions.ts  # createServerFn RPC wrapper (thin)
│   ├── seo-audit.server.ts     # page fetch + core audit (all checks)
│   ├── auxiliary-files.server.ts # robots.txt & sitemap.xml fetch + analysis
│   └── trailing-slash.server.ts  # trailing-slash canonicalization check
└── styles.css            # Tailwind v4 entry + design tokens
```

---

## 3. Design System

The aesthetic is **friendly, light, and clean** — NOT a dark cyber/hacker look. White/near-white backgrounds, soft shadows, generous rounding, a single confident violet accent, and semantic green/amber/red status colors.

### 3.1 Fonts

- **Family:** `Plus Jakarta Sans` (weights 400, 500, 600, 700, 800).
- **Loading:** via `<link>` in the root route head — **never** `@import` a Google Fonts URL in `src/styles.css` (Lightning CSS resolves `@import` from the filesystem and the build breaks).
  ```tsx
  // __root.tsx head.links
  { rel: "preconnect", href: "https://fonts.googleapis.com" },
  { rel: "preconnect", href: "https://fonts.gstatic.com", crossOrigin: "anonymous" },
  { rel: "stylesheet", href: "https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" },
  ```
- Apply via `--font-sans` token in `@theme` (Tailwind v4) and set `font-family` on `body` in the base layer.
- The root `<html>` also inlines `style={{ fontFamily: "'Plus Jakarta Sans', sans-serif" }}` as a render fallback.

### 3.2 Color tokens (`src/styles.css`)

All colors are defined as complete `oklch(...)` values on `:root`, mapped into Tailwind via `@theme inline`. Reference them in components as `var(--token)` — never wrap them in another color function, and never hardcode hex/rgb in components.

```css
:root {
  --radius: 0.875rem;
  /* friendly light palette */
  --background:   oklch(0.985 0.004 250);
  --foreground:   oklch(0.28 0.025 264);
  --card:          oklch(1 0 0);
  --card-foreground: oklch(0.28 0.025 264);
  --popover:       oklch(1 0 0);
  --popover-foreground: oklch(0.28 0.025 264);
  --primary:       oklch(0.55 0.2 266);
  --primary-foreground: oklch(0.99 0.005 250);
  --secondary:     oklch(0.965 0.012 260);
  --secondary-foreground: oklch(0.38 0.04 266);
  --muted:         oklch(0.965 0.012 260);
  --muted-foreground: oklch(0.52 0.025 262);
  --accent:        oklch(0.55 0.2 266);
  --accent-foreground: oklch(0.99 0.005 250);
  --destructive:   oklch(0.58 0.22 25);
  --destructive-foreground: oklch(0.99 0.005 250);
  --border:        oklch(0.912 0.012 260);
  --input:         oklch(0.912 0.012 260);
  --ring:          oklch(0.55 0.2 266);

  /* diagnostic status colors (used everywhere pass/fail/warn) */
  --pass: oklch(0.6 0.15 152);   /* green */
  --fail: oklch(0.58 0.22 25);   /* red */
  --warn: oklch(0.74 0.15 68);   /* amber */
  --glow: oklch(0.55 0.2 266);   /* violet — scan line accent */
}
```

The `.dark` block intentionally mirrors the same light values (the tool is always light). Map everything in `@theme inline` so utilities like `bg-card`, `text-foreground`, `text-pass`, `bg-fail/10`, `border-border` resolve:

```css
@theme inline {
  --color-background: var(--background);
  --color-foreground: var(--foreground);
  --color-card: var(--card);
  --color-card-foreground: var(--card-foreground);
  --color-primary: var(--primary);
  --color-primary-foreground: var(--primary-foreground);
  --color-secondary: var(--secondary);
  --color-secondary-foreground: var(--secondary-foreground);
  --color-muted: var(--muted);
  --color-muted-foreground: var(--muted-foreground);
  --color-accent: var(--accent);
  --color-accent-foreground: var(--accent-foreground);
  --color-border: var(--border);
  --color-input: var(--input);
  --color-ring: var(--ring);
  --color-pass: var(--pass);
  --color-fail: var(--fail);
  --color-warn: var(--warn);
  --color-glow: var(--glow);
  --font-sans: "Plus Jakarta Sans", ui-sans-serif, system-ui, sans-serif;
  --radius-lg: var(--radius);
  /* …shadcn radius scale (sm/md/lg/xl/2xl/3xl/4xl) */
}
```

### 3.3 Custom utilities (`src/styles.css`)

```css
/* faint grid backdrop behind the x-ray skeleton */
@utility scan-grid {
  background-image:
    linear-gradient(to right,  color-mix(in oklab, var(--glow) 6%, transparent) 1px, transparent 1px),
    linear-gradient(to bottom, color-mix(in oklab, var(--glow) 6%, transparent) 1px, transparent 1px);
  background-size: 32px 32px;
}

@utility text-glow {
  text-shadow: 0 0 12px color-mix(in oklab, var(--glow) 25%, transparent);
}
```

Base layer:
```css
@layer base {
  * { border-color: var(--color-border); }
  body { background-color: var(--color-background); color: var(--color-foreground); font-family: var(--font-sans); }
}
```

### 3.4 Visual conventions

- **Background:** near-white (`--background`), white cards (`--card`) with `shadow-sm` and `rounded-2xl`.
- **Radius:** default `--radius: 0.875rem` (14px); cards use `rounded-2xl`, pills/inputs `rounded-xl`, chips `rounded-full`.
- **Borders:** subtle `border-border`.
- **No "eyebrow" header chip** — the hero is just the H1 + subtitle.
- **Status semantics:** `--pass` (green), `--warn` (amber), `--fail` (red), `--glow` (violet accent, used only for the scan line and x-ray chrome).
- **Hover:** rows lighten with `hover:bg-secondary/40`, cards deepen shadow on `hover:shadow-md`.
- **Transitions:** framer-motion for section reveals (opacity + small y), `motion.span` for the expand chevron rotation, animated SVG for the gauge.
- **Tailwind v4 gotchas:** bare `border` defaults to `currentColor` — always write `border-border`. Use `bg-linear-to-r` (not `bg-gradient-to-r`). Never hand-write `-webkit-backdrop-filter`.

---

## 4. Page Layout & UX (`src/routes/index.tsx`)

A centered single column, `max-w-5xl`, generous vertical padding. Sections appear progressively once a result exists.

### 4.1 Structure (top → bottom)

1. **Header** — no eyebrow. H1: `On-Page <span className="text-accent">SEO Audit</span>`. Subtitle: *"Snapshot any page, watch the scan sweep top to bottom, and get a pass/fail readiness report — with a special look at your heading structure."*
2. **URL form** — a search-bar layout: a relative icon-prefixed text input + a primary "Run X-Ray Scan" button. Shows an alert paragraph on error. Button shows a spinner + "Fetching…" while loading, otherwise a `ScanLine` icon.
3. **AuditSnapshot** — the x-ray skeleton + scan line (only while `result` exists).
4. **Score + summary row** (only when result exists & not scanning) — 3-col grid: `ScoreGauge` card (1 col) + "Audit Summary" card (2 cols) showing the final URL, HTTP status, and a qualitative message.
5. **AuxiliaryFiles** — robots.txt & sitemap.xml cards (only when result exists & not scanning).
6. **HeadingStructure** — the heading tree (only when result exists & not scanning).
7. **AuditResultsTable** — the categorized expandable checklist (only when result exists & not scanning), headed "On-Page SEO Checklist".
8. **Empty state** — a dashed card with a `ScanLine` icon and *"Enter a URL above to snapshot the page and begin the audit."*
9. **Footer** — small muted note: *"Checks run server-side from the raw HTML. JavaScript-rendered content may not be captured."*

### 4.2 Scan interaction (client state)

State: `url`, `result: AuditResult | null`, `scanning` (bool), `loading` (bool), `error`.
Two framer-motion motion values drive the sweep:
```ts
const progressRaw = useMotionValue(0);
const progress = useSpring(progressRaw, { stiffness: 60, damping: 18, mass: 0.6 });
```
On submit:
1. Set `loading`, clear `result`/`error`, set `scanning`, `progressRaw.set(0)`.
2. `await audit({ data: target })` — the server function call.
3. Set `result`. Start a 2400ms `requestAnimationFrame` loop easing `progressRaw` from 0 → 1. When it hits 1, set `scanning=false`. The server fetch may take longer than the animation; the animation only starts once results return and still plays the full sweep over the rendered skeleton. (Acceptable — the sweep is a reveal effect, not a literal progress bar.)

### 4.3 SEO metadata (route `head()`)

```ts
meta: [
  { title: "On-Page SEO Audit · Readiness Scanner" },
  { name: "description", content: "Enter any URL to snapshot it and run an on-page SEO audit — title, meta, headings, canonical, schema, links, and more — with an x-ray scan animation and a pass/fail report." },
  { property: "og:title", content: "On-Page SEO Audit · Readiness Scanner" },
  { property: "og:description", content: "Snapshot any page, watch the x-ray sweep, and get a pass/fail on-page SEO checklist." },
  { property: "og:type", content: "website" },
  { name: "twitter:card", content: "summary_large_image" },
],
```
(No meaningful absolute og:image — omit it; hosting supplies the screenshot.)

---

## 5. The X-Ray Snapshot Component (`src/components/AuditSnapshot.tsx`)

Renders a **skeleton** of the fetched page (not the real page) so the scan line has something stable to sweep over.

### 5.1 Chrome
- A "browser" top bar: three colored dots (`bg-fail/60`, `bg-warn/60`, `bg-pass/60`) + a faux address bar showing the audited URL.
- Inside: a `scan-grid` backdrop (`opacity-40`), an overflow-scroll content area (`max-h-[28rem]`, `overflow-auto`), and the scan-line overlay.

### 5.2 Skeleton builder (`buildSkeleton`)
Parse the fetched HTML with cheerio and extract a lightweight, layout-stable block list:
- **Metadata tray** (rendered first): `title`, `meta[name=description]`, `link[rel=canonical]`, `meta[property=og:image]`, `meta[name=robots]` — each as a mono-font row.
- **Body blocks:** iterate `body`'s `h1, h2, h3, h4, h5, h6, p, a, img, nav, header, footer, li, blockquote, figure, span`. Render only headings (with their text + level), `p` (if `text.length > 3`), `nav` (first ~6 words), and `img` (alt or src). Skip `a`/`li`/etc. to avoid duplication with their `p`/`nav` containers.
- **Fallback:** if no blocks found, dump the first 600 chars of `body` text as a single `p` block.

### 5.3 Skeleton rendering styles
- **img** → a dashed-border placeholder box: `[ image · {alt/src} ]`.
- **nav** → a row of up to 6 mono words.
- **p** → muted, small (11px), leading-relaxed, truncated to 320 chars + "…".
- **headings** → an `H{level}` mono badge (color-coded by level) + the heading text sized by level (`text-xl`→`text-xs` for H1→H6). Level colors: H1=`--accent`, H2=`--pass`, H3=`--warn`, H4=`--fail`, H5/H6=`--muted-foreground`.

### 5.4 The scan line (signature animation)
An absolutely-positioned `motion.div` across the content, its vertical position driven by the `progress` motion value:
```tsx
<motion.div className="pointer-events-none absolute inset-x-0 z-20"
  style={{ top: progress.get() * 100 + "%" }} initial={false}>
  <div className="h-px w-full" style={{
    background: "var(--glow)",
    boxShadow: "0 0 10px 2px color-mix(in oklab, var(--glow) 55%, transparent), 0 0 24px 6px color-mix(in oklab, var(--glow) 22%, transparent)",
  }} />
  <div className="h-24 w-full -translate-y-full" style={{
    background: "linear-gradient(to top, color-mix(in oklab, var(--glow) 14%, transparent), transparent)",
  }} />
</motion.div>
```
The line is a 1px glowing violet bar with a soft trailing gradient glow above it. While `scanning`, an edge vignette (`radial-gradient` from transparent to faint `--glow`) is layered over the content.

> Note: `style={{ top: progress.get() * 100 + "%" }}` is read once per render in this implementation. For a truly continuous sweep driven by a MotionValue, bind via a `motion.div` with `style={{ top: useTransform(progress, v => v * 100 + "%") }}`. Either works; the goal is a smooth top→bottom sweep over ~2.4s.

---

## 6. Report Components

### 6.1 `ScoreGauge.tsx`
A circular SVG gauge (viewBox 120×120, radius 52, strokeWidth 9, rotated -90°). Background track `var(--border)`; animated `motion.circle` whose `strokeDashoffset` animates from full circumference to `circ - (score/100)*circ` over 1s ease-out. Center shows the integer score (color = pass≥80 / warn≥50 / fail) and "/ 100". Beside it, three legend rows: green/red/amber dots + `{n} passed` / `{n} failed` / `{n} warnings`.

### 6.2 `HeadingStructure.tsx`
A card listing every heading in document order as an indented tree:
- Each row indented by level: `paddingLeft = 0.5 + (level-1)*1.25 rem`.
- An `H{level}` mono badge colored by level (same palette as the snapshot).
- The heading text (truncated, full text in `title`). Empty headings show `(empty)` in faint red.
- If a heading is flagged as a hierarchy violation, its badge border/background are stronger and a red ⚠ appears.
- Below the list, a red-bordered "Issues" list repeats each violation message (e.g. `H3 · H3 appears after H1 — skipping H2. Use headings in order (H1 → H2).`).
- Header: "Heading Structure" + a count line `{headings} headings · {issues} issue(s)`.

### 6.3 `AuxiliaryFiles.tsx`
Two side-by-side cards (`lg:grid-cols-2`): **robots.txt** (icon `FileText`, accent `--accent`) and **sitemap.xml** (icon `Map`, accent `--pass`).
- Header row: icon, file title, a status chip (`Missing` if status≥400 or not fetched; `Issue` if any note starts with ⚠; `OK` otherwise), the file URL (mono, truncated), and on the right the HTTP status + byte count.
- Body: a mono `content-type:` line, then a bulleted note list. Note icons: ⚠→amber `AlertTriangle`; "well-formed"/"contains … URL"/"references"→green `CheckCircle2`; otherwise a small muted dot. The ⚠ prefix is stripped before display.

### 6.4 `AuditResultsTable.tsx` — the expandable checklist
- Group checks by `category`, preserving insertion order (`Array.from(new Set(checks.map(c=>c.category)))`). Render a small uppercase tracked category heading, then its rows.
- **Each row** (`CheckRow`) is a rounded card (`rounded-2xl`, `shadow-sm`, `hover:shadow-md`) containing a full-width button:
  - A circular status icon (green `CheckCircle2` / red `XCircle` / amber `AlertTriangle`) on a faint status-tinted background (`bg-pass/10`, etc.).
  - The check title (semibold) + a small uppercase status chip (Pass/Fail/Warn) + a one-line muted detail (truncated).
  - A `ChevronRight` that **rotates 90°** when open (the "twirly arrow").
- Expanding (framer-motion height auto/0) reveals a 2-col grid: **Finding** = `check.detail`; **Why it passed** (if pass) or **Recommendation** = `check.recommendation`, or "No action needed — this check passed." in green.
- Status config object maps `pass/fail/warn` → `{ Icon, label, color, chip, ring }`.

---

## 7. Server Architecture

### 7.1 RPC boundary rules
- `seo-audit.functions.ts` is a **thin wrapper**: only imports + the `createServerFn` declaration. No sibling helpers, no config constants. (Splitting a helper out causes a runtime `ReferenceError` even when typecheck passes.)
- All heavy logic lives in `*.server.ts` files (`seo-audit.server.ts`, `auxiliary-files.server.ts`, `trailing-slash.server.ts`). Components import types and the function — never the `.server.ts` files directly.
- Read any secret via `process.env.X` **inside** `.handler()`. (There are none currently.)

### 7.2 `seo-audit.functions.ts`
```ts
import { createServerFn } from "@tanstack/react-start";
import { z } from "zod";
import { fetchPage, runAudit, type AuditResult } from "./seo-audit.server";

export const auditUrl = createServerFn({ method: "POST" })
  .validator((input) => z.string().min(3).max(500).parse(input))
  .handler(async ({ data }): Promise<AuditResult> => {
    try {
      const fetched = await fetchPage(data);
      return await runAudit(fetched);
    } catch (err) {
      const message = err instanceof Error ? err.message : String(err);
      return { /* a single failing "Audit could not complete" AuditResult */ };
    }
  });
```
The whole handler is wrapped in try/catch so a runtime error returns a real `AuditResult` (with one failing check showing the message) instead of crashing to a generic error page. **This is important:** without it, an unexpected throw surfaces to the user as the app's "This page didn't load" HTML, which looks like the whole tool is broken.

### 7.3 Data types (`seo-audit.server.ts`)
```ts
type CheckStatus = "pass" | "fail" | "warn";
interface SeoCheck { id; category; title; status: CheckStatus; detail: string; recommendation?: string; }
interface HeadingNode { level: number; text: string; }
interface HeadingIssue { index: number; level: number; text: string; message: string; }
interface AuditResult {
  url; finalUrl; httpStatus; html;
  checks: SeoCheck[]; headings: HeadingNode[]; headingIssues: HeadingIssue[];
  robotsTxt: AuxiliaryFileAnalysis | null; sitemapXml: AuxiliaryFileAnalysis | null;
  score; passed; failed; warned;
}
interface PageFetch { ok: boolean; status: number; html: string; finalUrl: string; error?: string; }
```

### 7.4 Page fetch (`fetchPage`)
- Normalize the URL: try `new URL(rawUrl)`, else prepend `https://`. Reject non-http(s) protocols.
- `fetch(url, { redirect: "follow", headers: { User-Agent: "Mozilla/5.0 (compatible; SeoAuditBot/1.0; +https://example.com/audit)", Accept: "text/html,application/xhtml+xml", "Accept-Language": "en,*;q=0.8" }, signal: AbortSignal.timeout(15000) })`.
- If `content-type` does not include `html`, return `ok:false` with an error (non-HTML). Else return `{ ok, status, html, finalUrl: res.url || url }`. On throw, return `ok:false` with the message.
- If the fetch fails, push a **failing** `fetch` check and short-circuit `finalize(...)`.

### 7.5 Score / finalize
```ts
passed = checks.filter(pass).length; failed = checks.filter(fail).length; warned = checks.filter(warn).length;
scored = checks.filter(status !== "warn").length;
score = scored === 0 ? 0 : Math.round((passed / scored) * 100);
```
**Warnings are excluded from the denominator** — a page with only passes and warnings can still score 100.

---

## 8. The SEO Checks (complete rules)

Each check pushes a `SeoCheck` with `id`, `category`, `title`, `status`, `detail`, and optional `recommendation`. Categories used: **Meta Tags, Crawlability, Technical, Headings, Content, Links, Social & Rich**. The framework reference is the Semrush on-page SEO checklist.

### 8.1 Page reachable & returns HTML — *Crawlability*
Failing only. `detail = fetch.error || "HTTP {status}"`. Recommendation: ensure URL is live, returns 200 OK, serves text/html. Short-circuits all other checks.

### 8.2 Title tag present — *Meta Tags*
`$("head title").first().text().trim()`. Pass if present. Detail: `"{title}" ({len} chars)`. Rec: add unique descriptive title (50–60 chars).

### 8.3 Meta description present — *Meta Tags*
`$('head meta[name="description"]').attr("content")?.trim()`. Pass if present. Detail includes value + char count. Rec: add ~150–160 char description.

### 8.4 Canonical URL present — *Meta Tags*
`$('head link[rel="canonical"]').attr("href")`. Pass if present. Rec: add `<link rel="canonical">` to prevent duplicate content.

### 8.5 Page is indexable — *Crawlability*
`$('head meta[name="robots"]').attr("content")`. **Fail** if it contains `noindex`, else pass. Rec if fail: remove noindex.

### 8.6 Mobile viewport meta — *Technical*
`$('head meta[name="viewport"]').attr("content")`. Pass if present. Rec: add `<meta name="viewport" content="width=device-width, initial-scale=1">`.

### 8.7 Served over HTTPS — *Technical*
`parsedUrl.protocol === "https:"`. Pass/fail. Rec: migrate to HTTPS.

### 8.8 URL clean & concise — *Technical*
`urlLen <= 75 && !hasQuery → pass`; `<=90 || hasQuery → warn`; else `fail`. `hasQuery = parsedUrl.search.length > 0`. Rec: shorten long URLs; prefer clean keyword paths over query strings.

### 8.9 Heading collection (shared by 8.10–8.13)
```ts
$("h1, h2, h3, h4, h5, h6").each((_, el) => {
  const level = Number($(el).get(0)?.tagName?.slice(1));
  const text = $(el).text().replace(/\s+/g, " ").trim();
  if (level >= 1 && level <= 6) headings.push({ level, text: truncate(text, 120) });
});
const h1s = headings.filter(h => h.level === 1);
```

### 8.10 Exactly one H1 — *Headings*
Pass iff exactly one H1. Detail: single H1 text / "No H1" / "Found N H1 tags". Rec: use exactly one H1 per page.

### 8.11 H1 has meaningful content — *Headings* (only if ≥1 H1)
Pass if no empty H1s (text length 0). Detail: "H1 contains text" / "N H1 tag(s) are empty". Rec: give H1 descriptive text, not just an image/whitespace.

### 8.12 Heading hierarchy in correct order — *Headings* ⭐ (special focus)
**This is the special heading-structure rule.** Build `headingIssues`:
- If `headings[0].level !== 1` → issue: *"Page should start with an H1, but starts with H{n}."*
- For `i = 1..end`: `prev = headings[i-1].level`, `cur = headings[i].level`. **If `cur > prev + 1`** → issue: *"H{cur} appears after H{prev} — skipping H{prev+1}. Use headings in order (H{prev} → H{prev+1})."*

**The rule (per the user's spec):** a higher H number must not appear before a lower one has been established. Concretely, going from `prev` to `cur`, you may only increase by one level at a time (`cur <= prev + 1`). Skipping a level (e.g. H1 → H3, jumping over H2) is a violation. Once a lower level has appeared, returning to it or going lower again is fine. Decreasing is always allowed (e.g. H3 → H2, H3 → H1).

Status: **fail** if any issue, **pass** if headings exist & no issues, **fail** if no headings at all. Detail: "{n} headings in valid order" / "{n} hierarchy violation(s)" / "No heading tags found."

### 8.13 Uses subheadings (H2–H6) — *Headings*
`subheadings = headings.filter(h => h.level >= 2)`. ≥2 → pass; 1 → warn; 0 → fail. Rec: break content up with H2/H3.

### 8.14 Images have alt text — *Content*
`imgs = $("img").toArray()`. `missingAlt` = images with no/empty `alt`. No images → warn; none missing → pass; all missing → fail; some missing → warn. Detail: `{have}/{total} images have alt text`. Rec: add alt to the missing ones.

### 8.15 Has internal links — *Links*
Iterate `$("a[href]")`; skip `#`, `mailto:`, `tel:`, `javascript:`. Resolve each `href` against the final URL; `internal` if same hostname. ≥1 → pass. Rec: add internal links for crawling/distribution.

### 8.16 Has outbound links — *Links*
Same loop; `external` = different hostname. ≥1 → pass, else **warn**. Rec: link to authoritative sources for credibility.

### 8.17 Open Graph tags — *Social & Rich*
Count `og:title`, `og:description`, `og:image`. 3 → pass; ≥1 → warn; 0 → fail. Detail: `{n}/3 core OG tags`. Rec: add all three.

### 8.18 Twitter card — *Social & Rich*
`$('meta[name="twitter:card"]').attr("content")`. Present → pass, else warn. Rec: add `<meta name="twitter:card" content="summary_large_image">`.

### 8.19 Structured data (JSON-LD) — *Social & Rich*
`$('script[type="application/ld+json"]').length`. >0 → pass, else warn. Rec: add Article/Organization/BreadcrumbList schema.

### 8.20 Favicon present — *Technical*
`link[rel="icon"]` / `shortcut icon` / `apple-touch-icon`. Present → pass, else warn. Rec: add a favicon.

### 8.21 Sufficient textual content (word count) — *Content* ⭐⭐ **(CRITICAL — see §9)**
Count words from **only `<p>` and `<h1>`–`<h6>` tags**, never raw HTML. ≥300 → pass; ≥100 → warn; else fail. Rec: aim for ≥300 words of original content. **Full algorithm in §9 — reproduce exactly.**

### 8.22 HTML lang attribute — *Technical*
`$("html").attr("lang")`. Present → pass, else warn. Rec: set `<html lang="en">`.

### 8.23 Trailing slash handled consistently — *Technical* (see §10)
Uses `analyzeTrailingSlash`. Wrapped in try/catch → on failure pushes a `warn` "could not complete" check.

### 8.24–8.25 robots.txt & sitemap.xml — *Crawlability* (see §11)
Fetched & analysed; wrapped together in one try/catch → on failure pushes a single `warn` "aux-files" check.

---

## 9. ⭐⭐ TEXTUAL CONTENT / WORD-COUNT MEASURE — EXACT SPECIFICATION

> **This is the most important rule to reproduce precisely.** The previous implementation counted words from the *entire raw HTML* (nav, footer, scripts, boilerplate), inflating counts to thousands. It was fixed to count **only the words inside `<p>` and heading tags**. Re-implement the following algorithm **exactly**. Do not use `$("body").text()`. Do not strip tags by hand. Use cheerio's element-scoped `.text()`.

### 9.1 Intent
The "textual content" measure should reflect **readable, author-written content** — paragraphs and headings — and explicitly **exclude** boilerplate such as navigation, footers, scripts, `<noscript>`, `<style>`, sidebars, cookie banners, etc. The count is used by check **8.21 "Sufficient textual content"**.

### 9.2 Exact algorithm (TypeScript, cheerio)

```ts
// --- Word count ---
// Count ONLY words in actual content: <p> and heading (h1–h6) tags.
// This deliberately excludes nav, footer, script, style, and other boilerplate.
const contentPieces: string[] = [];
$("p, h1, h2, h3, h4, h5, h6").each((_, el) => {
  const t = $(el).text().replace(/\s+/g, " ").trim();
  if (t) contentPieces.push(t);
});
const contentText = contentPieces.join(" ").replace(/\s+/g, " ").trim();
const wordCount = contentText ? contentText.split(" ").filter(Boolean).length : 0;
```

### 9.3 Why each step matters (do not deviate)

1. **Selector `$("p, h1, h2, h3, h4, h5, h6")`** — the *only* elements counted. This is the entire fix. If you instead select `body`, `div`, `*`, or call `$.text()`, you pull in nav/footer/script text and the count balloons (the original bug reported a page as ~5,000 words that had far fewer readable words).
2. **`$(el).text()` per matched element** — cheerio's `.text()` returns the **concatenated text of the element and all its descendants**. This means a paragraph like `<p>See our <a href="/x">guide</a> for <strong>more</strong>.</p>` yields `"See our guide for more."` exactly once. The inline children (`<a>`, `<strong>`) are **not** themselves in the selector, so their text is counted once (via the parent `<p>`), not twice.
3. **`.replace(/\s+/g, " ").trim()`** — collapses runs of whitespace (newlines, tabs, indentation from the HTML source) into single spaces and trims. Without this, source indentation inflates the token count.
4. **`if (t) contentPieces.push(t)`** — skip empty strings (e.g. an empty `<p></p>`) so they don't add stray tokens.
5. **`contentPieces.join(" ")` then `.replace(/\s+/g, " ").trim()`** — join all content blocks with a single space, then normalize once more in case any piece had trailing/leading gaps. This produces one clean space-separated string of all readable content.
6. **`contentText.split(" ").filter(Boolean).length`** — split on **single spaces** (the content is already normalized, so split on `" "`, not `/\s+/` — both work, but `" "` is what the reference uses). `filter(Boolean)` removes any empty strings from consecutive/leading/trailing spaces, so the count equals the true number of word tokens. Guard with `contentText ? ... : 0` to return `0` for pages with no `<p>`/heading text.

### 9.4 Things NOT to do (regressions to avoid)

- ❌ Do **not** count `$("body").text()` or `$.root().text()` — includes nav/footer/scripts.
- ❌ Do **not** count `$("div, span, li, a, nav, footer")` — that's the boilerplate we are excluding.
- ❌ Do **not** include `<script>` or `<style>` text — cheerio `.text()` on `<p>`/headings never reaches them anyway, but make sure no broader selector does.
- ❌ Do **not** deduplicate across nested matched elements in a way that *under*-counts. The selector only matches `<p>` and headings; inline tags inside them are not separately matched, so there is no double-count. (Edge case: a `<p>` nested inside another `<p>` is invalid HTML but would count both; acceptable and rare.)
- ❌ Do **not** use the word count for anything other than check 8.21. (The trailing-slash helper has its *own* separate, broader `pageSignature` word count over `$("body").text()` — that is intentional and unrelated; leave it as-is. It is only used to decide whether two URL variants are the same page, never displayed as the content word count.)

### 9.5 The check that consumes it

```ts
checks.push({
  id: "word-count",
  category: "Content",
  title: "Sufficient textual content",
  status: wordCount >= 300 ? STATUS.pass : wordCount >= 100 ? STATUS.warn : STATUS.fail,
  detail: `${wordCount} words (from <p> and heading tags).`,
  recommendation: wordCount < 300
    ? "Aim for at least 300 words of original, useful content on the page."
    : undefined,
});
```
The `detail` string explicitly says *"(from <p> and heading tags)"* so the user understands the source of the count. **Keep that wording.**

### 9.6 Verification (acceptance criteria for a correct re-implementation)
- Audit a page whose visible article is ~250 words but whose raw HTML includes a large nav + footer + analytics script: the count should be ≈250 (only `<p>`/heading words), **not** thousands.
- Audit a page with only headings and one paragraph of 40 words: count ≈40, status **fail** (<100).
- Audit a long article with 400 words across many `<p>`s: count ≈400, status **pass** (≥300), no recommendation.
- A page with zero `<p>` and zero headings: count = 0, status **fail**, detail "0 words (from <p> and heading tags)."

---

## 10. Trailing-Slash Check (`trailing-slash.server.ts`)

`/contact` and `/contact/` are distinct URLs in SEO terms. If both serve the same content at HTTP 200 with no redirect → duplicate content (fail). Best practice: pick one canonical form and 301 the other.

### 10.1 Procedure (`analyzeTrailingSlash(canonicalUrl, canonicalHtml, canonicalStatus)`)
1. Parse the audited URL as `base`. If unparseable → `warn`.
2. **Root path** (`/` or empty): pass — "/" and "" resolve to the same resource, no duplicate.
3. Build the **slash-toggled variant**: if the path ends with `/`, strip it (fallback `/`); else append `/`. Same host + search.
4. `fetchVariant(variantUrl)` with **`redirect: "manual"`** (so we see the raw 3xx instead of following it), 15s timeout, the SeoAuditBot UA.
5. Resolve the redirect `Location` to absolute (`locAbs`) and compute `redirectsToCanonical` (same hostname AND same pathname as `base`).

### 10.2 Decision tree
- **Network error** (`!v.ok`): `warn` — "Could not fetch the alternate form …".
- **3xx**: if `redirectsToCanonical` → `pass` for 301/308 (permanent), `warn` for temporary (rec: use 301). Else `warn` — redirect target doesn't match canonical.
- **2xx** (served directly): compute `samePage(canonicalHtml, variantHtml, …)`:
  - `samePage` returns true if (a) whitespace-normalized HTML is byte-identical, or (b) `pageSignature` (title, first H1, word count, html length) matches with title+h1 equal and (word ratio ≥0.95 **or** html-length ratio ≥0.97), or (c) both pages share the same resolved `<link rel=canonical>` href.
  - If same page **and both bodies < 100 words** → `warn` (likely a JS shell; can't confirm duplicate from raw HTML).
  - If same page (real bodies) → **`fail`** duplicate URLs. If a shared canonical tag consolidates them, append a note and soften the rec to "redirect is the robust fix".
  - If different content → `pass` (no duplicate).
- **4xx** → `pass` — only the canonical resolves; no duplicate.
- **5xx/other** → `warn`.

`pageSignature` uses `$("body").text()` (broad, intentionally — this is a similarity heuristic, NOT the displayed content word count). `display()` renders `host + pathname + search` for readable output.

---

## 11. robots.txt & sitemap.xml (`auxiliary-files.server.ts`)

### 11.1 Fetch (`fetchAuxFile`)
`fetch(url, { redirect: "follow", UA, Accept: "text/plain,application/xml,text/xml,*/*;q=0.8", timeout 12s })`. Return `{ ok, status, text, contentType }` or an error object. `looksLikeHtml(contentType, text)` = content-type includes "html" OR text matches `/<\s*(html|head|body|!doctype)[\s>]/i`.

### 11.2 robots.txt analysis (`analyzeRobotsTxt`) → `AuxiliaryFileAnalysis { url, fetched, status, contentLength, contentType, notes[] }`
- status 0 / empty → note "could not be fetched", `fetched:false`.
- If `looksLikeHtml` → note "⚠ appears to contain HTML — should be plain text…".
- status ≥400 → note about the error code.
- For valid responses: check `User-agent:`, `Allow:/Disallow:`, `Sitemap:` directives; add notes for missing ones, and a positive "well-formed" note if all three present. `disallowAll` regex `user-agent:\s*\*\s*[\s\S]*?disallow:\s*\/\s*$` → note "⚠ Disallow: / blocks all crawlers".

### 11.3 sitemap.xml analysis (`analyzeSitemapXml`)
- status 0 / empty → note "could not be fetched".
- If `looksLikeHtml` → note "⚠ appears to contain HTML — should be valid XML…".
- status ≥400 → note.
- Count `<urlset>` and `<sitemapindex>` occurrences. If neither → `parseError` "Root element is not <urlset> or <sitemapindex>". Count `<url><loc>` or `<sitemap><loc>` entries. Note if zero. Note if HTML tags (`<html|head|body>`) found inside.
- Fallback: if not XML but `<loc>` tags exist, count them.
- If valid XML with URLs: note the count, and spot-check the first `<loc>` resolves to the same host as the audited site (note a ⚠ if mismatched).

### 11.4 How they feed the checklist (`seo-audit.server.ts`)
Both files are fetched, analysed, and produce their own `SeoCheck`:
- **robots.txt check** (`robots-txt`): pass if reachable + has User-agent + not disallowAll; **fail** if HTML-injected or disallow-all; else warn. Detail highlights HTML injection explicitly ("returned HTML (HTTP {n}) instead of plain text"). Rec tailored to each failure (fix server rule / create file / remove Disallow: / add Sitemap: line).
- **sitemap.xml check** (`sitemap-xml`): pass if valid (urlset/sitemapindex) + urlCount>0 + no HTML; **fail** if HTML-injected; else warn. Detail/recommendation mirror the failure mode (serve XML / create sitemap / wrap in urlset / add loc entries).
- The two file analyses are also stored on the `AuditResult` (`robotsTxt`, `sitemapXml`) so the `AuxiliaryFiles` cards can render the full `notes[]`.
- The whole aux block is wrapped in try/catch → on failure pushes one `warn` "aux-files" check.

---

## 12. Error Handling & Robustness

- **Server-function wrapper try/catch** (§7.2): any unexpected throw returns a single-check failing `AuditResult` instead of a raw error page. **Without this, a runtime failure surfaces as the app's "This page didn't load" HTML, which the user mistakes for the tool being broken.** (This was a real reported bug.)
- **Per-check try/catch** for network-dependent checks: trailing-slash (§10) and the robots/sitemap block (§11) each degrade to a `warn` "could not complete" check on failure.
- **Root error component** (`__root.tsx`): a friendly "This page didn't load" card with "Try again" (invalidates router + resets error) and "Go home". This is the fallback for *routing/render* errors, not audit errors.
- **404** component: large "404" + "Page not found" + Go home link.
- The generic error-page HTML shown in the user's report ("`<title>This page didn't load</title>` …") is exactly this root `ErrorComponent` — it appeared during debugging precisely because a server-function throw was bypassing the try/catch. Ensure the try/catch stays in place.

---

## 13. Build / Runtime Constraints (recreate faithfully)

- **Edge runtime, `nodejs_compat`.** No `child_process`, `sharp`, `canvas`, `puppeteer`, `fs.watch`. Safe: `fetch`, `crypto`, `Buffer`, `URL`, cheerio (pure JS).
- **Bundling:** all npm packages bundle at build time. Never set `ssr.external`/`resolve.external`.
- **Tailwind v4:** single `@import "tailwindcss" source(none)` + `@source "../src"` at top of `src/styles.css`; no `tailwind.config.js`; no `@tailwind` directives; custom utilities via `@utility`; shadcn tokens via `@theme inline`.
- **Fonts:** load Plus Jakarta Sans via `<link>` in `__root.tsx` head, **never** `@import` a font URL in `src/styles.css`.
- **Server-function file splitting:** `.functions.ts` = thin wrapper only. Helpers in `.server.ts`. (A helper sibling in the same `.functions.ts` throws `ReferenceError` at runtime even when typecheck passes.)
- **No `react-router-dom`, no `src/pages/`, no Next/Remix patterns, no `"use server"`.** Single route in `src/routes/`.

---

## 14. Accessibility & SEO of the Tool Itself

- Single H1 in the header (no eyebrow chip).
- The input has `aria-label="Page URL to audit"`; the error paragraph has `role="alert"`.
- Expandable rows use `aria-expanded` on the toggle button.
- Route `head()` sets a unique descriptive title + meta description + og:* + twitter:card.
- Lazy, semantic HTML; semantic design tokens throughout (no hardcoded colors in components).

---

## 15. Recreation Checklist (for Codex)

1. Scaffold TanStack Start v1 + Tailwind v4. Install `cheerio`, `framer-motion`, `lucide-react`, `zod`.
2. Write `src/styles.css` with the §3.2 tokens, `@theme inline` mappings, `scan-grid` / `text-glow` utilities, base layer.
3. Add Plus Jakarta Sans `<link>`s in `__root.tsx`; set `<html lang="en">` + inline font fallback; keep the 404 + Error components.
4. Create `lib/seo-audit.server.ts` (types, `fetchPage`, `runAudit`, `finalize`) with **all 25 checks**. Reproduce **§9 word-count exactly**. Reproduce §8.12 heading-order rule exactly.
5. Create `lib/trailing-slash.server.ts` (§10) and `lib/auxiliary-files.server.ts` (§11).
6. Create `lib/seo-audit.functions.ts` — **thin wrapper, full try/catch** (§7.2).
7. Build the 5 components (§5, §6) using only semantic tokens; wire the scan-line motion values (§4.2).
8. Assemble `routes/index.tsx` (§4) with the progressive section reveal.
9. Verify: the word count returns ~250 (not thousands) on a page with boilerplate; the heading-order check flags H1→H3 skips; trailing-slash reports a duplicate on a site that 200s both forms; robots/sitemap cards show ⚠ notes when HTML is served instead of the file.
10. Confirm a server-function throw returns the "Audit could not complete" check, not the "This page didn't load" page (§12).

---

*End of specification.*
