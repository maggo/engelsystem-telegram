# Telegram plugin for Engelsystem

Runs volunteer shifts over Telegram, on top of an unchanged [Engelsystem](https://github.com/engelsystem/engelsystem):

- **Login:** "Log in with Telegram" on the website.
- **Volunteers:** their shifts and meeting points in the bot, a reminder two hours before each shift (with where to meet an hour before it), and, if they're late or can't come, a required message to their lead.
- **Leads** (supporters of an area): team roster, roll call 15 minutes before the shift, excuse or no-show, open slots and replacements (from their area, or only from a standby area if there is one).
- **News:** a new Engelsystem news with *Send notification* ticked goes to everyone on Telegram.
- **Desk:** a daily check-in QR code, and one that completes onboarding.
- **Admins:** CSV imports for the shifts and the volunteers (with their areas, lead roles and shifts), setting or resetting someone's Telegram link, and an onboarding dashboard.
- **Connecting:** an account connects to Telegram by username only when an admin set that username (CSV import, or *Set or reset a Telegram link*). Otherwise people log in and connect under Settings > OAuth, as with any Engelsystem OAuth provider. The Engelsystem username is never matched.

Everything that counts is stored the Engelsystem way (arrival, shift entries, no-shows), so the same can be done in Engelsystem itself when the bot is down.

## Engelsystem version

Tested against Engelsystem `main` at [`f09f95ec`](https://github.com/engelsystem/engelsystem/commit/f09f95ecd012217d3a91c7db5e9e1be4c5177d32). No Engelsystem release has the plugin system yet. Each release in [CHANGELOG.md](CHANGELOG.md) names the commit it was tested against.

`f09f95ec` locks `symfony/http-foundation` 7.4.13, which is affected by [CVE-2026-108082](https://symfony.com/cve-2026-108082): any request can trigger it. Until upstream updates it, build Engelsystem with 7.4.21 or later:

```sh
composer update symfony/http-foundation --with-dependencies
```

## Install

1. Put this repository into Engelsystem as `resources/plugins/Telegram`. Engelsystem names a plugin after its folder, so keep that name.
2. In Engelsystem: **Admin > Plugins > Telegram > Install**. This creates the plugin's tables.
3. **Settings > Telegram:** enter the bot token and the login client ID and secret, all from @BotFather. Nothing goes into environment variables or config files. Optionally name a standby area (see below) and the meeting place, where volunteers meet an hour before their shift (e.g. *the volunteer lounge*).
4. In @BotFather, add the site URL and `<site URL>/oauth/telegram` to the bot's Login Widget allowed URLs.
5. **Admin > Telegram & bot > Connect bot to this site.**

## Standby

A standby area is an ordinary area (angel type) with its own shifts, for volunteers who wait to step in anywhere. Name it under **Settings > Telegram > Standby area**. Standby shifts must be shifts of their own, not an extra area on another area's shift. Then:

- Replacement lists for every other area offer only the people on a standby shift that covers most of the shift and runs now or starts within the hour, checked-in people first (everyone else is assumed to be on a shift of their own). People who wrote they're late or can't come are left out. Picking one moves them: their standby entry is removed, and coordination is told how many are left.
- The standby shift itself has no gap alerts and doesn't show in open slots, it is meant to shrink.
- Everything else works like any area: reminders, roll call (to coordination if the shift has no lead), no-shows.
- If the name matches no area, nobody is offered and leads are told the setting is wrong.

## Updating

Deploy the new release, then **Admin > Plugins > Telegram > Update**, which runs its new migrations. Migrations only ever add tables or columns, so the previous release keeps working on a newer schema, and rolling back the code needs no database step. Never click Uninstall: it deletes the plugin's tables and all their data.

## What the platform needs

- **Public HTTPS:** `POST /telegram/webhook` must be reachable from Telegram, with the `X-Telegram-Bot-Api-Secret-Token` header passed through.
- **Outbound HTTPS** to `api.telegram.org` and `oauth.telegram.org`.
- **PHP-FPM:** some work runs after the response is sent, for up to 300 seconds. Set `request_terminate_timeout` to at least 300 s, or 0.
- **Sessions:** Engelsystem's default `pdo` session driver.
- **No workers or cron jobs.** Scheduled messages run on site traffic. When the site is quiet, a pinger calling `GET /telegram/tick?key=…` every minute keeps them on time; the URL is on the Telegram & bot page.

## License

GPL-2.0-or-later, like Engelsystem. See [LICENSE](LICENSE).
