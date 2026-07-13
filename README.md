# Scout Suite Waiting List

A WordPress plugin that lets [Scout Suite](https://scoutsuite.app) groups embed a public waiting list signup form on their own website. Parents fill in the form and the entry is created on the group's waiting list in Scout Suite immediately. No spreadsheets, no retyping.

## How it works

The form submits to your WordPress server, which forwards the entry to the Scout Suite API:

```
POST https://api.scoutsuite.app/api/groups/{groupId}/waiting-list
```

This endpoint accepts public submissions for exactly this use case, so an API key is optional. If you set one, it is sent as a Bearer header from the server and is never exposed to visitors.

The section dropdown is filled automatically from your group's active sections via the public `signup-info` endpoint, cached for one hour.

## Features

- Shortcode `[scoutsuite_waitlist]` and a Gutenberg block, so the form works with any editor
- Form fields that match the Scout Suite waiting list API: child's name, date of birth, section, parent name, email, phone, postcode, sibling flag and notes
- GDPR ready: a configurable privacy notice and a required consent checkbox, with consent recorded in the entry notes with the date
- Clear success and error messages, including friendly handling of duplicate signups
- Honeypot spam protection and WordPress nonce verification
- All inputs sanitised server side; no personal data ever appears in URLs
- Light styling that inherits the theme's fonts, colours and button styles
- Removes all its settings and transients when the plugin is deleted

## Requirements

- WordPress 6.0 or later
- PHP 7.4 or later
- A Scout Suite account for your group

## Installation

1. Download or build the plugin zip (see below), or copy the `scout-suite-waiting-list` folder into `wp-content/plugins/`.
2. Activate **Scout Suite Waiting List** on the Plugins screen.
3. Go to **Settings, Scout Suite Waiting List** and enter your Scout Suite Group ID. An API key is optional.
4. Add the form to a page with the `[scoutsuite_waitlist]` shortcode or the **Scout Suite Waiting List** block.

To build the installable zip from a checkout:

```sh
zip -r scout-suite-waiting-list.zip scout-suite-waiting-list -x "*.DS_Store"
```

## Settings

| Setting | Purpose |
|---|---|
| Group ID | Your group's ID in Scout Suite. Required. |
| API key | Optional `ss_at_...` key from the Scout Suite developer portal. Stored server side only. |
| Sections | One per line to override the list fetched from Scout Suite. Leave blank to fetch automatically. |
| Privacy notice | Shown above the consent checkbox. Edit to match your group's privacy policy. |
| Consent checkbox label | The wording next to the required consent tick box. |
| Success message | Shown after a successful signup. |

## Form fields

Required fields mirror the API contract: child's first name, child's last name, parent name and parent email, plus the consent checkbox required by this plugin. Date of birth, section, phone, postcode, notes and the sibling flag are optional.

## Plugin structure

```
scout-suite-waiting-list/
├── scout-suite-waiting-list.php                    Main plugin file
├── uninstall.php                                   Clean up on delete
├── readme.txt                                      WordPress.org readme
├── includes/
│   ├── class-scoutsuite-waitlist-api.php           Scout Suite API client
│   ├── class-scoutsuite-waitlist-form.php          Form rendering and submission
│   └── class-scoutsuite-waitlist-settings.php      Settings page
└── assets/
    ├── css/scoutsuite-waitlist.css                 Theme-inheriting styles
    └── js/scoutsuite-waitlist-block.js             Gutenberg block (no build step)
```

There is no build step anywhere: plain PHP, plain JavaScript, plain CSS.

## Licence

GPLv2 or later.
