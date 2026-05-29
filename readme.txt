=== Proofing Pins ===
Contributors: lovedeep5, punjabideveloper
Tags: feedback, proofing, comments, client review, elementor
Requires at least: 6.3
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pin-point client feedback on any page with screenshots. Optional AI suggestions, Microsoft Teams notifications, 1-click Apply for Elementor.

== Description ==

Proofing Pins lets clients and reviewers click anywhere on your site's frontend to drop a comment pinned to that exact spot. Each pin is captured with a viewport screenshot so developers see what the reviewer saw. All comments live in a focused Proofing dashboard in wp-admin — no more "the button under the banner thing" emails.

**Watch the 2-minute tutorial:**

https://www.youtube.com/watch?v=8UJX0GmM79k

**Core features**

* **Pin-point comments:** reviewers click, type, submit — pin is saved with a screenshot that has the pin baked into the image at the click location.
* **Modern capture:** uses the html-to-image library (SVG foreignObject renderer) for reliable screenshots even on Elementor or block-theme pages.
* **Responsive anchoring:** pins are stored against the clicked element (selector + percentage-within-element), so they follow the element across viewport sizes.
* **Threaded replies:** native WordPress comments attached to each pin for discussion between reviewers and developers.
* **Admin dashboard:** list + grid views, status workflow (Open / In Progress / Resolved / Archived), bulk actions.
* **Guest comments:** optional — let logged-out visitors leave pins with a one-time name/email prompt (cookie-remembered for 30 days), with honeypot + per-IP rate limiting.
* **AI suggestions (optional, BYO key):** bring your own OpenAI, Anthropic, Google Gemini, or OpenRouter API key. Each pin gets a one-paragraph suggestion on what to change.
* **Elementor-aware Apply button:** when the AI proposes an allowlisted change (heading text, button text, color), a before/after preview appears with an "Apply to Elementor" button. Applies the change to the live page, saves a WordPress revision, one-click revert available.
* **Microsoft Teams notifications (optional):** post pin activity directly to a Teams channel using a Workflow webhook. Pick which events you want — new pins, replies, and per-status transitions (Open / In Progress / Resolved / Archived). Each notification is an Adaptive Card with the comment, author, page, status, and (where small enough) the screenshot. Webhook URL is stored encrypted at rest; one-click "Send test message" verifies the wire.

**Data, privacy, and third-party services**

* The plugin does not send any data to third parties by default.
* The AI feature is **opt-in**. You provide your own API key; requests go directly from your WordPress server to the provider you configure (OpenAI, Anthropic, Google, or OpenRouter). No data is sent to the plugin author. When enabled, each new pin's comment text, captured element HTML, and metadata are sent to the configured provider so it can generate a suggestion — consult your provider's privacy policy.
* The Microsoft Teams integration is **opt-in**. You provide your own Teams Workflow webhook URL; notifications are posted directly from your WordPress server to that webhook (typically a Microsoft-hosted Azure Logic Apps endpoint). No data is sent to the plugin author. Payloads include the pin comment, author name, page URL, status, and a heavily compressed thumbnail when one fits — see "External Services" below for the exact contract.
* Screenshots are stored locally in your WordPress uploads folder — never uploaded elsewhere.
* Guest identities (name + email) are stored in a cookie (`proopin_guest_identity`) for 30 days only on the visitor's own browser.
* When guest commenting is enabled, the plugin stores a short hash of each guest submitter's IP address (first 16 characters of the MD5 hash) for the sole purpose of rate-limiting abusive submissions. Raw IP addresses are never stored.

== Installation ==

1. Upload the `proofing-pins` folder to `/wp-content/plugins/` or install through the WordPress Plugins screen.
2. Activate the plugin through the Plugins screen in WordPress.
3. Visit **Proofing → Settings** to configure the floating-button position, brand color, and guest-comments toggle.
4. (Optional) Visit **Proofing → AI Integration** to enable AI suggestions — enter your provider API key and pick a model.
5. (Optional) Visit **Proofing → Teams Integration** to enable Microsoft Teams notifications — paste your Workflow webhook URL and pick which events you want to be notified about.

== Frequently Asked Questions ==

= Does this work with Elementor? =

