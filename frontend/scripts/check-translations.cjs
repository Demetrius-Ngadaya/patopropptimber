#!/usr/bin/env node
/**
 * Fails when a UI text has no Kiswahili translation, or when a translation
 * drops or invents a {placeholder}. Run: npm run i18n:check
 *
 * Texts are found by reading the TypeScript AST: every string passed to
 * t()/tr()/translate(), DescriptionList labels, and label/title/hint/description
 * properties in constant tables (navigation, lookups, settings help...).
 * Values the UI translates at runtime (statuses, enum values) are listed below.
 */
const fs = require("fs");
const path = require("path");
const ts = require("typescript");

const ROOT = path.join(__dirname, "..", "src");
const RUNTIME_VALUES = `paid completed received reconciled success sent active partial pending in_transit open processing confirmed ready
  out_for_delivery unpaid cancelled failed locked inactive expired overdue on_leave suspended terminated resigned approved rejected recovered
  draft present late absent half_day holiday off permanent contract casual intern id certificate cv letter other bank mobile_money cash
  rising falling steady high medium low male female returned refunded voided held closed`.split(/\s+/).map((v) => v.replace(/_/g, " "));

const LABEL_PROPS = new Set(["label", "title", "header", "hint", "description", "message"]);
const keys = new Set(RUNTIME_VALUES);
const lit = (n) => (n && (ts.isStringLiteral(n) || ts.isNoSubstitutionTemplateLiteral(n)) ? n.text : null);
const looksLikeText = (v) => /[A-Za-z]{2}/.test(v) && !/^[a-z-]+\//.test(v) && !/^(bg|text|ring|border|flex|grid)-/.test(v);

function scan(file) {
  const src = ts.createSourceFile(file, fs.readFileSync(file, "utf8"), ts.ScriptTarget.Latest, true, file.endsWith("x") ? ts.ScriptKind.TSX : ts.ScriptKind.TS);
  const visit = (n) => {
    if (ts.isCallExpression(n)) {
      const callee = n.expression.getText(src);
      if (/^(t|tr|translate)$/.test(callee)) {
        const v = lit(n.arguments[callee === "translate" ? 1 : 0]);
        if (v) keys.add(v);
      }
    }
    if (ts.isJsxAttribute(n) && n.name.getText(src) === "items" && n.initializer && ts.isJsxExpression(n.initializer)) {
      n.initializer.forEachChild(function pairs(c) {
        if (ts.isArrayLiteralExpression(c) && c.elements.length === 2 && lit(c.elements[0])) keys.add(lit(c.elements[0]));
        c.forEachChild(pairs);
      });
    }
    if (ts.isPropertyAssignment(n)) {
      let decl = n.parent && n.parent.parent;
      while (decl && (ts.isAsExpression(decl) || ts.isParenthesizedExpression(decl))) decl = decl.parent;
      const table = decl && ts.isVariableDeclaration(decl) ? decl.name.getText(src) : "";
      const v = lit(n.initializer);
      if (v && looksLikeText(v) && (LABEL_PROPS.has(n.name.getText(src)) || /LABELS?|Labels?|Help|^titles$/.test(table))) keys.add(v);
    }
    if (ts.isArrayLiteralExpression(n) && n.parent && ts.isVariableDeclaration(n.parent) && /SUGGESTIONS/.test(n.parent.name.getText(src))) {
      n.elements.forEach((e) => lit(e) && keys.add(lit(e)));
    }
    ts.forEachChild(n, visit);
  };
  visit(src);
}

(function walk(dir) {
  for (const f of fs.readdirSync(dir)) {
    const p = path.join(dir, f);
    if (fs.statSync(p).isDirectory()) { if (f !== "dictionaries") walk(p); }
    else if (/\.(tsx?)$/.test(f)) scan(p);
  }
})(ROOT);

// Read the dictionary without compiling TypeScript: it is a plain object literal.
const dictSrc = fs.readFileSync(path.join(ROOT, "lib/i18n/dictionaries/sw.ts"), "utf8");
const dictAst = ts.createSourceFile("sw.ts", dictSrc, ts.ScriptTarget.Latest, true);
const sw = {};
dictAst.forEachChild(function find(n) {
  if (ts.isObjectLiteralExpression(n)) n.properties.forEach((p) => { if (ts.isPropertyAssignment(p)) sw[lit(p.name) ?? p.name.getText(dictAst)] = lit(p.initializer); });
  else n.forEachChild(find);
});

const placeholders = (s) => (s.match(/\{\w+\}/g) || []).sort().join(",");
const missing = [...keys].filter((k) => /[A-Za-z]/.test(k) && !(k in sw)).sort();
const broken = Object.entries(sw).filter(([k, v]) => placeholders(k) !== placeholders(v ?? "")).map(([k]) => k);

if (process.argv.includes("--list-missing")) { console.log(JSON.stringify(missing, null, 2)); process.exit(0); }
console.log(`UI texts: ${keys.size}, Kiswahili entries: ${Object.keys(sw).length}`);
if (missing.length) console.error(`\nMissing Kiswahili (${missing.length}):\n  ${missing.join("\n  ")}`);
if (broken.length) console.error(`\nPlaceholder mismatch (${broken.length}):\n  ${broken.join("\n  ")}`);
process.exit(missing.length || broken.length ? 1 : 0);
