"use client";

import { useEffect, useRef, useState } from "react";
import { Camera, ScanBarcode, X } from "lucide-react";
import { toast } from "sonner";
import { api, ApiError, type ApiResponse } from "@/lib/api";
import { useApi, useDebounced } from "@/lib/hooks";
import { money, qty, toNumber, cn } from "@/lib/format";
import type { Product } from "@/lib/types";
import { useT } from "@/lib/i18n";

type Props = {
  onSelect: (product: Product) => void;
  /** API list endpoint; POS uses its own fast endpoint with branch stock. */
  source?: "admin/pos/products" | "admin/products";
  placeholder?: string;
  autoFocus?: boolean;
  showStock?: boolean;
  className?: string;
};

type BarcodeDetectorLike = { detect: (src: CanvasImageSource) => Promise<Array<{ rawValue: string }>> };

/**
 * Search by name / SKU, or scan a barcode. USB and Bluetooth scanners type the
 * code and press Enter, which looks the product up directly. Devices with a
 * camera can also scan with the camera button (where the browser supports it).
 */
export function ProductPicker({ onSelect, source = "admin/pos/products", placeholder = "Scan barcode or search product", autoFocus, showStock = true, className }: Props) {
  const tr = useT();
  const [term, setTerm] = useState("");
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(0);
  const [scanning, setScanning] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);
  const debounced = useDebounced(term.trim(), 200);

  const list = useApi<{ data: Product[] }>(debounced.length >= 2 ? source : null, { search: debounced, per_page: 20, limit: 20 });
  const results = (list.data?.data ?? []).slice(0, 20);

  useEffect(() => {
    setActive(0);
  }, [debounced]);

  const choose = (p: Product) => {
    onSelect(p);
    setTerm("");
    setOpen(false);
    inputRef.current?.focus();
  };

  const lookupCode = async (code: string) => {
    try {
      const res = await api.get<ApiResponse<Product>>(source === "admin/pos/products" ? "admin/pos/lookup" : "admin/products/lookup", { code });
      choose(res.data);
    } catch (e) {
      if (e instanceof ApiError && e.status === 404) {
        toast.error(tr("No product with barcode or SKU \"{code}\".", { code: code }));
      } else if (e instanceof ApiError) {
        toast.error(e.message);
      }
      setTerm("");
    }
  };

  const onKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === "ArrowDown") {
      e.preventDefault();
      setActive((a) => Math.min(a + 1, results.length - 1));
      setOpen(true);
    } else if (e.key === "ArrowUp") {
      e.preventDefault();
      setActive((a) => Math.max(a - 1, 0));
    } else if (e.key === "Enter") {
      e.preventDefault();
      const value = term.trim();
      if (!value) return;
      // Scanners send digits + Enter quickly; treat code-looking input as a barcode / SKU lookup.
      if (/^[A-Za-z0-9\-.]{4,}$/.test(value) && (!open || !results.length || /^\d{6,}$/.test(value))) {
        lookupCode(value);
      } else if (results[active]) {
        choose(results[active]);
      }
    } else if (e.key === "Escape") {
      setOpen(false);
    }
  };

  return (
    <div className={cn("relative", className)}>
      <div className="relative">
        <ScanBarcode className="pointer-events-none absolute left-3 top-1/2 size-5 -translate-y-1/2 text-ink-400" aria-hidden />
        <input
          ref={inputRef}
          value={term}
          autoFocus={autoFocus}
          onChange={(e) => {
            setTerm(e.target.value);
            setOpen(true);
          }}
          onFocus={() => setOpen(true)}
          onBlur={() => setTimeout(() => setOpen(false), 150)}
          onKeyDown={onKeyDown}
          placeholder={tr(placeholder)}
          aria-label={tr(placeholder)}
          data-product-search
          role="combobox"
          aria-expanded={open && results.length > 0}
          aria-controls="product-picker-list"
          aria-autocomplete="list"
          className="h-12 w-full rounded-[var(--radius-control)] border border-ink-200 bg-surface pl-11 pr-12 text-[15px] placeholder:text-ink-400 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20"
        />
        <button
          type="button"
          onClick={() => setScanning(true)}
          className="absolute right-2 top-1/2 -translate-y-1/2 rounded-md p-2 text-ink-500 hover:bg-ink-100 hover:text-ink-800"
          aria-label={tr("Scan with camera")}
          title={tr("Scan with camera")}
        >
          <Camera className="size-5" />
        </button>
      </div>

      {open && debounced.length >= 2 && (
        <ul id="product-picker-list" role="listbox" className="absolute z-30 mt-1 max-h-80 w-full overflow-y-auto rounded-lg bg-surface py-1 shadow-xl ring-1 ring-ink-200">
          {list.isLoading && <li className="px-3 py-2 text-sm text-ink-500">{tr("Searching…")}</li>}
          {!list.isLoading && !results.length && <li className="px-3 py-2 text-sm text-ink-500">{tr("No product matches “{debounced}”.", { debounced: debounced })}</li>}
          {results.map((p, i) => {
            const stock = toNumber(p.stock_quantity);
            return (
              <li
                key={p.id}
                role="option"
                aria-selected={i === active}
                onMouseDown={(e) => {
                  e.preventDefault();
                  choose(p);
                }}
                onMouseEnter={() => setActive(i)}
                className={cn("flex cursor-pointer items-center justify-between gap-3 px-3 py-2", i === active && "bg-brand-50")}
              >
                <div className="min-w-0">
                  <p className="truncate text-sm font-medium text-ink-900">{p.name}</p>
                  <p className="truncate text-xs text-ink-500">{[p.sku, p.barcode, p.category?.name].filter(Boolean).join("  |  ")}</p>
                </div>
                <div className="shrink-0 text-right">
                  <p className="num text-sm font-semibold text-ink-900">{money(p.selling_price)}</p>
                  {showStock && !p.is_service && (
                    <p className={cn("num text-xs", stock <= 0 ? "text-ember-600" : "text-ink-500")}>{stock <= 0 ? tr("Out of stock") : tr("{stock} in stock", { stock: qty(stock, p.unit?.short_name) })}</p>
                  )}
                </div>
              </li>
            );
          })}
        </ul>
      )}

      {scanning && <CameraScanner onClose={() => setScanning(false)} onCode={(code) => { setScanning(false); lookupCode(code); }} />}
    </div>
  );
}

