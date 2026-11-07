import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { onTablesChanged } from '../db/database';

/**
 * Read from the local database and re-read whenever one of `tables` changes
 * (a sale saved, a sync bringing new orders), so screens are always current.
 */
export function useLive<T>(load: () => Promise<T>, tables: string[], deps: unknown[] = []) {
  const [data, setData] = useState<T | undefined>(undefined);
  const [error, setError] = useState<string | null>(null);
  const loadRef = useRef(load);
  useLayoutEffect(() => {
    loadRef.current = load;
  });
  const seq = useRef(0);

  const reload = useCallback(async () => {
    const mine = ++seq.current;
    try {
      const result = await loadRef.current();
      if (mine === seq.current) {
        setData(result);
        setError(null);
      }
    } catch (e) {
      if (mine === seq.current) setError(e instanceof Error ? e.message : String(e));
    }
  }, []);

  useEffect(() => {
    reload();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps);

  useEffect(
    () => onTablesChanged((changed) => {
      if (changed.includes('*') || changed.some((t) => tables.includes(t))) reload();
    }),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [reload, tables.join(',')],
  );

  return { data, loading: data === undefined && !error, error, reload };
}

/** A value that settles after the user stops typing. */
export function useDebounced<T>(value: T, ms = 200): T {
  const [v, setV] = useState(value);
  useEffect(() => {
    const id = setTimeout(() => setV(value), ms);
    return () => clearTimeout(id);
  }, [value, ms]);
  return v;
}
