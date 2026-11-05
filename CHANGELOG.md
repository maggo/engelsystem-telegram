# Changelog

Each release is tagged `vX.Y.Z`, matching `version` in `composer.json`. Releases with
migrations or new settings say so, as they need **Admin > Plugins > Telegram > Update** or an
admin entering something.

## 1.3.0 (2026-11-05)

- **Tested against:** Engelsystem `main` at `f09f95ec`.
- **No migration, no new settings.** New routes under `/admin/telegram` (`checkins`, `live`,
  `overview`, `attendance`, `needs`, `standby`) and `/telegram/api`; a cached route list must not
  survive the deploy.
- **Check-ins:** Admin > *Check-ins* (coordinators only) shows a day's check-ins per shift, who on
  a shift hasn't checked in and the no-shows; an admin can check someone in for any day with
  shifts or take a check-in back (for a dead phone, say), and check in every lead for every day
  at once. Check-ins by hand have no time until the person scans the code. Each change is logged.
- **Live dashboard:** Admin > *Live dashboard* (coordinators only), for a screen at the desk. Big
  numbers at the top: checked in and volunteers on shift (each split into AM and PM), and who is
  missing in the shift that runs now or comes next. Below, every shift and area of the day with
  checked in, assigned and needed and its status (open slots, waiting for check-ins, complete),
  and who hasn't checked in; the day can be picked. The page updates itself every 15 seconds,
  pops up each new check-in and throws confetti when an area's shift is complete (needs covered,
  everyone checked in). It doesn't change while someone moves the mouse or types, only after two
  minutes without.
- **Shift overview:** Admin > *Shift overview* (coordinators only), a page of its own for a
  screen. For a day (picked at the top): volunteers needed, and out of those how many checked in
  at the desk and how many their lead marked here in the roll call, plus no-shows; below, the same
  per area for AM and PM, with the shift's lead and who is on each shift. Under the date, how many
  of all volunteers checked in that day, with or without a shift (arrived, in an area, no leads).
  Updates itself every 30 seconds, not while someone uses it.
- **Attendance:** also shows per shift how many of those assigned checked in at the desk and
  how many their lead marked here in the roll call. Leads aren't listed any more (they count as
  checked in), also not among the no-shows. Lists of people are in order of status: here in the
  roll call (the final confirmation), checked in, not checked in, no-show.
- **Dashboard:** a day's check-ins per area and half day, with who checked in and who didn't.
- **Leads aren't counted as volunteers:** every number of volunteers (dashboard, `/team`,
  `/roster`, `/gaps`, gap alerts, roll call, replacements, *Swap people*) leaves out the leads,
  supporters of any area, and their slots in Engelsystem's need. Leads are still listed, marked
  as such.
- **Needed volunteers:** Admin > *Needed volunteers* (coordinators only) shows every shift's need
  in one table, one row per area and shift time, one column per day, and saves all changed
  numbers at once, all or nothing. The numbers are volunteers: Engelsystem's need also holds a
  slot for each lead on the shift, which saving adds. Only cells changed on the page are written;
  shifts from a schedule can't be changed there. A number someone else changed since the page
  was loaded isn't overwritten: nothing is saved and the page asks to reload. Each change is
  logged.
- **Standby moves:** Admin > *Standby moves* (coordinators only) shows every shift of a day's AM
  or PM at once: anyone on an area's shift can be moved to the standby shift of the same half day
  with one button, and anyone on standby to any area's shift of that half day, picked from a list
  that shows how full each shift is. A second click confirms each move, which then works like
  *Swap people*: the person joins the area they move to, and the bot tells them, the leads of
  both shifts and coordination. Leads aren't listed and can't be moved; no-shows and shifts that
  are over can't be moved, and shifts that are over aren't offered.
- **API for dashboards:** read-only JSON under `/telegram/api` (shifts, entries, check-ins,
  people, log), with the check-ins, onboarding, Telegram links, roll call marks and no-shows
  that Engelsystem's API doesn't show. Same API key as Engelsystem's API, sent as a header; needs
  `api` and `admin_user` (the log also `admin_log`). A day that isn't a date gets a 400, errors
  answer in JSON. See the README.

