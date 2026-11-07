import { Fragment } from "react";

/**
 * Small, safe renderer for the assistant's answers: headings, lists, tables,
 * **bold** and `code`. It never injects HTML, so model output cannot run script.
 */
function inline(text: string, key: string) {
  const parts = text.split(/(\*\*[^*]+\*\*|`[^`]+`)/g);
  return parts.map((p, i) =>
    p.startsWith("**") && p.endsWith("**") ? <strong key={`${key}-${i}`}>{p.slice(2, -2)}</strong>
      : p.startsWith("`") && p.endsWith("`") ? <code key={`${key}-${i}`} className="rounded bg-ink-100 px-1 text-[0.9em]">{p.slice(1, -1)}</code>
        : <Fragment key={`${key}-${i}`}>{p}</Fragment>,
  );
}

export function Markdown({ text }: { text: string }) {
  const lines = text.replace(/\r/g, "").split("\n");
  const out: React.ReactNode[] = [];
  let i = 0;
  while (i < lines.length) {
    const line = lines[i];
    if (!line.trim()) { i++; continue; }
    if (/^\s*\|.*\|\s*$/.test(line)) {
      const rows: string[][] = [];
      while (i < lines.length && /^\s*\|.*\|\s*$/.test(lines[i])) {
        if (!/^\s*\|[\s:|-]+\|\s*$/.test(lines[i])) rows.push(lines[i].trim().slice(1, -1).split("|").map((c) => c.trim()));
        i++;
      }
      const [head, ...body] = rows;
      out.push(
        <div key={`t${i}`} className="my-2 overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead><tr>{head.map((c, j) => <th key={j} className="border-b border-ink-200 px-2 py-1 font-semibold">{inline(c, `h${j}`)}</th>)}</tr></thead>
            <tbody>{body.map((r, k) => <tr key={k}>{r.map((c, j) => <td key={j} className="border-b border-ink-100 px-2 py-1">{inline(c, `c${k}${j}`)}</td>)}</tr>)}</tbody>
          </table>
        </div>,
      );
      continue;
    }
    const heading = /^(#{1,4})\s+(.*)$/.exec(line);
    if (heading) { out.push(<p key={`h${i}`} className="mt-3 font-semibold text-ink-900">{inline(heading[2], `h${i}`)}</p>); i++; continue; }
    if (/^\s*([-*]|\d+[.)])\s+/.test(line)) {
      const ordered = /^\s*\d/.test(line);
      const items: string[] = [];
      while (i < lines.length && /^\s*([-*]|\d+[.)])\s+/.test(lines[i])) { items.push(lines[i].replace(/^\s*([-*]|\d+[.)])\s+/, "")); i++; }
      const List = ordered ? "ol" : "ul";
      out.push(<List key={`l${i}`} className={`my-1.5 space-y-0.5 pl-5 ${ordered ? "list-decimal" : "list-disc"}`}>{items.map((it, j) => <li key={j}>{inline(it, `li${i}${j}`)}</li>)}</List>);
      continue;
    }
    const para: string[] = [];
    while (i < lines.length && lines[i].trim() && !/^(#{1,4}\s|\s*([-*]|\d+[.)])\s+|\s*\|)/.test(lines[i])) { para.push(lines[i]); i++; }
    out.push(<p key={`p${i}`} className="my-1.5">{para.map((p, j) => <Fragment key={j}>{j > 0 && <br />}{inline(p, `p${i}${j}`)}</Fragment>)}</p>);
  }
  return <div className="text-sm leading-relaxed">{out}</div>;
}
