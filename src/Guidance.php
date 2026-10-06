<?php
declare(strict_types=1);
namespace Audit;
final class Guidance {
    public static function forReport(array $report): array {
        $sections=[
            'Crawlability'=>['why'=>'Search systems must be able to discover, fetch and interpret a page before on-page improvements can help. Indexing directives, crawler rules and sitemaps serve different purposes.','verify'=>'Inspect the live URL with a search engine URL inspection tool after fixing access rules. A successful HTTP response or sitemap entry alone does not guarantee indexing.'],
            'Meta Tags'=>['why'=>'Titles, descriptions and canonical URLs help describe the page and identify its preferred address. They should agree with the visible purpose of the page.','verify'=>'Check the live HTML after publishing and confirm the title and canonical are unique and intentional. Search engines may rewrite displayed titles and snippets.'],
            'Technical'=>['why'=>'Consistent URLs, secure delivery, language and viewport declarations make the page easier to interpret and use. These checks do not measure layout or Core Web Vitals.','verify'=>'Test the canonical URL on mobile and desktop. Check redirects in browser network tools and test performance separately.'],
            'Headings'=>['why'=>'Headings describe the structure of the document for readers, assistive technology and automated extraction. They should describe sections rather than merely control font size.','verify'=>'Read the heading outline without the body text. It should communicate the topic and logical order. Check the rendered accessibility tree as well as source HTML.'],
            'Content'=>['why'=>'Content must serve the page purpose and be available in a form that machines and people can understand. Word count is a rough signal, not a ranking target.','verify'=>'Compare the visible page with the initial HTML. Review whether the content answers the intended question and inspect informative images for meaningful alternative text.'],
            'Links'=>['why'=>'Links connect readers and crawlers to related content. Descriptive anchors and relevant destinations matter more than raw link totals.','verify'=>'Test important links and inspect their anchors, redirects and destination status. The basic report counts links but does not visit their destinations.'],
            'Social & Rich'=>['why'=>'Social metadata supports shared-page previews. Structured data explicitly describes entities, but valid JSON alone does not establish eligibility for rich results.','verify'=>'Validate relevant schema in an official rich-result testing tool, confirm it matches visible content, and preview social sharing.'],
            'GEO signals'=>['why'=>'Clear structure, identifiable authorship and accurate dates can make information easier to interpret. These observable signals do not predict inclusion or citations in an AI answer.','verify'=>'Review the accuracy and usefulness of the content with a human editor. Separately check any AI provider access policies and monitor real citations over time.'],
        ];
        $steps=[
            'title'=>['Write a unique title that describes this page specifically.','Put the main topic early and avoid repeated boilerplate.','Check the rendered title; character length is only a display guideline.'],
            'description'=>['Write a concise summary explaining the benefit of visiting this page.','Avoid copying the same description across unrelated pages.','Remember a search engine may select a different snippet.'],
            'canonical'=>['Choose the preferred public URL for this content.','Use one valid absolute canonical in the document head.','Align redirects, internal links and sitemap entries with the preferred URL.'],
            'indexable'=>['Inspect robots and Googlebot meta tags plus X-Robots-Tag response headers.','Remove unintended noindex or none directives for a page intended for search.','Allow crawling so search engines can read the changed directive, then request inspection.'],
            'followable'=>['Check for nofollow or none at page level in metadata and headers.','Remove unintended blanket restrictions; review individual sponsored or untrusted links separately.','Verify crawlable anchor elements still point to the intended destinations.'],
            'robots-txt'=>['Open robots.txt on the same origin as the audited page.','Check the matching user-agent group and the most specific Allow/Disallow rule for this path.','Ensure the server returns text rather than an HTML error or home page.'],
            'sitemap-xml'=>['Review flagged URLs below before deciding whether they belong in search.','Keep preferred, public URLs in a valid sitemap and reference it from robots.txt.','Test XML syntax and submit the sitemap in webmaster tools; submission is not an indexing guarantee.'],
            'content-accessibility'=>['Compare initial HTML with the visible rendered page.','Expose meaningful text and semantic headings in crawlable HTML where possible.','Use URL inspection to check rendered content before concluding that the page cannot be indexed.'],
            'word-count'=>['Assess the page purpose before adding text.','Count useful explanatory content rather than navigation or legal boilerplate.','Do not pad a page just to pass a numerical threshold.'],
            'alt'=>['Start with missing attributes in the image inventory below.','Describe the information conveyed by informative images in context.','Use an empty alt attribute for purely decorative images; do not stuff keywords.'],
            'hierarchy'=>['Start with a clear main heading.','Nest subsections one heading level at a time.','Use CSS for appearance rather than choosing heading levels by visual size.'],
            'h1'=>['Choose a main heading that describes the page purpose.','Keep the heading visible and meaningful.','Review repeated H1 elements in templates; multiple H1s are not an automatic ranking penalty.'],
            'h1-content'=>['Add meaningful text to the main heading.','Do not rely on a background image to convey the heading.','Check that a template is not inserting an empty heading element.'],
            'subheadings'=>['Divide substantial content into useful sections.','Write descriptive H2 and H3 headings.','Avoid adding unnecessary headings to very short pages just to pass this check.'],
            'internal'=>['Link to relevant pages using real anchor elements with href values.','Use descriptive link text.','Check important destination pages separately for errors or redirects.'],
            'external'=>['Cite reliable primary sources where they help the reader.','Review source quality and relevance manually.','External links are not required on every type of page.'],
            'schema'=>['Fix JSON syntax errors first.','Use types and properties that accurately match visible content.','Validate the applicable rich-result requirements separately.'],
            'og'=>['Set og:title, og:description and an absolute og:image URL.','Make sure the image is accessible and suitable for sharing.','Preview the result in the relevant social platform.'],
            'twitter'=>['Set an appropriate twitter:card value.','Review the related title, description and image.','Check the shared preview on the destination platform.'],
            'https'=>['Enable a valid certificate on the public host.','Redirect HTTP to the preferred HTTPS URL.','Update canonical links, sitemaps and internal URLs consistently.'],
            'viewport'=>['Declare width=device-width and initial-scale=1.','Test the actual layout at narrow widths.','Avoid restricting zoom or hiding important content on mobile.'],
            'url'=>['Prefer stable, human-readable paths.','Do not change an established URL solely to satisfy a length guideline.','If changing URLs, map permanent redirects and update internal references.'],
            'favicon'=>['Declare a suitable favicon URL in the head.','Confirm the resource returns successfully.','Check its appearance at small sizes.'],
            'lang'=>['Set the document language on the html element.','Use a valid language code matching the page text.','Mark passages in other languages where appropriate.'],
            'trailing-slash'=>['Compare both URL variants and their redirect chains.','Choose one preferred form when they serve the same content.','Use permanent redirects where appropriate and align canonical and sitemap references.'],
            'geo-structure'=>['Make important answers understandable without scripts where practical.','Use descriptive headings and clearly structured paragraphs.','Verify the content is useful and accurate rather than optimising for a speculative AI score.'],
            'geo-author'=>['Identify the responsible author or organisation where relevant.','Provide verifiable expertise and contact or about information.','Ensure metadata describes the visible author accurately.'],
            'geo-date'=>['Use accurate publication and modification dates where relevant.','Keep visible and machine-readable dates consistent.','Do not update dates without a meaningful content change.'],
            'geo-snippet'=>['Review snippet restrictions against the publishing policy.','Remove unintended nosnippet or max-snippet:0 restrictions.','Treat AI training controls and search appearance controls separately.'],
        ];
        $checks=[];
        foreach($report['checks'] ?? [] as $check) {
            $section=$sections[$check['category']] ?? ['why'=>'Review the finding in context.','verify'=>'Check the live page after making changes.'];
            $checks[$check['id']]=['meaning'=>$section['why'],'steps'=>$steps[$check['id']] ?? ['Review the reported finding.','Apply the recommendation if appropriate for this page.','Run a new audit after publishing.'],'verify'=>$section['verify'],'priority'=>$check['status']==='fail'?($check['category']==='Crawlability'?'Urgent review':'Fix next'):($check['status']==='warn'?'Manual review':'Maintain')];
        }
        return ['sections'=>$sections,'checks'=>$checks];
    }
}