Yes. The plugin detects Elementor widgets, links directly to them in the editor, and (when AI is configured) can propose and 1-click apply text / color changes to heading, button, and text-editor widgets.

= Does it work with block themes? =

Yes. Pin capture and rendering work with Twenty Twenty-Four / Twenty Twenty-Five and other block themes.

= Do I need an AI subscription? =

No. AI suggestions are entirely optional. The pin-and-comment workflow works without them.

= Who can leave pins? =

By default, any logged-in user with the `proopin_create_pin` capability. You can enable guest pins in Settings; guests see a one-time name/email prompt before posting.

= How are API keys stored? =

Encrypted at rest with AES-256-CBC using a key derived from `AUTH_KEY`. The stored setting row is non-autoloaded. The raw key is never returned by the REST API — only a masked preview.

== Screenshots ==

1. Reviewer view — floating button, click-to-pin mode, composer popover.
2. Admin dashboard list view with status chips and page filters.
3. Pin detail — captured screenshot with baked-in pin, threaded replies, status control, AI suggestion.
4. AI Integration settings — provider, model, API key with test-connection.
5. Apply to Elementor — before/after preview with revert.

== Changelog ==

= 0.1.2 =
* New: tutorial video link in the admin dashboard header (opens on YouTube in a new tab) so first-time users can find the walkthrough without leaving wp-admin.
* New: tutorial video auto-embedded on the WordPress.org plugin listing page.

= 0.1.1 =
* New: Microsoft Teams integration. Post pin activity (new pin, replies, status changes) to a Teams channel via a Workflow webhook. Opt-in, off by default; webhook URL stored encrypted at rest; per-event toggles; one-click "Send test message".
* Security: tighten REST API permission callbacks. `GET /pins/{id}` now requires the caller to be a manager or the pin's author; `GET /pins` requires non-managers to scope the listing to a single `page_url` and never returns archived pins to non-managers; `POST /pins/{id}/replies` is restricted to logged-in users with the create capability (guests can no longer reply).
* Security: apply identical honeypot + per-IP rate-limit + identity sanitization to any guest-driven write request.
* Security: guest rate-limit no longer trusts client-supplied `X-Forwarded-For` (spoofable — would let attackers bypass rate-limits and inflate `wp_options` with throw-away transient rows). Only `REMOTE_ADDR` is used by default; sites behind a trusted reverse proxy can hook the new `proofingpins_guest_ip` filter to use their proxy's real-client-IP header.
* Cleanup: deleting a pin now also clears any pending AI-suggestion cron event scheduled for that pin (no more orphan cron rows).
* Cleanup: uninstall now also clears pending AI cron events and sweeps the plugin's transients (per-IP rate-limit + cached provider model lists), in addition to deleting pin posts, screenshots, thumbnails, replies, settings, and capabilities.
* Rename: all internal identifiers (option names, capabilities, post type, post statuses, post meta keys, transients, comment type, script handles, JS globals, CSS classes, cookies, query parameters) migrated from the `pp_` / `pp-` / `PP_` prefix to the longer `proopin_` / `proopin-` / `PROOPIN_` prefix to satisfy the WordPress.org 4-character-minimum prefix requirement and prevent collisions with other plugins.

= 0.1.0 =
* Initial release.
* Pin capture via html-to-image with element-anchored responsive positioning.
* Admin dashboard (list/grid), status workflow, bulk actions.
* Guest comments with identity cookie + rate limit + honeypot.
* AI suggestions (OpenAI / Anthropic / Gemini / OpenRouter) with dynamic model discovery.
* Elementor-aware suggestions and 1-click Apply / Revert for allowlisted widget settings.

== Upgrade Notice ==

= 0.1.2 =
Adds an in-dashboard "Watch tutorial" link and embeds the video on the WP.org listing page. No code or data changes affecting existing pins.

= 0.1.1 =
Security hardening for REST permission callbacks and a prefix rename (`pp_` → `proopin_`) to meet WordPress.org guidelines. Existing data from 0.1.0 testing installs will not be migrated; activate fresh after upgrading.

= 0.1.0 =
Initial release.

== External Services ==

