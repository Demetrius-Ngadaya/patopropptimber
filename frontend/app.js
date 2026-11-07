// Startup file for DirectAdmin / cPanel "Setup Node.js App" (CloudLinux Passenger).
// Application root: patopro/frontend   ·   Startup file: app.js
//
// It runs the ready-made website build. On pptimber.co.tz the build is made on a PC
// (./publish-web.sh) and downloaded by ./deploy.sh into ~/patopro-web, because the
// server's Linux is too old to build Next.js 16. A build made on the server itself
// (.next/standalone) is used when there is no downloaded one.
const path = require("path");
const fs = require("fs");
const os = require("os");

const candidates = [
  process.env.PATOPRO_WEB_DIR && path.join(process.env.PATOPRO_WEB_DIR, "server.js"),
  path.join(os.homedir(), "patopro-web", "server.js"),
  path.join(__dirname, ".next", "standalone", "server.js"),
].filter(Boolean);

const server = candidates.find((file) => fs.existsSync(file));
if (!server) {
  console.error("PatoPro: no website build found. Run ./publish-web.sh on your PC, then ./deploy.sh frontend on the server.");
  process.exit(1);
}
process.env.NODE_ENV = "production";
require(server);