## 1.2.0 (2026-11-02)

- **Tested against:** Engelsystem `main` at `f09f95ec`.
- **No migration, no new settings.**
- **Swap people:** Admin > *Swap people* (coordinators only) shows two shifts of the same day and
  half day side by side, by default an area and standby, or an area and the free volunteers.
  Ticked people move to the other side, all or nothing; they join the area they move to. Moves
  onto a shift at the same time as another of the person's shifts are refused. The bot tells the
  people moved, the leads of both shifts and coordination; people not on Telegram are listed.
- **`/rollcall`:** a lead's roll call of their current or next shift, any time, also for a lead
  added later. *Is here* works any time; *No-show* once the roll call is out, 15 minutes before
  the shift. The roll call lists everyone until a lead marks them here; the daily check-in only
  shows as 📍.
- **`/roster`:** an area's shifts for a day (up to a week ahead): staffing, leads, everyone on
  the shift, who checked in, who isn't onboarded, no-shows. Coordinators see every area.
- **Times:** the bot shows every shift starting an hour before its real start (the end stays).
  The reminder goes out three hours before the real start and asks people to scan the QR code at
  the check-in desk, then be at the meeting place by the start it shows.
- **Removed:** the reminder's *Notify your lead* button and the Excuse / Not excused decision.
  Volunteers message their lead directly; the reminder names the lead with their handle. Buttons
  on older messages do nothing.
- **Dashboard:** one list of shifts from now until the end of tomorrow with open slots or, today,
  people not checked in at the desk, with who is missing; readable in dark themes.
- **Bot:** `/start` and `/shifts` list every upcoming shift; `/team` lists every member; the
  command menu shows `/team`, `/gaps`, `/rollcall` and `/roster` only to leads and coordinators;
  news on Telegram carry the whole text.
- **Fixes:** no gap alert for a no-show on a shift that is over; someone who hasn't started the
  bot is no longer connected from a group, which lost their reminders; the volunteer import flags
  rows that only matched an Engelsystem username; the admin page and QR codes don't wait for
  Telegram.

## 1.1.0 (2026-10-30)

- **Tested against:** Engelsystem `main` at `f09f95ec`.
- **No migration.** **New settings** (Settings > Telegram): `telegram_standby_area` (empty keeps
  the 1.0 behaviour) and `telegram_meeting_place`.
- **Shift import:** Admin > Telegram & bot > *Create the shifts* (title, area, location, start,
  end, needed). Existing shifts are reused, missing locations and shift types created.
- **Volunteer import:** a shift can name its own area (`2027-08-19 AM Kitchen`), the lead column
  takes "yes", "no" or the areas someone leads, and existing accounts get their areas and shifts
  added instead of being skipped. Re-importing adds nothing.
- **Standby:** with a standby area, replacements come only from standby people on shift now or
  within the hour (checked-in first); picking one moves them, joining the area if needed.
- **Notify your lead:** the reminder has one button instead of *Running late* / *Can't make it*
  (none for leads). The volunteer must write a message; the lead excuses, marks a no-show, or
  marks them here in the roll call. One message per shift.
- **Timing:** reminder two hours before the shift (to everyone, with the meeting place an hour
  before), roll call 15 minutes before (sent late rather than never).
- **Roll call:** one message per shift that turns into the replacement list and back; *is here*
  / *Excuse* / *No-show* buttons; replacements count as not here until the lead marks them; the
  header counts volunteers ("4/5 volunteers on the shift · all here").
- **News:** a new news with *Send notification* ticked goes to everyone on Telegram, once.
- **Hardening:** buttons only count on the message they were sent with; decisions and roll call
  buttons check the lead's area and timing; stored Telegram updates lose their content once
  handled; the bot no longer leaves a group when promoted in it.
- **Fixes:** no duplicate slot messages, no "everyone is checked in" for an empty roll call, no
  "standby" on check-in days without a shift, a double tap or late *Find replacement* keeps the
  message's buttons, bot texts no longer mention goodies.

## 1.0.1 (2026-10-28)

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
