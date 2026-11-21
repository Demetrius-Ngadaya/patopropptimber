# One repository, many businesses

Every business runs the same code from the one GitHub repository. On the server each
business has:

| | PP Timber | Dablos (example) |
|---|---|---|
| Code (copy of the repository) | `~/patopro` | `~/dablos` |
| Website build (downloaded) | `~/patopro-web` | `~/dablos-web` |
| Settings | `patopro/backend/.env`, `patopro/frontend/.env` | `dablos/backend/.env`, `dablos/frontend/.env` |
| Database | `pptimber_patopro` | e.g. `shezoni_dablos` |
| Website | `pptimber.co.tz` | `dablos.shezoni.com` |
| API | `api.pptimber.co.tz` | `api-dablos.shezoni.com` |
| Node.js App root | `patopro/frontend` | `dablos/frontend` |

Settings files and databases are never in git, so one business never sees another's
data. Names, logos, colours, prices and everything else are set inside each system.

## Adding a business

1. DirectAdmin → Subdomain Management: add `dablos` and `api-dablos` (or the
   domain's own names). SSL → Let's Encrypt for both.
2. DirectAdmin → MySQL Management: create a database and user.
3. On the server: `~/patopro/new-business.sh dablos` and answer the questions.
4. DirectAdmin → Setup Node.js App: create the app it prints (root `dablos/frontend`,
   startup file `app.js`), then RESTART.

## Updating all businesses

On the PC: `git push` (and `./publish-web.sh` when the website changed).
On the server: `~/patopro/update-all.sh` — it runs `./deploy.sh` in every folder
listed in `~/.patopro-sites`. One business can also be updated alone with
`cd ~/dablos && ./deploy.sh`.

Never edit code on the server; only the `.env` files there.

## Business codes (mobile app)

One app serves every business. At sign-in the person types their **business code**
(e.g. `dablos`); the app looks it up in
`https://shezoni.com/patopro/directory.json` and from then on talks to that
business's server. The code is remembered; switching to another code wipes the
previous business's data from the phone (and refuses while sales are still unsent).

Manage the list on the shezoni.com server:

    ~/patopro-base/directory.sh list
    ~/patopro-base/directory.sh add dablos "Dablos" https://api-dablos.shezoni.com
    ~/patopro-base/directory.sh add pptimber "PP Timber" https://api.pptimber.co.tz
    ~/patopro-base/directory.sh remove dablos

`new-business.sh` adds each new business by itself. Leaving the code empty uses the
server built into the app (PP Timber).
