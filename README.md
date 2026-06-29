=== AltGenix AI Image SEO ===
Contributors: abdulkabeerdeveloper2530
Tags: auto alt text, seo, image optimization, openai, claude
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AltGenix auto-generates SEO alt text & renames image files using Google Gemini, OpenAI GPT-4o, or Anthropic Claude — with smart AI fallback.

== Description ==

Welcome to **AltGenix AI Image SEO**, an enterprise-level, smart SEO automation tool for WordPress.

Stop wasting hours writing alt text and renaming image files manually. Our plugin uses the power of Google's Gemini Vision AI to instantly analyze your images, write descriptive SEO alt tags, and rename your physical files for maximum search engine visibility.

Unlike other plugins, it comes with a **Smart Fallback Engine**. It dynamically discovers available AI models (like Gemini 3 Flash, Gemini 2.5, Gemma, and Robotics), and if one model reaches its free limit, it seamlessly routes the request to the next available model without interrupting your workflow.

### 🚀 Key Features:
* **Choose Your AI Engine:** Works with Google Gemini, OpenAI (GPT-4o / GPT-4o-mini), or Anthropic Claude (Opus, Sonnet, Haiku). Bring your own API key for any provider.
* **Multilingual Output:** Generates Alt Text, Titles, Captions & Descriptions in your site's language automatically, or pick any of 30+ languages manually (Portuguese, Spanish, French, German, Arabic, Japanese, and more).
* **Smart Alt Text Generation:** Analyzes image context and writes perfect, keyword-rich alt texts.
* **Physical File Renaming:** Automatically renames physical image files (e.g., `IMG_123.jpg` to `glossy-red-car.jpg`) including all generated thumbnails!
* **Zero Configuration API Setup:** Just enter your Google AI Studio API key, and the plugin auto-discovers and verifies all available Vision models.
* **Waterfall Fallback Mechanism:** Automatically switches to backup AI models (Gemma, Robotics, etc.) if your primary model exhausts its free limits.
* **Smart Error Handling:** Professional error translation catching permanent issues (Format, Size) vs recoverable issues (Rate Limits, Timeouts).
* **Bulk Auto-Tagging:** Process all your pending images with a single click in the background via secure AJAX.
* **100% Privacy & Security:** Built strictly according to WordPress coding standards. No external tracking, no bloated CDN assets.

Take your website's Image SEO to the next level on autopilot!

== Installation ==

1. Upload the `altgenix-ai-image-seo` folder to the `/wp-content/plugins/` directory, or upload the ZIP file through the WordPress plugins screen.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to the newly created **AltGenix AI** menu in your WordPress dashboard.
4. Enter your Google AI Studio API Key and click **Save Settings**.
5. You're all set! Start uploading images or bulk process your existing library.

== Frequently Asked Questions ==

= Do I need a paid API key? =
No! You can use Google AI Studio's free tier. Our smart fallback mechanism will intelligently cycle through available free models to maximize your free quota.

= Does it rename the actual file on my server? =
Yes! This is a premium agency-level feature. It not only updates the database but also physically renames the main image and all its registered thumbnails for maximum SEO impact.

= What happens if an image is rejected by the AI? =
The plugin has a professional error-catching system. It will display the exact reason (e.g., "File Too Large", "Unsupported Format", or "Content Blocked") right in your dashboard so you know exactly what to fix.

= Is it safe for my existing website? =
Absolutely. AltGenix AI Image SEO is built with strict WordPress security standards, using nonces, capability checks, and data sanitization to ensure your site remains secure.

== Screenshots ==

1. **Dashboard Overview:** A clean, modern UI to manage your API key and view processed/failed images.
2. **Bulk Processing:** The bulk auto-tagging feature in action.
3. **Smart Fallback API:** Showing the auto-discovery of multiple Gemini and Gemma models.

== Changelog ==

= 1.1.0 =
* New: Multi-provider support — choose Google Gemini, OpenAI (GPT-4o), or Anthropic Claude as your image analysis engine, each with its own API key.
* New: AI Provider selector in Settings, with per-provider key validation and "get your key" links.
* Improvement: Per-provider model fallback — if one model is rate-limited, the plugin automatically tries the next.
* Improvement: New uploads are now auto-tagged within seconds while you stay on the page, instead of waiting for a WordPress cron page reload. The upload request no longer performs any AI work, so uploads are noticeably faster.
* Improvement: Clearer verification errors — the plugin now tells you the real reason a key was rejected (e.g. an invalid key vs. a server SSL/network problem vs. Google Gemini being unavailable in your region), instead of always saying "Invalid API Key".
* Fix: Added a concurrency lock so a single image is never processed (and billed) twice when the background processor and cron fallback overlap.
* Fix: A key that fails verification is no longer saved — the field is cleared so it can't get stuck showing a non-working key.
* Fix: Switching the AI Provider now clears the key field so a previous provider's key never carries over.
* Fix: OpenAI/Claude key verification now performs a real generation check, so a key with no credits/quota is caught upfront (with an "add billing/credits" message) instead of appearing active and silently falling back to filename mode.

= 1.0.2 =
* New: Multilingual output — generated text now matches your site language automatically (Auto-detect), with a manual picker for 30+ languages.
* Fix: The "Custom Prompt Context" advanced setting is now actually sent to the AI (it was previously saved but ignored).
* Improvement: Saving settings no longer re-verifies the API key on every save. It only re-checks when the key changes, so a brief network hiccup can no longer silently revert a working AI configuration to fallback mode.
* Hardening: Added a capability check to the feedback handler and a guard against corrupted model data.

= 1.0.1 =
* Minor bug fixes and complete rebranding implementation.

= 1.0.0 =
* Initial Release.
* Added Google Gemini Vision integration.
* Added Physical file renaming with thumbnail support.
* Added Auto-Fallback model routing.
* Added Bulk AJAX processing.
