=== PixRefiner ===
Contributors: codethatfits
Tags: images, webp, avif, image optimization, convert
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 3.5
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

= 3.5 =
* Initial plugin release (converted from code snippet).
* Added nonce security to all settings actions.
* Organised into includes/ and admin/ structure.
* Added Elementor JSON content URL fixing.
* Added CPT and FSE template support for URL fixing.
