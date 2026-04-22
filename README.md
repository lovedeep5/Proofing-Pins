# Proofing Pins

WordPress plugin for pin-point client feedback. Click anywhere on a page to drop a comment, see it pinned on a viewport screenshot in the admin dashboard.

**Author:** [Lovedeep](https://flaircross.com)
**License:** GPL-2.0-or-later

## Features

- **Pin-point comments** with viewport screenshots (pin baked into the image)
- **Responsive anchoring** — pins stored against the clicked element; follow it across viewport sizes
- **Threaded replies** via native WordPress comments
- **Admin dashboard** — list + grid views, status workflow, bulk actions
- **Guest comments** (optional) — one-time name/email prompt, 30-day cookie, honeypot + per-IP rate limiting
- **AI suggestions** (optional, bring your own key) — OpenAI / Anthropic / Google Gemini / OpenRouter
- **Elementor-aware** — deep links to the exact widget + 1-click Apply for text / color changes with before-after preview + revert

## Installation

1. Download this repo as a zip, or clone it.
2. Place the `proofing-pins/` folder under your WordPress site's `wp-content/plugins/`.
3. Activate from **Plugins** in wp-admin.
4. Configure at **Proofing → Settings** and optionally **Proofing → AI Integration**.

## Requirements

- WordPress 6.2+
- PHP 7.4+

## Third-Party Libraries

- [html-to-image](https://github.com/bubkoo/html-to-image) (MIT License) — bundled at `assets/js/html-to-image.min.js` for client-side screenshot capture.

## Documentation

Full plugin description, FAQ, and changelog live in [`readme.txt`](./readme.txt) (WordPress.org plugin-directory format).

## Status

v0.1.0 — first release. Planned for submission to the [WordPress.org plugin directory](https://wordpress.org/plugins/).
