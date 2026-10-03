=== PixRefiner ===
Contributors: danjasker
Tags: images, webp, avif, image optimization, convert
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 4.0.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Convert, resize, and optimise images to WebP or AVIF with fine-grained control over sizes, quality, and batch processing.

== Description ==

PixRefiner converts your WordPress media library images to WebP or AVIF format, resizes them to up to four configurable breakpoints, and updates all post/page image URLs to point to the new files.

**Features:**

* Convert existing and new uploads to WebP (default) or AVIF
* Up to 4 configurable width or height breakpoints
* Configurable output quality (0–100)
* Batch conversion with configurable batch size and retry logic
* Preserve or delete original files after conversion
* Minimum file-size threshold to skip small images
* Exclude specific images from conversion
* Fix image URLs in posts, pages, FSE templates, and Elementor content
* Export the full media library as a ZIP before converting
* Conversion stamp system to skip already-optimised images unless settings change
* Custom srcset support for converted sizes
* Disable auto-conversion on upload

== Installation ==

1. Upload the `pixrefiner` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu.
3. Go to **Media → PixRefiner** to configure and run.

== Changelog ==

= 4.0.2 =
* Critical fix: uploading an image with the same name as an existing one (e.g. a second `photo.jpg`) overwrote the first image's files instead of getting a `-1` name. WordPress only checks for clashes with the *uploaded* extension, and once the first image's original `photo.jpg` had been converted and deleted, nothing stopped the new upload from being converted onto the existing `photo.webp` and its resized files. Uploads now pick a free name (`photo-1.webp`, `photo-2.webp`, …) the same way WordPress does.
* Fixed the same overwrite in bulk "Convert/Scale": an image is now only converted onto a name no other attachment uses, while its own leftover files from an earlier WebP/AVIF switch are reused so its name doesn't drift. Bulk conversion also no longer deletes another image's same-named breakpoint file when cleaning up outdated sizes.
* Fixed a conversion that failed partway (e.g. one breakpoint size couldn't be saved) leaving the attachment pointing at a deleted file, and — for images already in the target format — deleting the only copy of the image. The attachment now falls back to its original file, and existing files are never deleted by the rollback.
* "Fix URLs" now finds each image by the name it was originally uploaded with (recorded on its first bulk conversion), so links still reach the right image when it had to be renamed — including after a later WebP/AVIF switch, for legacy `-scaled` images, and for percent-encoded links. Previously it guessed by swapping the file extension, which could send a renamed image's links to a different image.
* Added a note under the conversion steps explaining that the JPG/PNG copies left by step 1 are only removed by step 2.
* The version shown on the settings page now always matches the plugin version.

= 4.0.1 =
* Critical fix: the "Fix URLs" step's write to `_elementor_data` (and, more rarely, to `post_content`) could corrupt saved Elementor/page data. `update_post_meta()` and the array form of `wp_update_post()` both unslash the value they're given, on the assumption that the caller already escaped it with `wp_slash()` first; without that, any literal backslash already present in the JSON (escaped quotes, `\n` in text-editor widgets, Windows-style paths, etc.) got stripped on save, corrupting the JSON and turning `\n` sequences into stray `n` characters. Both writes now wrap their data in `wp_slash()` before saving, matching WordPress's documented convention (and exactly what Elementor's own `Document::save_elements()` does).
* Fixed "Fix URLs" silently skipping every `elementor_library` post (Elementor's saved Templates, and Theme Builder Headers/Footers/Archives/Popups when using Elementor Pro) — a blanket `post_type` prefix check meant to skip Elementor's own internal post types was also skipping this one, which holds real, user-facing `_elementor_data`. It's now processed like any other post.
* Fixed "Fix URLs" rewriting `_elementor_data` (and clearing the `_elementor_css` cache) on every single Elementor page on every run, even ones with no image to update. The comparison re-encoded the JSON without matching Elementor's own slash-escaping, so the string never matched the original even when nothing had changed. It now compares the decoded data instead, so only pages with an actual image URL change get written and cache-busted.

= 4.0 =
* Security: fixed a stored XSS in the excluded-images admin list (attachment titles were rendered unescaped).
* Fixed WordPress default sizes (Medium/Medium Large/Large/1536x1536/2048x2048) being unregistered on converted images, which caused Elementor and other size-aware code to silently fall back to the thumbnail size.
* Added an optional site scan (Elementor data, post content, theme templates) to detect which default sizes are actually referenced, with an admin UI to review/confirm which to keep generating.
* Added an action to prune already-generated files for default sizes no longer kept, for already-converted media.
* Fixed the "Fix URLs" step not touching Elementor's real image data (`_elementor_data` postmeta) — it was only ever rewriting `post_content`, which Elementor doesn't store its image references in.
* Added `uninstall.php` to remove plugin options and the `.htaccess` MIME-type block on uninstall.
* Hardened the leftover-file cleanup routine to only ever delete files matching a tracked media-library attachment, rather than any unreferenced file in the uploads directory.
* Fixed duplicate settings-log entries caused by settings actions running twice per request.
* Wrapped the `.htaccess` MIME-type block in `<IfModule mod_mime.c>` to avoid breaking sites without that module.
* Reduced a blocking retry delay on upload from up to 5s to about 1s.
* Raised memory/time limits on the "Fix URLs" step so it doesn't time out on larger sites.

= 3.6 =
* Fixed custom image sizes (Custom 1200, Custom 600, Custom 300) reporting 1px height in Gutenberg by reading actual pixel dimensions from generated files instead of hardcoding 0.

= 3.5 =
* Initial plugin release (converted from code snippet).
* Added nonce security to all settings actions.
* Organised into includes/ and admin/ structure.
* Added Elementor JSON content URL fixing.
* Added CPT and FSE template support for URL fixing.
