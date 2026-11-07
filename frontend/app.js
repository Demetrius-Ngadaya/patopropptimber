// Startup file for DirectAdmin / cPanel "Setup Node.js App" (CloudLinux Passenger).
// Application root: patopro/frontend   ·   Startup file: app.js
// It runs the production build made by `npm run build` (output: "standalone").
const path = require("path");
const fs = require("fs");

const server = path.join(__dirname, ".next", "standalone", "server.js");
if (!fs.existsSync(server)) {
  console.error("PatoPro: no build found. Run ./deploy.sh frontend (or npm run build) first.");
  process.exit(1);
}
process.env.NODE_ENV = "production";
require(server);
