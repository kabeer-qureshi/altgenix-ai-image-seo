=== AltGenix AI Image SEO ===
Contributors: abdulkabeerdeveloper2530
Tags: auto alt text, seo, image optimization, openai, claude
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Auto-generate SEO-optimized Alt Text, Titles, Captions and rename image files using Google Gemini, OpenAI, Anthropic Claude, or DeepSeek AI.

== Description ==

AltGenix generates image alt text, titles, captions and descriptions from a filename or your selected AI provider. Review generated text for accuracy and accessibility before publishing.

* Choose Google Gemini, OpenAI, Anthropic Claude or DeepSeek with your own API key.
* Enable each metadata field separately and choose the output language and length.
* Automatically process new uploads after WordPress finishes preparing them, or run through pending images in the Bulk Optimizer.
* Retry failed images without losing existing metadata. Bulk runs continue past failed batches.
* Optional AI filename generation copies the main image, original and thumbnails to new names. Existing embeds continue to use retained files, so old URLs remain valid. Copies require additional disk space.
* File renaming does not perform global search-and-replace in posts, builder data, serialized metadata or attachment GUIDs. New attachment-based output uses the current filename; existing hardcoded URLs retain the previous name.
* API credentials remain on the server. Authentication is checked using model discovery; account credits and generation access are checked during processing.

== Installation ==

1. Upload the `altgenix-ai-image-seo` directory to `/wp-content/plugins/`, or install the ZIP from Plugins > Add New.
2. Activate AltGenix AI Image SEO.
3. Open AltGenix AI > Settings and choose Filename or AI mode.
4. In AI mode select a provider, enter its API key and save. Provider charges may apply when generating metadata.
5. Choose the fields to generate. Automatic processing is enabled by default; optional renaming is disabled on a fresh installation.
6. Use Bulk Optimizer for existing images. Save pending attachment/editor edits before regenerating metadata.

== External Services ==

Filename mode makes no AI requests. AI mode sends a local image copy and the generation prompt (including any custom prompt) only to your selected provider. The API key authenticates these requests. Verification requests send the key to the provider's model-list endpoint; they do not generate an image or description. Model discovery does not guarantee credits, generation permissions or service availability.

* Google Gemini: https://ai.google.dev/ | Terms: https://ai.google.dev/gemini-api/terms | Privacy: https://policies.google.com/privacy
* OpenAI: https://openai.com/api/ | Terms: https://openai.com/policies/terms-of-use | Privacy: https://openai.com/policies/privacy-policy
* Anthropic Claude: https://www.anthropic.com/ | Terms: https://www.anthropic.com/legal/commercial-terms | Privacy: https://www.anthropic.com/legal/privacy
* DeepSeek: https://api-docs.deepseek.com/ | Terms: https://cdn.deepseek.com/policies/en-US/deepseek-terms-of-use.html | Privacy: https://cdn.deepseek.com/policies/en-US/deepseek-privacy-policy.html

Optional feedback is sent only when you click Submit Feedback. It emails your message, rating, site URL, WordPress account email, plugin version and provider name to the developer at abdulkabeer2530@gmail.com using your site's mail transport. API keys are not included. Public reviews are available regardless of your rating. Service links open only when clicked; no advertising trackers or remote assets are loaded by the plugin.

== Frequently Asked Questions ==

= What happens if the AI fails? =
Existing metadata is preserved and the image remains in the Failed list for an explicit retry. No failed, incomplete, refused or malformed AI response is marked as successfully optimized. Choose Filename mode explicitly if you want metadata derived from filenames.

= What if generated text succeeds but renaming fails? =
Generated text is retained, but the image is reported as failed until the filename issue is resolved. Repair the reported file problem and use the retry rename action to reuse the saved AI filename without another AI request.

= Does processing overwrite my text? =
A successful run replaces enabled fields. Disabled fields remain unchanged. All fields OFF makes processing a no-op. Back up your media metadata before bulk regeneration. The plugin cannot determine whether an empty alt attribute was deliberately chosen for a decorative image.

= How do I remove an API key? =
A blank key field retains the stored key. Use the explicit Remove Key button to delete the selected provider's key and switch to Filename mode. Other providers' stored keys remain available when switching providers.