function CameraScanner({ onClose, onCode }: { onClose: () => void; onCode: (code: string) => void }) {
  const tr = useT();
  const video = useRef<HTMLVideoElement>(null);
  const [error, setError] = useState<string | null>(null);
  const onCodeRef = useRef(onCode);
  useEffect(() => {
    onCodeRef.current = onCode;
  }, [onCode]);

  useEffect(() => {
    let stream: MediaStream | null = null;
    let stopped = false;
    const Detector = (window as unknown as { BarcodeDetector?: new (o: object) => BarcodeDetectorLike }).BarcodeDetector;

    (async () => {
      if (!Detector) {
        setError("This browser cannot scan with the camera. Use a barcode scanner or type the code.");
        return;
      }
      try {
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: "environment" } });
        if (!video.current) return;
        video.current.srcObject = stream;
        await video.current.play();
        const detector = new Detector({ formats: ["ean_13", "ean_8", "code_128", "code_39", "upc_a", "upc_e", "qr_code"] });
        const tick = async () => {
          if (stopped || !video.current) return;
          try {
            const codes = await detector.detect(video.current);
            if (codes[0]?.rawValue) {
              onCodeRef.current(codes[0].rawValue);
              return;
            }
          } catch {
            /* keep trying */
          }
          requestAnimationFrame(tick);
        };
        tick();
      } catch {
        setError("Camera access was blocked. Allow camera access in the browser settings to scan.");
      }
    })();

    return () => {
      stopped = true;
      stream?.getTracks().forEach((t) => t.stop());
    };
  }, []);

  return (
    <div className="fixed inset-0 z-[60] flex items-center justify-center bg-night-950/80 p-4" role="dialog" aria-modal="true" aria-label={tr("Scan barcode")}>
      <div className="w-full max-w-md overflow-hidden rounded-xl bg-surface">
        <div className="flex items-center justify-between border-b border-ink-100 px-4 py-3">
          <p className="font-semibold">{tr("Point the camera at the barcode")}</p>
          <button type="button" onClick={onClose} className="rounded-md p-1 hover:bg-ink-100" aria-label={tr("Close scanner")}>
            <X className="size-5" />
          </button>
        </div>
        {error ? <p className="p-5 text-sm text-ember-700">{tr(error)}</p> : <video ref={video} className="aspect-[4/3] w-full bg-black object-cover" muted playsInline />}
      </div>
    </div>
  );
}
