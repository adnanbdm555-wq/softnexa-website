# SoftNexa — setup on Hostinger

## What is in this folder

```
softnexa/
├── index.html          the website (all pages, demos, scope builder)
├── assets/             logo, favicons, service and industry images
├── api/
│   ├── config.php      database login + helpers  ← edit this
│   ├── db.sql          database tables            ← import this once
│   ├── contact.php     receives every lead, scores it, emails you
│   ├── track.php       records visits and what visitors do
│   └── settings.php    tells the site the trial length and founding slots
└── admin/              the control panel  (yoursite.com/admin)
    ├── dashboard.php   overview, funnel, traffic, latest leads
    ├── leads.php       pipeline, notes, visitor journey, CSV export
    ├── analytics.php   live visitors, devices, regions, heatmap, clicks
    └── settings.php    trial days, founding slots, alert email, password
```

## Setup — about 10 minutes

1. **Create the database** — hPanel → Databases → MySQL Databases.
   Note the database name, username and password Hostinger gives you
   (they look like `u123456789_softnexa`).

2. **Import the tables** — hPanel → phpMyAdmin → pick the new database →
   Import → choose `api/db.sql` → Go.

3. **Edit `api/config.php`** — put your database name, user and password
   in the four `define(...)` lines at the top, and your real email in
   `NOTIFY_EMAIL`.

4. **Upload** — hPanel → File Manager → `public_html` → upload everything
   inside the `softnexa/` folder (not the folder itself).

5. **Turn on SSL** — hPanel → Security → SSL → install and force HTTPS.

6. **Sign in** — open `https://yourdomain.com/admin`
   Username `admin`, password `softnexa123`.
   **Change the password immediately** in Settings.

## What gets tracked

| Event            | When it fires                                  |
|------------------|------------------------------------------------|
| `pageview`       | every page the visitor opens                   |
| `demo_open`      | a sector demo is opened                        |
| `demo_action`    | they switch module or add a record in a demo   |
| `wizard_step`    | each step of the scope builder                 |
| `wizard_done`    | they reach their estimate                      |
| `verify_run`     | they use the problem checker                   |
| `cta_click`      | any main button is clicked                     |
| `popup_view`     | a trend toast, service offer or exit offer shows |
| `popup_click`    | its button is clicked                          |
| `popup_close`    | the visitor closes it                          |
| `lead`           | the form is submitted                          |

Each visitor gets an anonymous session id for that browser tab only.
No third-party cookies, no Google Analytics, nothing sold or shared.
Add a line to your privacy policy saying you record anonymous visit
statistics on your own server.

## Lead scoring (0–100)

Calculated on the server, so it cannot be faked from the browser:
team size (up to 30) + urgency (up to 30) + modules chosen (up to 20)
+ problems ticked (up to 12) + work email (8) + phone (4) + company (4).
**70+ is hot** — the email subject starts with `[HOT 85]`.

## Country data

If you put the domain behind Cloudflare (free plan is enough), the
Analytics page will also show visitor countries automatically.

## Popups

Three kinds, all switchable in Admin → Settings:
- **Trend toasts** — small card bottom-left with a 2026 trend and a link to the matching service.
- **Service offers** — on a service page, after reading half of it, one free offer for that service.
- **Exit offer** — desktop only, when the mouse heads for the tab bar; once every 3 days.

Limits are built in: nothing in the first 20 seconds, at most 3 toasts and one
big popup per visit, none on demo or scope-builder pages, closing a toast stops
the rest for that visit. See Analytics → Popup performance for click rates.

## Housekeeping

Settings → Data housekeeping removes old tracking rows. Leads are never
deleted by it — only from the lead page itself.