= Do renamed files replace every URL on my site? =
No. Only the attachment's file references are updated. Old originals and sizes are retained for existing embeds and cached URLs. The plugin intentionally avoids replacing serialized content or GUIDs. Retained files are not deleted on uninstall; audit references before removing backups manually.

= Are images processed without an administrator page open? =
Uploads schedule WordPress cron processing after image preparation finishes. WordPress cron needs site traffic or a working server cron. On media/editor pages a browser queue also processes ready uploads. A failed image is retried from Bulk Optimizer, not repeatedly charged in the background.

= What about offloaded or unsupported files? =
A readable local copy inside this site's uploads directory is required. Suitable uncropped generated sizes are preferred; otherwise WordPress's image editor attempts a temporary JPEG conversion. When the host cannot read or convert a format, the failure is reported and existing metadata stays intact.

= Does the plugin support multisite? =
Settings and queues are per-site. Network uninstall clears plugin settings, jobs and temporary diagnostics for each site while preserving generated metadata, processed-status history, rename history and retained image backups so a reinstall does not make optimized media Pending again.

== Screenshots ==

1. Configure AltGenix AI Image SEO, choose your provider and model, and manage API verification.
2. Bulk optimize multiple images with selection controls, status badges, and bulk actions.
3. Generate image metadata directly from the WordPress Media Library attachment details screen.
4. Choose exactly which image fields to regenerate, including filename options, from a focused popup.

== Upgrade Notice ==

= 1.2.1 =
Fixes two faults introduced in 1.2.0: image uploads could hang on "Crunching..." forever, and the "choose what to regenerate" field selection was ignored. Recommended for everyone on 1.2.0.

= 1.2.0 =
Security and data-integrity fixes: server-side keys, safe file copies, reliable queues and strict AI validation. Rotate keys if a previous build exposed them to untrusted uploaders. Back up and test on staging first.

== Changelog ==

= 1.2.1 =
* Fix: Uploading an image could leave the media uploader stuck on "Crunching..." and never finish. A PHP warning raised on every upload was corrupting the response the uploader waits for. The images were still being tagged correctly underneath, but the screen never said so. Introduced in 1.2.0.
* Fix: "Choose what to regenerate" now limits the run to the fields you actually tick. The selection was being discarded, so a manual regeneration always used the saved Generation Control toggles instead. Introduced in 1.2.0.
* Fix: Rating five stars no longer opens the "tell us what went wrong" form alongside the review link. Low ratings get the feedback form, high ratings get the review link.
* Fix: "Regenerate Selected" now shows the progress bar while it works, instead of leaving the screen looking frozen, and the bar is cleared when the run ends rather than staying on screen.
* New: Bulk runs can be stopped. A Stop button sits with the progress bar and halts the run after the image in flight finishes, so nothing is left half-written.
* Fix: A failed image now turns red and shows the error in the list instead of still reading "Pending", and a processed image shows its new title instead of "Awaiting Action..." beside a green badge.
* Fix: Regenerating in Original Filename mode now marks the row as processed. It completed correctly but the status badge never updated.
* New: The queue list has a rows-per-page control (10, 25, 50 or 100), so a selection is no longer capped at ten images.
* Fix: The queue table scrolls on its own on narrow screens instead of stretching the page sideways.
* Fix: The settings screen reopens on the tab you were last using, and the tabs can be operated with the arrow keys.
* Fix: Buttons that are switched off now look switched off. The confirm button in the regenerate dialog and Save during a save stayed fully coloured, so pressing them appeared to do nothing.
* Fix: Notifications no longer cover the Save button, can be dismissed with a close button, stay put while the pointer is over them, and errors remain readable for longer.
* Fix: A request that simply took too long is reported as a timeout instead of claiming the login had expired.
* Fix: The result of a file rename is reported, including partial failures and how many old files were removed. That detail was being produced and then discarded.
* Fix: When this site cannot send email, the feedback form now shows the support-forum link it points you to.
* Fix: Help tooltips open on keyboard focus and on touch, not only on mouse hover.
* Fix: Confirmation dialogs close when the dimmed area around them is clicked.
* Fix: The buttons on the Media Library and attachment screens respond to hover and show a visible keyboard focus ring.
* New: Automatic processing after an upload now says what it is doing instead of running silently.
* Change: The background check for new uploads slows down while there is nothing to do, reducing repeated admin-ajax requests on media screens.
* Change: Dismissing the services banner keeps it hidden for thirty days instead of one.

