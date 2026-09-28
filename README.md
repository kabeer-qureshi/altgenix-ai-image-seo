# AltGenix AI Image SEO

[![WordPress Plugin Version](https://img.shields.io/wordpress/plugin/v/altgenix-ai-image-seo?label=wordpress.org)](https://wordpress.org/plugins/altgenix-ai-image-seo/)
[![Downloads](https://img.shields.io/wordpress/plugin/dt/altgenix-ai-image-seo?label=downloads)](https://wordpress.org/plugins/altgenix-ai-image-seo/advanced/)
[![Requires WordPress](https://img.shields.io/wordpress/plugin/wp-version/altgenix-ai-image-seo)](https://wordpress.org/plugins/altgenix-ai-image-seo/)
[![License](https://img.shields.io/badge/license-GPLv2%2B-blue)](https://www.gnu.org/licenses/gpl-2.0.html)

Generate alt text, titles, captions and descriptions for your WordPress images — from the
filename, or from Google Gemini, OpenAI, Anthropic Claude or DeepSeek using your own API key.

> Generated text should be reviewed for accuracy and accessibility before publishing. An alt
> attribute describes an image to someone who cannot see it; no model gets that right every time.

**[Plugin page on WordPress.org →](https://wordpress.org/plugins/altgenix-ai-image-seo/)**

---

## What it does

- **Two modes.** *Filename* rewrites `red-car-front.jpg` into readable text with no API calls
  and no cost. *AI* sends the image to the provider you choose, using your own key.
- **Four providers**, each with your own key: Google Gemini, OpenAI, Anthropic Claude, DeepSeek.
  You pick the model; models are listed cheapest first.
- **Per-field control.** Alt text, title, caption and description are enabled separately, each
  with its own length, in the language you choose or your site's own.
- **Automatic on upload**, or work through the backlog in the Bulk Optimizer with progress,
  a stop button, and per-image status.
- **Failures stay retryable.** A provider error never overwrites metadata you wrote yourself, and
  the image stays in the Failed list instead of being marked done.
- **Optional file renaming** that copies rather than moves, so existing embeds keep working —
  responsive sizes included.

## Requirements

| | |
|---|---|
| WordPress | 5.8 or newer |
| PHP | 7.4 or newer |
| API key | Only for AI mode. Filename mode needs nothing. |

## Installation

From your dashboard: **Plugins → Add New**, search for *AltGenix AI Image SEO*, install, activate.

Manually:

```bash
cd wp-content/plugins
git clone https://github.com/kabeer-qureshi/altgenix-ai-image-seo.git
```

Then activate it in **Plugins**, and open **AltGenix AI → Settings**.

## Getting started

1. **AltGenix AI → Settings** and pick a Processing Mode.
2. For AI mode, choose a provider, paste its API key, and press **Verify & Refresh Models**.
   Verification only asks the provider for its model list — it does not generate anything.
3. Choose which fields to generate and how long each should be.
4. New uploads are processed automatically. For images you already have, use **Bulk Optimizer**.

Renaming files is off on a fresh install. Turn it on only if you understand the note below.

### Where to get an API key

| Provider | Key | Notes |
|---|---|---|
| Google Gemini | [aistudio.google.com](https://aistudio.google.com/app/apikey) | Has a free tier |
| OpenAI | [platform.openai.com](https://platform.openai.com/api-keys) | |
| Anthropic Claude | [console.anthropic.com](https://console.anthropic.com/settings/keys) | |
| DeepSeek | [platform.deepseek.com](https://platform.deepseek.com/api_keys) | No free tier — needs a prepaid balance |

Provider charges apply to every image processed in AI mode. Check current pricing before a large run.

## About renaming files

When renaming is enabled, the plugin **copies** the image, its original and its thumbnails to new
filenames and points the attachment at the copies. The old files are left in place, so URLs already
embedded in posts, page builders, caches or other sites keep working.

It deliberately does **not** run a global search-and-replace across post content, builder data,
serialized metadata or attachment GUIDs. That keeps it safe, at the cost of extra disk space — the
retained originals are not removed on uninstall either. Audit your references before deleting them.

## Privacy and external services

**Filename mode makes no external requests at all.**

In AI mode, a downscaled copy of the image and the generation prompt are sent to the one provider
you selected, authenticated with your key. Nothing is sent anywhere else. Your key stays on your
server and is never written into the page or into JavaScript.

| Provider | Terms | Privacy |
|---|---|---|
| [Google Gemini](https://ai.google.dev/) | [Terms](https://ai.google.dev/gemini-api/terms) | [Privacy](https://policies.google.com/privacy) |
| [OpenAI](https://openai.com/api/) | [Terms](https://openai.com/policies/terms-of-use) | [Privacy](https://openai.com/policies/privacy-policy) |
| [Anthropic](https://www.anthropic.com/) | [Terms](https://www.anthropic.com/legal/commercial-terms) | [Privacy](https://www.anthropic.com/legal/privacy) |
| [DeepSeek](https://api-docs.deepseek.com/) | [Terms](https://cdn.deepseek.com/policies/en-US/deepseek-terms-of-use.html) | [Privacy](https://cdn.deepseek.com/policies/en-US/deepseek-privacy-policy.html) |

The feedback form on the Help screen sends mail **only when you press Submit** — your message,
rating, site URL, account email, plugin version and provider name, through your own site's mail
transport. API keys are never included. There is no telemetry, no phone-home, and no remote assets.

## Questions people actually ask

<details>
<summary><b>What happens when the AI fails?</b></summary><br>

Your existing metadata is preserved and the image goes to the Failed list for an explicit retry.
An incomplete, empty, refused or malformed response is never recorded as a success. If you want
text derived from filenames, choose Filename mode deliberately rather than relying on a failure.
</details>

<details>
<summary><b>Will it overwrite text I wrote myself?</b></summary><br>

A successful run replaces the fields you have enabled. Disabled fields are left alone, and turning
every field off makes a run do nothing. A temporary provider failure will not overwrite your words.
Back up your media metadata before a large regeneration — and note that the plugin cannot tell an
empty alt attribute you chose deliberately for a decorative image from one nobody filled in.
</details>

<details>
<summary><b>How do I remove a saved API key?</b></summary><br>

Leaving the field blank keeps the stored key. Use **Remove Key** to delete the current provider's
key and drop back to Filename mode. Keys saved for other providers are kept.
</details>

<details>
<summary><b>Does anything happen if no admin page is open?</b></summary><br>

Uploads schedule a WordPress cron job once the image is fully prepared, so WP-Cron needs either
site traffic or a real server cron. While you are on a media or editor screen the browser also
works through ready uploads. A failed image is retried from the Bulk Optimizer, never charged
repeatedly in the background.
</details>

<details>
<summary><b>What about offloaded media or unusual formats?</b></summary><br>

A readable local copy inside the site's uploads directory is required. AVIF, BMP and TIFF are
converted to a temporary JPEG for the request; HEIC/HEIF goes through as-is on Gemini. When the
host cannot read or convert a file the failure is reported and existing metadata is untouched.
</details>

<details>
<summary><b>Multisite?</b></summary><br>

Settings and queues are per-site. A network uninstall clears settings, jobs and diagnostics for
each site while keeping generated metadata, processed history and retained backups, so a reinstall
does not turn already-optimized media back into a queue.
</details>

## Changelog

### 1.2.2

- On the attachment edit screen, pressing **Update** after generating no longer puts the old title
  and alt text back.
- Typing in the Media Library popup and clicking Generate straight away no longer lets the typed
  text overwrite the generated text.
- Renaming a file no longer breaks images already placed in posts: their `srcset`, width and height
  stay intact. Deleting a renamed image now also removes the older copies the plugin kept.
- **Remove Key** really removes the key, and saving settings no longer shows a false error.
- A failed regeneration no longer sends an already processed image back into the paid queue, and
  **Mark as done** now accepts failed images.
- Gemini models are asked for the least thinking they allow; Claude 5 models are listed.
- Clearer wording throughout: *Filename (free, no API key)* and *AI (uses your API key)*,
  *Process all remaining*, *Regenerate selected* and *Mark as done* with live counts, and an
  *Automatic (cheapest available)* model choice.

### 1.2.1

Fixes two regressions introduced in 1.2.0:

- Uploading an image could leave the media uploader stuck on *"Crunching…"* and never finish. A PHP
  warning raised on every upload was corrupting the response the uploader waits for. Images were
  still being tagged correctly underneath.
- *Choose what to regenerate* now limits the run to the fields you tick. The selection was being
  discarded, so a manual regeneration always used the saved Generation Control toggles instead.

Interface work in the same release: a five-star rating no longer opens the "what went wrong" form,
*Regenerate Selected* shows its progress bar, bulk runs can be stopped, failed rows read as failed,
a rows-per-page control, the queue table scrolls on narrow screens, the settings screen reopens on
the tab you left, disabled buttons look disabled, notifications clear the Save button and can be
dismissed, timeouts are reported as timeouts, rename results are reported, tooltips open on keyboard
focus, dialogs close on a backdrop click, and automatic processing says what it is doing.

**[Full changelog for every release →](readme.txt)**

## Contributing

Bug reports and suggestions are welcome as [issues](https://github.com/kabeer-qureshi/altgenix-ai-image-seo/issues).
For support questions, the [WordPress.org support forum](https://wordpress.org/support/plugin/altgenix-ai-image-seo/)
is the better place — please say which provider you are using and quote the error you saw.

## License

GPL v2 or later — see [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).

Built by [Abdul Kabeer](https://www.linkedin.com/in/abdulkabeerdeveloper).
