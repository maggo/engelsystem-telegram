# Changelog

Each release is tagged `vX.Y.Z`, matching `version` in `composer.json`. Releases with
migrations or new settings say so, as they need **Admin > Plugins > Telegram > Update** or an
admin entering something.

## 1.0.1 (unreleased)

- **Tested against:** Engelsystem `main` at `f09f95ec`.
- **No migration, no new settings.**
- **Security:** the bot no longer connects an account because its Engelsystem username matches
  someone's Telegram handle. Previously, with registration disabled, whoever held a handle equal
  to the username of a not-yet-connected volunteer account (e.g. one made with a password) could
  connect it and then log in to the website with Telegram. Now only accounts an admin set a
  handle for connect by handle: the CSV import, or **Set or reset a Telegram link** (formerly
  *Reset a Telegram link*), which also works for accounts that were never connected. Everyone
  else logs in and connects under Settings > OAuth.

## 1.0.0 (2026-10-27)

First release.

- **Tested against:** Engelsystem `main` at `f09f95ec`.
- **Migration:** `2026_10_27_000000_create_telegram_tables` creates `telegram_accounts`,
  `telegram_checkins`, `telegram_notifications`, `telegram_chats`, `telegram_updates` and
  `telegram_unknown_contacts`. It runs on **Install**.
- **New settings** (Settings > Telegram): `telegram_bot_token`, `telegram_client_id`,
  `telegram_client_secret`.
- **Routes:** `/admin/telegram` (with dashboard, import, link reset, bot connection and QR
  codes), `/telegram/webhook`, `/telegram/tick`; it also handles `/oauth/telegram`.
