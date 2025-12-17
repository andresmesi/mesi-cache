=== Mesi Cache ===
Contributors: andresmesi
Tags: cache, performance, static, html, apache
Requires at least: 5.5
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.2.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Ultra-light static HTML caching system for WordPress.

== Description ==

Mesi Cache converts your WordPress site into a fully static HTML version served
directly by Apache. Once a page is cached, it is delivered instantly without
executing PHP or querying MySQL.

This design removes the need for PHP-FPM and database access on every visit,
reducing server load to almost zero. The result is a pure static experience with
instant page delivery and total control over your server resources.

Mesi Cache is minimalist, open, and transparent. It integrates cleanly with
`.htaccess`, uses no cron jobs, and requires no external services.

**What it does**
* Creates static HTML files of posts, pages, categories and authors.
* Serves files directly from disk, bypassing PHP and MySQL completely.
* Invalidates relevant cache files when new content is published.
* Allows manual cache generation and cleaning from the admin panel.
* Works in root and subdirectory installations.
* 100% local — no APIs, no remote calls, no external dependencies.

**What it does not do**
* No CSS or JS minification.
* No CDN integration (can be added manually).
* No object or fragment caching.
* No user/session-aware pages or dynamic content.
* Does not serve cached content to logged-in users.

Mesi Cache focuses purely on static HTML delivery for public visitors.  
When cached, pages are served as plain files — no PHP or database code executes.

The plugin code is open, commented, and easy to extend.  
Developers can add features like preloading or CDN hooks using ChatGPT, Gemini,
or manual edits.

== Installation ==

1. Upload the `mesi-cache` folder to `/wp-content/plugins/`.
2. Activate it from the WordPress “Plugins” menu.
3. Go to **Settings -> Mesi Cache** to configure.
4. Click **Generate / Update MESI-Cache Block** to write `.htaccess` rules.
5. Optionally click **Generate All** to prebuild all cached pages.

== Frequently Asked Questions ==

= Does it work with subfolder installations (like /wp/)? =
Yes. The plugin detects your path and writes correct `.htaccess` rules.

= Does it cache logged-in users or admin pages? =
No. Logged-in users and administrators always bypass the cache.

= Does it still use PHP or MySQL to serve cached pages? =
No. Cached pages are delivered directly by Apache with zero PHP execution.

= Can I clear the cache manually? =
Yes. Use **Settings → Mesi Cache → Clear All Cache** 
or delete `wp-content/uploads/mesi-cache/`.

= Is it compatible with Nginx or LiteSpeed? =
It is optimized for Apache `.htaccess`. Nginx rules can be written manually.

== Changelog ==

= 1.2.5 =
* Compatibility confirmed with WordPress 6.9.
* Improved cache invalidation for tags and authors.
* Minor fixes and internal cleanup.

= 1.2.4 =
* Added tag and author cache invalidation.
* Improved path handling for categories and nested URLs.
* Added translation support and `.pot` file.
* Enhanced Apache rule generation for static delivery.

== Upgrade Notice ==
Back up your `.htaccess` before updating, as rewrite rules may change.

== License ==
This plugin is licensed under GPLv2 or later.
You are free to use, modify, and redistribute it.