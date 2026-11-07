/**
 * Hands a product selection from the product list to the label sheet without
 * putting thousands of ids in the address bar.
 */
export type LabelSelection = {
  ids?: number[];
  all?: boolean;
  search?: string;
  category_id?: string;
  active?: string;
};

const KEY = "mz_label_selection";

export function saveLabelSelection(sel: LabelSelection): void {
  try {
    sessionStorage.setItem(KEY, JSON.stringify(sel));
  } catch {
    /* private mode: the label page falls back to ?ids= */
  }
}

export function readLabelSelection(): LabelSelection | null {
  try {
    const raw = sessionStorage.getItem(KEY);
    return raw ? (JSON.parse(raw) as LabelSelection) : null;
  } catch {
    return null;
  }
}

/** The request body the API expects for a selection. */
export function selectionBody(sel: LabelSelection): Record<string, unknown> {
  return sel.all
    ? { all: true, search: sel.search || null, category_id: sel.category_id ? Number(sel.category_id) : null, active: sel.active === "" || sel.active === undefined ? null : sel.active === "1" }
    : { ids: sel.ids ?? [] };
}
