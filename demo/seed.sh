#!/bin/sh
# Idempotent WP-CLI bootstrap for the Scout Suite plugin demo site.
set -eu

SITE_URL="${SITE_URL:-http://localhost:8888}"
TITLE="${SITE_TITLE:-Ridgeway District Scouts}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-scoutsuite}"
ADMIN_EMAIL="${ADMIN_EMAIL:-district@example.test}"

echo "[seed] Waiting for WordPress files…"
i=0
while [ "$i" -lt 90 ]; do
  if [ -f /var/www/html/wp-includes/version.php ] && [ -f /var/www/html/wp-config.php ]; then
    break
  fi
  i=$((i + 1))
  sleep 2
done
if [ ! -f /var/www/html/wp-config.php ]; then
  echo "[seed] WordPress files never appeared." >&2
  exit 1
fi

echo "[seed] Waiting for database…"
i=0
while [ "$i" -lt 60 ]; do
  if wp db check >/dev/null 2>&1; then
    break
  fi
  i=$((i + 1))
  sleep 2
done
wp db check >/dev/null

if ! wp core is-installed >/dev/null 2>&1; then
  echo "[seed] Installing WordPress…"
  wp core install \
    --url="$SITE_URL" \
    --title="$TITLE" \
    --admin_user="$ADMIN_USER" \
    --admin_password="$ADMIN_PASSWORD" \
    --admin_email="$ADMIN_EMAIL" \
    --skip-email
else
  echo "[seed] WordPress already installed."
fi

wp rewrite structure '/%postname%/' --hard
wp option update blogdescription "Find a Group and what's on across the District"
wp option update timezone_string "Europe/London"
wp option update date_format "j F Y"
wp option update time_format "g:ia"

echo "[seed] Installing plugins…"
wp plugin install wp-store-locator --activate --quiet || wp plugin activate wp-store-locator
wp plugin install the-events-calendar --activate --quiet || wp plugin activate the-events-calendar
wp plugin install event-tickets --activate --quiet || wp plugin activate event-tickets
wp plugin install template-events-calendar --activate --quiet || wp plugin activate template-events-calendar
wp plugin install events-block-for-the-events-calendar --activate --quiet || wp plugin activate events-block-for-the-events-calendar
wp plugin activate scout-suite-waiting-list

echo "[seed] Installing Skills for Life child theme…"
SFL_CHILD_REPO="${SFL_CHILD_THEME_REPO:-https://github.com/everycastlabs/sfl-wordpress-child-theme}"
SFL_CHILD_REF="${SFL_CHILD_THEME_REF:-main}"
SFL_CHILD_DIR="/var/www/html/wp-content/themes/skillsforlife-child"
if [ -f "$SFL_CHILD_DIR/style.css" ] && [ "${SFL_CHILD_THEME_FORCE:-0}" != "1" ]; then
  echo "[seed] Child theme already present, skipping download."
else
  SFL_CHILD_TMP="$(mktemp -d)"
  if ! curl -fsSL "${SFL_CHILD_REPO}/archive/refs/heads/${SFL_CHILD_REF}.tar.gz" | tar -xz -C "$SFL_CHILD_TMP"; then
    echo "[seed] Could not download ${SFL_CHILD_REPO}@${SFL_CHILD_REF}" >&2
    rm -rf "$SFL_CHILD_TMP"
    exit 1
  fi
  SFL_CHILD_SRC="$(find "$SFL_CHILD_TMP" -maxdepth 1 -mindepth 1 -type d | head -n 1)"
  if [ -z "$SFL_CHILD_SRC" ] || [ ! -f "$SFL_CHILD_SRC/style.css" ]; then
    echo "[seed] Downloaded archive was not a WordPress theme." >&2
    rm -rf "$SFL_CHILD_TMP"
    exit 1
  fi
  rm -rf "$SFL_CHILD_DIR"
  mv "$SFL_CHILD_SRC" "$SFL_CHILD_DIR"
  rm -rf "$SFL_CHILD_TMP"
  echo "[seed] Child theme installed from ${SFL_CHILD_REPO}@${SFL_CHILD_REF}"
fi
wp theme activate skillsforlife-child

wp eval '
$o = get_option( "tribe_events_calendar_options", array() );
if ( ! is_array( $o ) ) {
  $o = array();
}
$o["viewOption"] = "list";
update_option( "tribe_events_calendar_options", $o );
update_option( "tribe_skip_welcome", 1 );
echo "tec options set\n";
'

