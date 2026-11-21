// Startup file for DirectAdmin / cPanel "Setup Node.js App" (CloudLinux Passenger).
// Application root: <business folder>/frontend   ·   Startup file: app.js
//   e.g. patopro/frontend for PP Timber, dablos/frontend for Dablos.
//
// It runs the ready-made website build. The build is made on a PC (./publish-web.sh)
// and downloaded by ./deploy.sh next to the business folder (~/patopro → ~/patopro-web,
// ~/dablos → ~/dablos-web), because the server's Linux is too old to build Next.js 16.
// One build serves every business; each one's addresses come from frontend/.env.
const path = require("path");
const fs = require("fs");
const os = require("os");

// This business's settings (API_URL, APP_URL, ...) from frontend/.env, unless the
// Node.js App screen already sets them.
const envFile = path.join(__dirname, ".env");
if (fs.existsSync(envFile)) {
  for (const line of fs.readFileSync(envFile, "utf8").split(/\r?\n/)) {
    const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*?)\s*$/);
    if (!m || line.trim().startsWith("#")) continue;
    const value = m[2].replace(/^(['"])(.*)\1$/, "$2");
    if (process.env[m[1]] === undefined || process.env[m[1]] === "") process.env[m[1]] = value;
  }
}

const businessRoot = path.resolve(__dirname, "..");
const candidates = [
  process.env.PATOPRO_WEB_DIR && path.join(process.env.PATOPRO_WEB_DIR, "server.js"),
  `${businessRoot}-web/server.js`,
  path.join(os.homedir(), "patopro-web", "server.js"),
  path.join(__dirname, ".next", "standalone", "server.js"),
].filter(Boolean);

const server = candidates.find((file) => fs.existsSync(file));
if (!server) {
  console.error("PatoPro: no website build found. Run ./publish-web.sh on your PC, then ./deploy.sh frontend on the server.");
  process.exit(1);
}
if (!process.env.API_URL) console.error("PatoPro: API_URL is not set. Add it to frontend/.env (see frontend/.env.example).");
process.env.NODE_ENV = "production";
require(server);
