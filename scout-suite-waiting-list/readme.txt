=== Scout Suite Waiting List ===
Contributors: scoutsuite
Tags: scouts, waiting list, membership, form, scout suite
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Embed a public waiting list signup form for your Scout group. Submissions go straight to your group's waiting list in Scout Suite.

== Description ==

Scout Suite Waiting List connects your group's WordPress website to your Scout Suite account (scoutsuite.app). Parents fill in a simple form on your site and the entry is created on your group's waiting list in Scout Suite immediately. No spreadsheets, no retyping.

Features:

* Shortcode `[scoutsuite_waitlist]` and a Gutenberg block, so the form works with any editor
* Form fields that match the Scout Suite waiting list API: child's name, date of birth, section, parent name, email, phone, postcode, sibling flag and notes
* Section dropdown filled automatically from your group's active sections in Scout Suite, with a manual override in settings
* Submissions are sent from your server. Your API key is never exposed to visitors
* GDPR ready: a configurable privacy notice and a required consent checkbox, with the consent recorded in the entry notes
* Clear success and error messages, including friendly handling of duplicate signups
* Honeypot spam protection and WordPress nonce verification
* Light styling that inherits your theme's fonts, colours and button styles
* Removes all its settings when you delete the plugin

== Installation ==

1. Upload the `scout-suite-waiting-list` folder to `/wp-content/plugins/`, or install the zip through Plugins, Add New, Upload Plugin.
2. Activate the plugin through the Plugins screen.
3. Go to Settings, Scout Suite Waiting List and enter your Scout Suite Group ID. An API key is optional.
4. Add the form to a page with the `[scoutsuite_waitlist]` shortcode or the Scout Suite Waiting List block.

== Frequently Asked Questions ==

= Where do I find my Group ID? =

In Scout Suite, open your group settings. The Group ID is shown there. If you are unsure, contact Scout Suite support.

= Do I need an API key? =

No. The Scout Suite waiting list signup endpoint accepts public submissions for exactly this use. You can add an API key from the Scout Suite developer portal if you prefer authenticated requests. Either way the key is stored on your server and never sent to visitors.

= Which fields are required? =

Child's first name, child's last name, your name, your email address and the consent checkbox. Everything else is optional, matching the Scout Suite API.

= What happens if a child is already on the list? =

The form shows a clear message asking the parent to contact the group rather than creating a duplicate entry.

= Is this GDPR compliant? =

The plugin gives you the tools: a privacy notice you can edit to match your group's privacy policy, and a consent checkbox that must be ticked before the form submits. Consent is recorded in the waiting list entry notes with the date. Your group is still responsible for its own privacy policy and data handling.

= Can I change the wording on the form? =

The privacy notice, consent label and success message are editable under Settings, Scout Suite Waiting List.

== Changelog ==

= 1.0.0 =
* First release. Shortcode, Gutenberg block, settings page, section auto detection, consent handling and full Scout Suite API integration.

== Upgrade Notice ==

= 1.0.0 =
First release.