This plugin can connect to third-party services only when their corresponding integration is explicitly enabled. **Every integration is opt-in and disabled by default.** No external connections are made unless you turn on a feature and provide its credential (API key for AI, webhook URL for Teams) in the plugin's settings screens.

When AI is enabled and a new pin is created (or you manually trigger a suggestion), the following data is sent from your WordPress server directly to your configured AI provider: the pin's comment text, the page URL, the page title, the clicked element's tag name and a short HTML snippet, and the element's CSS selector. No data is sent to the plugin author at any time.

When Microsoft Teams is enabled, the following data is sent from your WordPress server directly to the Workflow webhook you configured (typically a Microsoft-hosted endpoint at logic.azure.com): the pin's comment text, the author's display name (or guest name), the page URL, the pin status, the event type, and — only when it fits inside Teams' card-size budget — a heavily compressed JPEG thumbnail of the pin's screenshot. The webhook URL is supplied by you and points to whatever channel/workflow you set up in Teams; the plugin does not contact any other Microsoft endpoint. No data is sent to the plugin author at any time.

Only the providers you configure are contacted. Each supported service is documented below.

= OpenAI =

Used for: generating AI pin suggestions and listing available models.
Data sent: pin comment, page URL, element context (tag, HTML snippet, selector).
Sent when: AI suggestions are enabled and a pin is created (if auto-suggest is on), or when you click "Regenerate suggestion" in the pin detail view.

* Service: https://openai.com/
* Terms of Use: https://openai.com/policies/terms-of-use
* Privacy Policy: https://openai.com/policies/privacy-policy

= Anthropic =

Used for: generating AI pin suggestions and listing available models.
Data sent: pin comment, page URL, element context (tag, HTML snippet, selector).
Sent when: AI suggestions are enabled and a pin is created (if auto-suggest is on), or when you click "Regenerate suggestion".

* Service: https://www.anthropic.com/
* Terms of Service: https://www.anthropic.com/legal/consumer-terms
* Privacy Policy: https://www.anthropic.com/legal/privacy

= Google Gemini (Generative Language API) =

Used for: generating AI pin suggestions and listing available models via Google's Generative Language API (generativelanguage.googleapis.com).
Data sent: pin comment, page URL, element context (tag, HTML snippet, selector).
Sent when: AI suggestions are enabled and a pin is created (if auto-suggest is on), or when you click "Regenerate suggestion".

* Service: https://ai.google.dev/
* Terms of Service: https://ai.google.dev/gemini-api/terms
* Privacy Policy: https://policies.google.com/privacy

= OpenRouter =

Used for: generating AI pin suggestions and listing available models via the OpenRouter gateway (openrouter.ai).
Data sent: pin comment, page URL, element context (tag, HTML snippet, selector).
Sent when: AI suggestions are enabled and a pin is created (if auto-suggest is on), or when you click "Regenerate suggestion".

* Service: https://openrouter.ai/
* Terms of Service: https://openrouter.ai/terms
* Privacy Policy: https://openrouter.ai/privacy

= Microsoft Teams (via user-configured Workflow webhook) =

Used for: posting pin activity (new pin, new reply, status change) to a Microsoft Teams channel as Adaptive Cards.
Data sent: pin comment, author display name (or guest name), page URL, pin status, event type, and — when small enough to fit Teams' card payload budget — a heavily compressed JPEG thumbnail of the pin screenshot.
Sent when: the Teams integration is enabled, a webhook URL is configured, and the corresponding event happens on a pin. Also sent on demand when the admin clicks "Send test message" on the Teams settings screen.

The destination URL is provided entirely by you. The plugin only posts to whichever Workflow webhook URL you save (typically a Microsoft-hosted endpoint at https://*.logic.azure.com/ — created by Teams' "Post to a channel when a webhook request is received" workflow template). The webhook URL is stored encrypted at rest.

* Service: https://www.microsoft.com/en-us/microsoft-teams/group-chat-software
* Terms of Service: https://www.microsoft.com/en-us/servicesagreement/
* Privacy Statement: https://privacy.microsoft.com/en-us/privacystatement

== Third-Party Libraries ==

* **html-to-image** (bubkoo/html-to-image, MIT License) — bundled as `assets/js/html-to-image.min.js`. Used for client-side viewport screenshot generation.