# UK start point so the locator is not sitting on the US default.
wp eval '
$settings = get_option( "wpsl_settings", array() );
if ( ! is_array( $settings ) ) {
  $settings = array();
}
$settings["start_latlng"] = "51.37,-0.49";
$settings["start_name"]   = "Addlestone, Surrey";
$settings["zoom_level"]   = 10;
$settings["auto_locate"]  = 0;
update_option( "wpsl_settings", $settings );
echo "wpsl start point set\n";
'

ensure_page() {
  slug="$1"
  page_title="$2"
  content="$3"
  existing="$(wp post list --post_type=page --name="$slug" --field=ID --format=ids 2>/dev/null || true)"
  if [ -n "$existing" ]; then
    wp post update "$existing" --post_title="$page_title" --post_content="$content" --post_status=publish >/dev/null
    echo "$existing"
    return
  fi
  wp post create --post_type=page --post_status=publish --post_name="$slug" --post_title="$page_title" --post_content="$content" --porcelain
}

HOME_ID="$(ensure_page home "Ridgeway District" "$(cat <<'HTML'
<!-- wp:paragraph -->
<p>Welcome. Use Find a Group for the map of HQ pins, and What's on for the events list — both filled from Scout Suite after sync.</p>
<!-- /wp:paragraph -->
HTML
)")"

FIND_ID="$(ensure_page find-a-group "Find a Group" '[wpsl]')"

WHATS_ON_ID="$(ensure_page whats-on "What's on" "$(cat <<'HTML'
<!-- wp:paragraph -->
<p>What's on across the District. These events are created in Scout Suite and appear here after sync.</p>
<!-- /wp:paragraph -->
<!-- wp:shortcode -->
[events-calendar-templates category="all" template="default" style="style-1" date_format="default" start_date="" end_date="" limit="10" order="ASC" hide-venue="no" time="future" socialshare="no"]
<!-- /wp:shortcode -->
HTML
)")"

WAITLIST_PAGE_ID="$(ensure_page join-the-waiting-list "Join the waiting list" '[scoutsuite_waitlist]')"

wp option update show_on_front page
wp option update page_on_front "$HOME_ID"

# TEC still owns the /events/ CPT archive. Public "What's on" is the themed page above.

SAMPLE_ID="$(wp post list --post_type=page --name=sample-page --field=ID --format=ids 2>/dev/null || true)"
if [ -n "$SAMPLE_ID" ]; then
  wp post delete "$SAMPLE_ID" --force >/dev/null
fi
HELLO_ID="$(wp post list --post_type=post --name=hello-world --field=ID --format=ids 2>/dev/null || true)"
if [ -n "$HELLO_ID" ]; then
  wp post delete "$HELLO_ID" --force >/dev/null
fi

echo "[seed] Building primary menu…"
if ! wp menu list --fields=name --format=csv 2>/dev/null | tail -n +2 | grep -qx Primary; then
  wp menu create "Primary" >/dev/null
fi
EXISTING_ITEMS="$(wp menu item list Primary --format=ids 2>/dev/null || true)"
if [ -n "$EXISTING_ITEMS" ]; then
  # shellcheck disable=SC2086
  wp menu item delete $EXISTING_ITEMS >/dev/null
fi
wp menu item add-post Primary "$FIND_ID" --title="Find a Group" >/dev/null
wp menu item add-post Primary "$WHATS_ON_ID" --title="What's on" >/dev/null
wp menu location assign Primary primary >/dev/null || true

ORG_ID="${SCOUTSUITE_ORG_ID:-}"
WAITLIST_GROUP_ID="${SCOUTSUITE_WAITLIST_GROUP_ID:-}"
API_KEY="${SCOUTSUITE_API_KEY:-}"
API_BASE="${SCOUTSUITE_API_BASE_URL:-http://host.docker.internal:8787}"
AUTO_SEED="${SCOUTSUITE_AUTO_SEED:-1}"
E2E_SECRET="${SCOUTSUITE_E2E_SECRET:-local-e2e}"

