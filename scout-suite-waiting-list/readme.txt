=== Scout Suite ===
Contributors: scoutsuite
Tags: scouts, scout suite, waiting list, directory, membership
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect a Scout Suite Group, District or County to WordPress. Sync Groups into WP Store Locator / Skills for Life. Embed a waiting list form on Group sites.

== Description ==

Scout Suite connects your WordPress website to your Scout Suite account (scoutsuite.app).

It does not replace WP Store Locator or Skills for Life. Those plugins keep the public Group map, list and necker UI. Sync writes into the `wpsl_stores` posts they already query. Public events are written into The Events Calendar when that plugin is active.

On a **District or County** site, set the Org ID to that District or County and use Sync now. Skills for Life then shows the Groups. Do not put the waiting list shortcode on the District homepage: that form is for a single Group. Each Group’s waiting-list link points at Scout Suite.

On a **Group** site, set the Org ID to that Group and add `[scoutsuite_waitlist]` if parents should join the list on your website.

Features:

* Sync Groups from a District, County or Group into WP Store Locator without duplicating stores or blanking editor content
* Hourly WP-Cron plus a Sync now button
* Shortcode `[scoutsuite_waitlist]` and a Gutenberg block for Group waiting list signups
* Form fields that match the Scout Suite waiting list API
* Section dropdown filled automatically from your group's active sections, with a manual override in settings
* Submissions are sent from your server with `"source": "wordpress"`. Your API key is never exposed to visitors
* GDPR ready: a configurable privacy notice and a required consent checkbox
* Honeypot spam protection and WordPress nonce verification
* Light styling that inherits your theme
* Removes all its settings when you delete the plugin

== Installation ==

1. Upload the `scout-suite-waiting-list` folder to `/wp-content/plugins/`, or install the zip through Plugins, Add New, Upload Plugin.
2. Activate **Scout Suite** through the Plugins screen.
3. Go to Settings, Scout Suite and enter your Org ID (District, County, or Group) and API key.
4. Click Sync now to pull Groups into WP Store Locator / Skills for Life.
5. On a Group site, add the form with `[scoutsuite_waitlist]` or the Scout Suite Waiting List block.

== Frequently Asked Questions ==

= Where do I find my Org ID? =

In Scout Suite, use the same id leaders see in the URL for a District, County or Group. Existing installs that stored this as Group ID keep working.

= Should I use this on a District website? =

Yes for sync. Set the Org ID to the District, click Sync now, and keep using Skills for Life for the public map and list. Do not put `[scoutsuite_waitlist]` on the District homepage. That shortcode is a single-Group form. Parents join a Group’s list via the waiting-list link on that Group, which points at Scout Suite.

= Do I need an API key? =

The waiting list signup endpoint accepts public submissions without a key. Directory and events sync require a Bearer token (`ss_at_…`) from the Scout Suite developer portal. The key is stored on your server and never sent to visitors.

= Which fields are required on the form? =

Child's first name, child's last name, your name, your email address and the consent checkbox. Everything else is optional, matching the Scout Suite API.

= What happens if a child is already on the list? =

The form shows a clear message asking the parent to contact the group rather than creating a duplicate entry.

= Does this replace Skills for Life or WP Store Locator? =

No. Those plugins keep the public UI. This plugin writes store posts (`wpsl_stores`) that they already read.

= What if The Events Calendar is not installed? =

Events are skipped and an admin notice is shown. Groups still sync into WP Store Locator. The plugin does not create its own events post type.

= Is this GDPR compliant? =

The plugin gives you the tools: a privacy notice you can edit, and a consent checkbox that must be ticked before the form submits. Consent is recorded in the waiting list entry notes with the date. Your group is still responsible for its own privacy policy and data handling.

= Can I change the wording on the form? =

The privacy notice, consent label and success message are editable under Settings, Scout Suite.

== Changelog ==

= 1.1.0 =
* Plugin renamed to Scout Suite. Waiting list is one feature; directory and events sync are the other.
* Sync Groups from Scout Suite into WP Store Locator (`wpsl_stores`) for Skills for Life sites.
* Sync public events into The Events Calendar when it is active.
* Org ID may be a District, County or Group. API base URL is configurable.
* Sync now button and hourly WP-Cron. 404s fail the sync instead of inventing data.
* Waiting list submissions include `"source": "wordpress"`.

= 1.0.0 =
* First release. Shortcode, Gutenberg block, settings page, section auto detection, consent handling and Scout Suite waiting list API integration.

== Upgrade Notice ==

= 1.1.0 =
Now named Scout Suite. Adds directory and events sync into WP Store Locator and The Events Calendar. The `[scoutsuite_waitlist]` shortcode is unchanged.
