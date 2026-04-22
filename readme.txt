=== Proofing Pins ===
Contributors: lovedeep5
Tags: feedback, proofing, comments, client review, elementor
Requires at least: 6.3
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pin-point client feedback on any page with screenshots. Optional AI suggestions and 1-click Apply for Elementor widgets.

== Description ==

Proofing Pins lets clients and reviewers click anywhere on your site's frontend to drop a comment pinned to that exact spot. Each pin is captured with a viewport screenshot so developers see what the reviewer saw. All comments live in a focused Proofing dashboard in wp-admin — no more "the button under the banner thing" emails.

**Core features**

* **Pin-point comments:** reviewers click, type, submit — pin is saved with a screenshot that has the pin baked into the image at the click location.
* **Modern capture:** uses the html-to-image library (SVG foreignObject renderer) for reliable screenshots even on Elementor or block-theme pages.
* **Responsive anchoring:** pins are stored against the clicked element (selector + percentage-within-element), so they follow the element across viewport sizes.
* **Threaded replies:** native WordPress comments attached to each pin for discussion between reviewers and developers.
* **Admin dashboard:** list + grid views, status workflow (Open / In Progress / Resolved / Archived), bulk actions.
* **Guest comments:** optional — let logged-out visitors leave pins with a one-time name/email prompt (cookie-remembered for 30 days), with honeypot + per-IP rate limiting.
* **AI suggestions (optional, BYO key):** bring your own OpenAI, Anthropic, Google Gemini, or OpenRouter API key. Each pin gets a one-paragraph suggestion on what to change.
* **Elementor-aware Apply button:** when the AI proposes an allowlisted change (heading text, button text, color), a before/after preview appears with an "Apply to Elementor" button. Applies the change to the live page, saves a WordPress revision, one-click revert available.

**Data, privacy, and third-party services**

* The plugin does not send any data to third parties by default.
* The AI feature is **opt-in**. You provide your own API key; requests go directly from your WordPress server to the provider you configure (OpenAI, Anthropic, Google, or OpenRouter). No data is sent to the plugin author. When enabled, each new pin's comment text, captured element HTML, and metadata are sent to the configured provider so it can generate a suggestion — consult your provider's privacy policy.
* Screenshots are stored locally in your WordPress uploads folder — never uploaded elsewhere.
* Guest identities (name + email) are stored in a cookie (`pp_guest_identity`) for 30 days only on the visitor's own browser.
* When guest commenting is enabled, the plugin stores a short hash of each guest submitter's IP address (first 16 characters of the MD5 hash) for the sole purpose of rate-limiting abusive submissions. Raw IP addresses are never stored.

== Installation ==

1. Upload the `proofing-pins` folder to `/wp-content/plugins/` or install through the WordPress Plugins screen.
2. Activate the plugin through the Plugins screen in WordPress.
3. Visit **Proofing → Settings** to configure the floating-button position, brand color, and guest-comments toggle.
4. (Optional) Visit **Proofing → AI Integration** to enable AI suggestions — enter your provider API key and pick a model.

== Frequently Asked Questions ==

= Does this work with Elementor? =

Yes. The plugin detects Elementor widgets, links directly to them in the editor, and (when AI is configured) can propose and 1-click apply text / color changes to heading, button, and text-editor widgets.

= Does it work with block themes? =

Yes. Pin capture and rendering work with Twenty Twenty-Four / Twenty Twenty-Five and other block themes.

= Do I need an AI subscription? =

No. AI suggestions are entirely optional. The pin-and-comment workflow works without them.

= Who can leave pins? =

By default, any logged-in user with the `pp_create_pin` capability. You can enable guest pins in Settings; guests see a one-time name/email prompt before posting.

= How are API keys stored? =

Encrypted at rest with AES-256-CBC using a key derived from `AUTH_KEY`. The stored setting row is non-autoloaded. The raw key is never returned by the REST API — only a masked preview.

== Screenshots ==

1. Reviewer view — floating button, click-to-pin mode, composer popover.
2. Admin dashboard list view with status chips and page filters.
3. Pin detail — captured screenshot with baked-in pin, threaded replies, status control, AI suggestion.
4. AI Integration settings — provider, model, API key with test-connection.
5. Apply to Elementor — before/after preview with revert.

== Changelog ==

= 0.1.0 =
* Initial release.
* Pin capture via html-to-image with element-anchored responsive positioning.
* Admin dashboard (list/grid), status workflow, bulk actions.
* Guest comments with identity cookie + rate limit + honeypot.
* AI suggestions (OpenAI / Anthropic / Gemini / OpenRouter) with dynamic model discovery.
* Elementor-aware suggestions and 1-click Apply / Revert for allowlisted widget settings.

== Upgrade Notice ==

= 0.1.0 =
Initial release.

== Third-Party Libraries ==

* **html-to-image** (bubkoo/html-to-image, MIT License) — bundled as `assets/js/html-to-image.min.js`. Used for client-side viewport screenshot generation.