= 1.2.0 =
* New: DeepSeek added as a fourth AI provider, using its vision-capable deepseek-flash model — a lower-cost option for high-volume image tagging. Requested on the support forum. Note that DeepSeek has no free tier, so the account needs a prepaid balance.
* Note: DeepSeek offers image input on deepseek-flash only (deepseek-v4-pro is text-only), so this provider runs a single model rather than the multi-model fallback chain used by Gemini, OpenAI and Claude.
* Fix: Switching AI Provider without saving no longer loses the saved provider's API key. Unsaved keys are now kept separately per provider for the current page session and restored when you switch back.
* Fix: Verified AI Model and escalation controls are hidden immediately when a different, unsaved provider is selected, preventing a stale model list from appearing under the wrong provider.
* Security: Per-image AJAX processing now verifies that the current user can edit the requested attachment. Global bulk actions require administrator capability.
* Data safety: Provider/JSON errors are now stored in private `_altgenix_error` attachment meta instead of overwriting the Media Library Description field. Error placeholders written into Descriptions by earlier versions are moved into that private meta by a one-time migration, which restores the Description field.
* Fix: A fresh install now behaves the way the Settings screen shows it. Alt Text, Title and Rename were displayed as ON before anything was saved, but the processor read the missing option as OFF — so the first uploads generated nothing and were still marked as processed. Defaults are now written on activation and shared by the screen and the processor.
* Data safety: A temporary AI failure can no longer replace Alt Text, Titles, Captions or Descriptions you wrote yourself. When the provider is rate limited or unreachable, filename text is only used to fill fields that are still empty, and the image stays in the Failed list so a later run can still generate proper AI text for it.
* Fix: "Process All Pending" now works through the whole library instead of stopping after the first 100 images, and it retries images whose previous run failed. The completion message reports how many succeeded and how many failed rather than always claiming the library is optimized.
* Fix: File renaming is far safer. It now waits until WordPress has finished preparing an image before touching anything, so a rename can no longer happen while thumbnails are still being generated from the file. Sizes that share one generated file are all updated together instead of leaving some pointing at a filename that is gone, the untouched original kept beside a scaled upload is renamed in step, and any move that does not actually happen keeps its old working filename and is reported instead of being silently ignored.
* Fix: Clicking Regenerate on an image the plugin is already processing no longer starts a second request for it. That could send the same image to the AI twice — billed twice — with whichever reply arrived last deciding the metadata. It now reports that the image is busy.
* Fix: GIFs, and HEIC/HEIF on Google Gemini, now appear in the Bulk Optimizer and the upload queue. They were accepted on upload and handled by the AI, but every screen filtered them out, so they could only ever be processed by the background task.
* Fix: Deleting the plugin now also clears the background processing events it scheduled, instead of leaving WP-Cron holding jobs whose handler is gone.
* Fix: The "Optimize with AltGenix" button in the Media Library is shown only to administrators. It links to the Bulk Optimizer, which is an administrator screen, so anyone else was sent to a permission error.
* New: Save Settings now follows you down the page instead of waiting at the bottom, so it is reachable from any tab without scrolling. It tells you when there are unsaved changes, warns you if you navigate away with some, and Ctrl+S (Cmd+S on Mac) saves.
* Fix: Buttons rendered as links no longer turn admin-blue when hovered or focused. The plugin styles them explicitly in every link state instead of leaving it to the WordPress admin stylesheet.
* New: A banner at the top of the AltGenix settings and Bulk Optimizer screens offers development and SEO help for the rest of your site.
* New: Once the plugin has tagged a meaningful number of images, a dismissible notice on the Media Library offers a free short review of the rest of your site. It appears on the media screens only, never elsewhere in wp-admin, and dismissing it hides it permanently.
* New: The Help screen now says who builds the plugin and what else they can help with — WordPress and CMS development, technical SEO, site speed and migrations — with a free short review on offer. A compact version appears once on the Bulk Optimizer and can be hidden permanently. It is confined to this plugin's own screens: no dashboard notices, nothing on the front of your site, and nothing is sent anywhere unless you click a link yourself.
* Fix: Auto-processing a new upload now reports a provider failure in the browser instead of showing success while the image was quietly marked as failed.
* Fix: Gemini model discovery no longer offers image-generation models such as gemini-3.1-flash-image and gemini-3-pro-image, which return pictures rather than the text metadata this plugin needs.
* Fix: Gemini requests no longer pin a reduced temperature. Google's guidance for Gemini 3 is to leave it at the default, where a lowered value can cause looping and degraded output.
* Fix: Anthropic Claude requests no longer send a fixed temperature, so the model runs at its own default.
* Fix: The one-time migration of older error placeholders runs in small batches, so a library with thousands of failed images cannot time out an admin page load, and it only converts text matching exactly what the plugin wrote — a Description of your own that happens to begin with the same words is left alone.
* Hardening: Image reads now verify that WordPress filesystem access initialized successfully before use.
* Cleanup: Uninstall now removes plugin-private feedback/data-version options and private processing/error meta while preserving generated SEO content.
* Packaging: Removed an accidental duplicate copy of the plugin that had been nested under `/assets/`.
* Documentation: Added explicit External Services disclosures for Google, OpenAI, Anthropic, DeepSeek, and the optional feedback email.
* Fix: Feedback sent from the Help & Rate Us page was reported as delivered even when the site could not send email at all — so the message was silently lost while you were thanked for it. The plugin now checks whether the mail actually went out, keeps what you typed if it did not, and points you to the support forum instead.
* Fix: The star rating no longer reappears every time you open the Help page after you have already rated or left a review. A "Send more feedback" button is there if you want to write again.
* Improvement: The Help & Rate Us page now actually contains help — the common causes of images not being tagged, and what to include when asking on the support forum.
* Improvement: Feedback emails now include the site address, plugin version and chosen AI provider, so a report can be acted on without a round of questions. API keys are never included.
* New: Images are now downscaled before being sent to the AI. WordPress has already made smaller copies of every upload, so in most cases the plugin simply uses the existing 1024px "large" version instead of the full-size original — an 8MB photo becomes a 150KB upload. The AI providers downscale large images anyway, so the descriptions are unchanged; uploads are far faster and, on the higher-resolution models, noticeably cheaper.
* New: If your setup has no suitable copy — an optimization plugin that switches off the larger registered sizes, or a big upload whose thumbnails never got built — the plugin now creates a temporary resized copy for the AI and deletes it straight afterwards. Your original file is never modified.
* New: Images in formats the AI cannot read (AVIF, BMP, TIFF) are converted to JPEG for that temporary copy, so they get proper alt text instead of being skipped.
* Fix: The plugin will no longer describe a tiny thumbnail. If the only remaining copy is smaller than 512px it resizes the original instead, because descriptions written from a 150px image are vague and often wrong.
* New: AI Model selector — you now choose which model runs, instead of the plugin picking for you. Models are listed cheapest first.
* Fix: A busy model no longer silently switches you to a more expensive one. The plugin now waits a few seconds and retries the same model, which clears most rate limits; only if you switch on "If the model is busy" will it move up to a pricier model. Previously a rate-limited Haiku would escalate all the way to Opus — roughly 7x the cost per image — with nothing to warn you.
* Fix: Auto-discovered Google Gemini models are now sorted cheapest-first. Google returns its catalogue in its own order, so a costly Pro model could previously end up as the default for a new install.
* Fix: Large images are no longer sent to a provider that cannot accept them. The size limit is now calculated per provider against the encoded payload, which is roughly a third larger than the file on disk — previously images between about 7.5MB and 10MB were accepted locally and then rejected by Anthropic Claude.
* Fix: Images in formats the AI providers cannot read (AVIF, BMP, TIFF, SVG) are now caught before upload with a clear message naming the supported formats, instead of failing with an opaque provider error. HEIC/HEIF is allowed through on Google Gemini, which supports it.
* Fix: A file whose type could not be identified is no longer sent to the AI labelled as a JPEG. Guessing moved the failure to the API, where the reported error was much harder to act on.
* Improvement: Oversized and unsupported images now fall back to filename-based text rather than leaving the attachment with no alt text at all.
* Improvement: DeepSeek requests automatically retry as plain text if strict JSON output is rejected or comes back empty, so a JSON-mode limitation never costs you a generated tag.
* Improvement: DeepSeek key verification performs a real generation check, so a key with an exhausted balance is reported upfront instead of silently falling back to filename mode.
* Security: Removed API keys from localized scripts and rendered settings; added explicit key removal and a secure masked saved-key state without rendering the secret.
* Security: Corrected rename and banner nonces, per-attachment authorization, provider error redaction and text-only UI notices.
* Data safety: Replaced destructive renames and global SQL URL replacement with verified copies; preserved GUIDs, serialized data and published URLs.
* Fix: Bulk runs use an increasing attachment-ID cursor and continue beyond failed 100-image batches.
* Fix: Atomic per-image locks cover automatic processing, manual regeneration and renaming; an expired owner cannot release another request's lock.
* Fix: Upload readiness is finalized at request shutdown after WordPress image metadata generation.
* Fix: All field-toggle combinations behave consistently; disabled fields are preserved, no-op runs stay pending, and rename-only runs request a filename.
* Fix: Rejected incomplete, empty, malformed and refused AI responses; failures preserve existing metadata and remain retryable.
* Fix: Preserved settings on verification failure, avoided double unslashing, blocked duplicate saves and retained edits typed during an in-flight save.
* Fix: Bounded provider requests, respected numeric/date Retry-After, avoided retrying transport timeouts, and paginated Gemini/Claude model discovery.
* Fix: Queues include WordPress image types that require local conversion; cropped thumbnails are excluded from AI inputs.
* Fix: Checked database/file errors, kept source image bytes, made repeated renames idempotent, and added PHP-memory and actual-image-type validation.
* Fix: Failed filename changes remain retryable; manual Rename reuses the saved AI filename without another generation charge.
* Fix: Regenerating media no longer reloads editors and discards unsaved post content.
* Fix: Repaired the Settings plugin action link, removed inline JavaScript, restored styled selects with keyboard/ARIA accessibility, and offered public reviews for every rating.
* Cleanup: Added deactivation job cleanup and multisite-aware uninstall while preserving media, processed-status/rename history and backup URLs so reinstalling does not re-queue already optimized images.

