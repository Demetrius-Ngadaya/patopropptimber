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
