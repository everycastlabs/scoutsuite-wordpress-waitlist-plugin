# Scout Suite for WordPress

A WordPress plugin that connects a [Scout Suite](https://scoutsuite.app) Group, District or County to the website you already run.

It does **not** replace [WP Store Locator](https://wordpress.org/plugins/wp-store-locator/) or Skills for Life. Those plugins keep the public map, list, necker renderer and shortcodes. This plugin writes into the posts they already read (`wpsl_stores`, and `tribe_events` when The Events Calendar is active).

## What to use where

| Site | Org ID | What to put on the site |
|---|---|---|
| **District or County** | That District or County | Sync now. Skills for Life shows the Groups. Do not put the waitlist shortcode on the District homepage — it is a single-Group form. Each Group’s waiting-list link goes to Scout Suite (`{origin}/waiting-list/{groupId}`). |
| **Group** | That Group | Sync if you want the Group as a store, plus `[scoutsuite_waitlist]` on a page if parents should join the list on your site. |

## Directory and events sync

Sync pulls plugin-only Scout Suite APIs with `Authorization: Bearer {api_key}`:

```
GET {api_base}/api/orgs/{orgId}/wordpress/directory
GET {api_base}/api/orgs/{orgId}/wordpress/events
```

If either endpoint returns 404, sync stops and an admin notice is shown. The plugin does not invent Groups or events, and it does not ask Skills for Life to fetch a feed.

- **Stores:** upsert `wpsl_stores`, matched on `_scoutsuite_org_id`. Never a second store for the same org. Address, lat/lng when present, phone, email, website, meeting nights, necker colours, waiting-list URL, and Group vs District/County. `post_content` and the featured image are not overwritten after first create. Stores that vanish from Scout Suite are marked `_scoutsuite_sync_status = missing_from_source` and are not deleted.
- **Events:** upsert `tribe_events` with `_scoutsuite_event_id` only when The Events Calendar is active. Otherwise events are skipped and an admin notice is shown. No Scout Suite events CPT.

Click **Sync now** or wait for hourly WP-Cron. Re-running updates address, nights and waiting-list URL without duplicating stores or blanking the editor body.

## Waiting list form (Group sites)

Shortcode `[scoutsuite_waitlist]` and a Gutenberg block. Parents fill in the form; WordPress posts the entry to Scout Suite:

```
POST {api_base}/api/groups/{orgId}/waiting-list
```

The JSON body includes `"source": "wordpress"` alongside the existing fields. An API key is optional for this public endpoint. If you set one, it is sent as a Bearer header from the server and is never exposed to visitors.

The section dropdown comes from the public `signup-info` endpoint, cached for one hour.

This form always talks to **one** org. A District or County id will not list member Groups.

## Features

- Sync Groups into WP Store Locator / Skills for Life without replacing their UI
- Sync public events into The Events Calendar when that plugin is active
- Sync now button and hourly WP-Cron
- Shortcode `[scoutsuite_waitlist]` and a Gutenberg block
- Form fields that match the Scout Suite waiting list API
- GDPR ready: configurable privacy notice and required consent, recorded in the entry notes
- Honeypot, nonce, server-side sanitisation; no personal data in URLs
- Light form styling that inherits the theme
- Removes its settings and transients when deleted

## Requirements

- WordPress 6.0 or later
- PHP 7.4 or later
- A Scout Suite account for your Group, District or County
- WP Store Locator (and typically Skills for Life) for the public Group directory
- The Events Calendar, only if you want events written into WordPress

## Installation

1. Copy the `scout-suite-waiting-list` folder into `wp-content/plugins/`, or install the zip.
2. Activate **Scout Suite** on the Plugins screen.
3. Go to **Settings → Scout Suite**. Enter the Org ID (District, County, or Group) and API key.
4. Click **Sync now** if this site should show Groups from Scout Suite.
5. On a **Group** site, add `[scoutsuite_waitlist]` or the **Scout Suite Waiting List** block.

To build the installable zip from a checkout:

```sh
zip -r scout-suite-waiting-list.zip scout-suite-waiting-list -x "*.DS_Store"
```

## Local demo (Docker)

A District-style WordPress with WP Store Locator, The Events Calendar (plus Event Tickets, Events Shortcodes, and Events Block), a Scout-branded classic theme, Leaflet (no Google Maps), and this plugin mounted from the checkout:

```sh
cd demo
docker compose up -d
```

If a local Scout Suite API is up (`wrangler dev` on 8787, or the video-walkthrough servers on 8790), the seed job creates a District, three Groups with HQ pins, two public events, an API key, and runs **Sync now**. You do not paste an org id or key.

- Site: http://localhost:8888
- Admin: http://localhost:8888/wp-admin (`admin` / `scoutsuite`)
- Find a Group: http://localhost:8888/find-a-group/
- What's on: http://localhost:8888/whats-on/
- Waiting list form: http://localhost:8888/join-the-waiting-list/
- TEC archive (unthemed CPT): http://localhost:8888/events/

Re-run seed after the API comes up, or to refresh Groups/events:

```sh
docker compose run --rm seed
```

The video walkthrough `wordpress-website` does the same from `pnpm video:record wordpress-website` — Docker, Scout Suite data, plugin settings, and sync are all in `setup()`. `wordpress-waiting-list` uses the same site with `SCOUTSUITE_WAITLIST_GROUP_ID` pointing at a Group.

Find a Group uses **Leaflet**. Server-side WPSL geocoding is [postcodes.io](https://postcodes.io/), not Google. That lives in the **Skills for Life child** at [everycastlabs/sfl-wordpress-child-theme](https://github.com/everycastlabs/sfl-wordpress-child-theme) — seed clones it into `wp-content/themes/skillsforlife-child`. The demo parent in `demo/themes/skillsforlife` is Scout-branded chrome for recordings (purple header, Find a Group / What's on nav). Real district sites should use the Mersey Weaver Skills for Life zip as the parent.

What's on is a **page** (`/whats-on/`) using **Events Shortcodes for The Events Calendar** (`[events-calendar-templates …]`). Events themselves are still `tribe_events` from The Events Calendar — the shortcode (or the Events Block) is how most Scout sites list them on a themed page rather than the default `/events/` archive. Event Tickets is installed alongside, matching typical stacks.

`SCOUTSUITE_API_BASE_URL` defaults to `http://host.docker.internal:8787`. Use port `8790` on the video-walkthrough servers.

## Settings

| Setting | Purpose |
|---|---|
| Org ID | District, County, or Group ID from Scout Suite (the same id leaders see in URLs). Required. Existing installs that stored this as Group ID keep working. |
| Waiting list Group ID | Group the `[scoutsuite_waitlist]` form posts to. Needed when Org ID is a District or County. |
| API key | Bearer token `ss_at_…` from the Scout Suite developer portal. Stored server side only. Required for directory/events sync; optional for the public form. |
| API base URL | Defaults to `https://api.scoutsuite.app`. Override for staging. |
| Sections | One per line to override the list fetched from Scout Suite. Leave blank to fetch automatically. |
| Privacy notice | Shown above the consent checkbox. Edit to match your group's privacy policy. |
| Consent checkbox label | The wording next to the required consent tick box. |
| Success message | Shown after a successful signup. |
| Sync now | Pulls directory + public events immediately. The same job also runs hourly via WP-Cron. |

## Form fields

Required: child's first name, child's last name, parent name, parent email, and the consent checkbox. Date of birth, section, phone, postcode, notes and the sibling flag are optional.

## Not in v1

- Replacing SFL shortcodes, maps, or necker renderer
- A Scout Suite-branded directory theme
- Deleting WordPress posts that vanish from Scout Suite
- A District/County waitlist form with a Group picker

## Plugin structure

```
scout-suite-waiting-list/
├── scout-suite-waiting-list.php                    Main plugin file
├── uninstall.php                                   Clean up on delete
├── readme.txt                                      WordPress.org readme
├── includes/
│   ├── class-scoutsuite-waitlist-api.php           Scout Suite API client
│   ├── class-scoutsuite-waitlist-form.php          Form rendering and submission
│   ├── class-scoutsuite-waitlist-settings.php      Settings page
│   ├── class-scoutsuite-waitlist-sync.php          Sync now + WP-Cron
│   ├── class-scoutsuite-waitlist-stores.php        wpsl_stores upsert
│   └── class-scoutsuite-waitlist-events.php        tribe_events upsert
└── assets/
    ├── css/scoutsuite-waitlist.css                 Theme-inheriting styles
    └── js/scoutsuite-waitlist-block.js             Gutenberg block (no build step)
```

There is no build step: plain PHP, JavaScript and CSS.

## Licence

GPLv2 or later.