= 1.1.2 =
* Fix: Retired or deprecated Gemini models (e.g. gemini-2.5-flash) now automatically fall back to the next available model instead of stopping with an "AI Error".
* Fix: Fallback mechanism added for "JSON mode not supported" errors — the plugin now automatically retries the model as plain text if application/json mimeType fails.
* Fix: Resolved Regenerate Button silently failing by clearing stale transients before execution and accurately reporting API errors back to the user.
* Fix: Corrected the spinning animation CSS logic and vertical flexbox alignment for all buttons to ensure icons and text are perfectly centered without wobbling.
* New: Media Grid Modal integration — the "Auto-Generate AI Tags" button now correctly appears inside the Backbone JS attachment modal.
* New: Added full tooltip on hover for truncated errors in the Bulk Optimizer table.
* Improvement: Model discovery regex is now future-proof — new Gemini versions (3.5, 3.6, 4.0, etc.) are auto-discovered without requiring a plugin update.
* Improvement: Prefixed API errors with "AI Provider Error:" to clarify when the error comes from the AI provider (e.g., rate limits) vs the plugin itself.
* Improvement: Replaced file_get_contents() with WP_Filesystem for stricter WordPress coding standards compliance.
* Improvement: Added 'Reply-To' header to the user feedback form to prevent support emails from going to spam.

= 1.1.1 =
* Improvement: Custom Prompt Context is now treated as high-priority instructions the AI must follow, so your brand name, tone, or focus is reliably reflected in the generated Alt Text and other fields.
* Improvement: Alt Text is now grounded strictly in what is visible in the image — the AI is instructed not to invent brands, names, or details it cannot see, producing more accurate results.
* Improvement: Default Alt Text length is now "Medium (5-15 words)" instead of "Short", giving more descriptive, SEO-friendly alt text out of the box (existing saved settings are unchanged).
* Improvement: Lower generation temperature for OpenAI and Claude keeps descriptions faithful to the image instead of overly "creative".
* Fix: Gemini responses are now requested as strict JSON, reducing occasional "Invalid JSON" failures that left an image without generated tags.

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