probe_api() {
  for candidate in "$API_BASE" "http://host.docker.internal:8790" "http://host.docker.internal:8787"; do
    if [ -z "$candidate" ]; then
      continue
    fi
    if curl -sf --connect-timeout 2 "${candidate}/health" >/dev/null 2>&1; then
      echo "$candidate"
      return 0
    fi
  done
  return 1
}

if [ -z "$ORG_ID" ] || [ -z "$API_KEY" ]; then
  if [ "$AUTO_SEED" = "1" ]; then
    LIVE_API="$(probe_api || true)"
    if [ -n "$LIVE_API" ]; then
      echo "[seed] Scout Suite API at $LIVE_API — creating District, Groups, events, and API key…"
      SEED_JSON="$(curl -sf --connect-timeout 5 -X POST "${LIVE_API}/internal/e2e/wordpress-demo" \
        -H "x-e2e-secret: ${E2E_SECRET}" \
        -H "Content-Type: application/json" \
        -d "{\"districtName\":\"${TITLE}\"}" || true)"
      if [ -n "$SEED_JSON" ]; then
        ORG_ID="$(printf '%s' "$SEED_JSON" | php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["data"]["districtId"] ?? "";')"
        API_KEY="$(printf '%s' "$SEED_JSON" | php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["data"]["apiKey"] ?? "";')"
        API_BASE="$LIVE_API"
      fi
      if [ -z "$ORG_ID" ] || [ -z "$API_KEY" ]; then
        echo "[seed] Auto-seed from Scout Suite failed. Response: ${SEED_JSON:-empty}"
      fi
    else
      echo "[seed] No Scout Suite API on 8790/8787 yet — WordPress is up; sync when the API is running."
    fi
  fi
fi

php_quote() {
  printf "'%s'" "$(printf '%s' "$1" | sed "s/'/'\\\\''/g")"
}

wp eval "
\$opts = get_option( 'scoutsuite_waitlist_options', array() );
if ( ! is_array( \$opts ) ) {
  \$opts = array();
}
\$opts['api_base_url'] = $(php_quote "$API_BASE");
\$org = $(php_quote "$ORG_ID");
\$waitlist = $(php_quote "$WAITLIST_GROUP_ID");
\$key = $(php_quote "$API_KEY");
if ( \$org !== '' ) {
  \$opts['org_id'] = \$org;
  \$opts['group_id'] = \$org;
}
if ( \$waitlist !== '' ) {
  \$opts['waitlist_group_id'] = \$waitlist;
}
if ( \$key !== '' ) {
  \$opts['api_key'] = \$key;
}
update_option( 'scoutsuite_waitlist_options', \$opts );
echo 'Scout Suite settings saved (org_id=' . ( \$opts['org_id'] ?? '' ) . ', waitlist_group_id=' . ( \$opts['waitlist_group_id'] ?? '' ) . ')' . PHP_EOL;
"

if [ -n "$ORG_ID" ] && [ -n "$API_KEY" ]; then
  echo "[seed] Syncing Groups and events from Scout Suite…"
  STORE_IDS="$(wp post list --post_type=wpsl_stores --field=ID --format=ids 2>/dev/null || true)"
  if [ -n "$STORE_IDS" ]; then
    # shellcheck disable=SC2086
    wp post delete $STORE_IDS --force >/dev/null
  fi
  EVENT_IDS="$(wp post list --post_type=tribe_events --field=ID --format=ids 2>/dev/null || true)"
  if [ -n "$EVENT_IDS" ]; then
    # shellcheck disable=SC2086
    wp post delete $EVENT_IDS --force >/dev/null
  fi
  wp eval 'echo wp_json_encode( ( new ScoutSuite_Waitlist_Sync() )->run( "manual" ), JSON_PRETTY_PRINT ) . PHP_EOL;'
fi

wp rewrite flush --hard

echo "[seed] Done."
echo "  Site:  $SITE_URL"
echo "  Admin: $SITE_URL/wp-admin  ($ADMIN_USER / $ADMIN_PASSWORD)"
echo "  Find a Group: $SITE_URL/find-a-group/"
echo "  What's on:    $SITE_URL/whats-on/"
echo "  Waiting list: $SITE_URL/join-the-waiting-list/"
echo "  TEC archive:  $SITE_URL/events/"
if [ -z "$ORG_ID" ] || [ -z "$API_KEY" ]; then
  echo "  Start the Scout Suite API, then: docker compose run --rm seed"
fi
