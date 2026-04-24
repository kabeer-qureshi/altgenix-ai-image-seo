=== AltGenix AI Image SEO ===
Contributors: abdulkabeerdeveloper2530
Tags: auto alt text, seo, image optimization, auto tagger, gemini ai
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AltGenix AI Image SEO automatically generates highly optimized SEO Alt Text and renames physical image files using Google Gemini AI.

== Description ==

Welcome to **AltGenix AI Image SEO**, an enterprise-level, smart SEO automation tool for WordPress.

Stop wasting hours writing alt text and renaming image files manually. Our plugin uses the power of Google's Gemini Vision AI to instantly analyze your images, write descriptive SEO alt tags, and rename your physical files for maximum search engine visibility.

Unlike other plugins, it comes with a **Smart Fallback Engine**. It dynamically discovers available AI models (like Gemini 3 Flash, Gemini 2.5, Gemma, and Robotics), and if one model reaches its free limit, it seamlessly routes the request to the next available model without interrupting your workflow.

### 🚀 Key Features:
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

= 1.0.1 =
* Minor bug fixes and complete rebranding implementation.

= 1.0.0 =
* Initial Release.
* Added Google Gemini Vision integration.
* Added Physical file renaming with thumbnail support.
* Added Auto-Fallback model routing.
* Added Bulk AJAX processing.
