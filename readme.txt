=== ForgeHub Search & Replace ===
Contributors: wpforgehub
Tags: search replace, database, migration, serialized, domain change
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Serialize-safe database search and replace with a mandatory dry-run preview and batched processing. Zero bloat, no external services.

== Description ==

**Search & Replace** changes URLs, domains and text across your whole WordPress database without corrupting serialized data (widgets, theme options, page builder settings).

Every replacement starts with a **dry run**: you see how many values would change and a preview of before/after snippets. The live run unlocks only after a dry run with exactly the same settings.

### Problem and solution

Moving a site or changing a domain breaks things when you use a plain SQL `REPLACE()`: serialized strings store their own byte length, so a plain replace corrupts options and widgets. This plugin unserializes each value, replaces recursively inside arrays and objects, and serializes it again with correct lengths.

### Core features (Free)

* Serialize-safe replacement for strings, arrays and objects, including nested serialized data.
* Mandatory dry-run preview with sample before/after snippets and counters.
* Batched AJAX processing (200 rows per request) so large databases never hit time limits.
* The `guid` column is skipped by default, with a warning and an explicit opt-in.
* Case-sensitive or case-insensitive matching.
* Passwords and activation keys are never touched.
* All queries use prepared statements; every request is protected by a nonce and the `manage_options` capability.
* Corrupted serialized values and unsafe objects are reported and left untouched instead of being damaged.

### Free vs Pro

| Feature | Free | Pro |
| --- | --- | --- |
| Serialize-safe replace | Yes | Yes |
| Dry-run preview | Yes | Yes |
| Batched AJAX processing | Yes | Yes |
| Table and column selection | All tables | Choose tables and columns |
| Automatic backup before every change | No | Yes |
| One-click undo | No | Yes |

= BYOK and privacy =
This plugin does not use AI, does not call external services, does not send any data off your server and has no server cost.

== Frequently Asked Questions ==

= Is it safe for serialized data? =
Yes. Values are unserialized without instantiating classes, replaced recursively and serialized again. Anything that cannot be handled safely is skipped and reported.

= Can I undo a replacement? =
Pro stores the original value of every changed cell and can restore it with one click. In Free, take a database backup first.

= Should I replace the guid column? =
Usually not. GUIDs are permanent identifiers; changing them can make feed readers show old posts as new. They are skipped unless you opt in.

= Does it work on multisite? =
It processes the tables of the current site (the tables that share the current table prefix).

= Which PHP version is required? =
PHP 7.4 or newer, WordPress 6.2 or newer.

= What is your refund policy? =
Pro purchases are covered by a 14-day money-back guarantee through Freemius.

== Screenshots ==

1. The Search & Replace tool with dry-run preview.

== Changelog ==

= 1.0.2 =
* Connect live Freemius Product ID and public key for Pro licensing and automatic updates.

= 1.0.1 =
* Fix serialized scalar corruption, Select None table handling, Multisite subsite isolation, PK protection, wp_options ordering, and mbstring fallback.

= 1.0.0 =
* Initial release: serialize-safe search and replace, dry-run, batched AJAX, guid protection. Pro: table/column selection, automatic backup and undo.
