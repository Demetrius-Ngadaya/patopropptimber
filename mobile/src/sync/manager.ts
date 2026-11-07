import { AppState, type AppStateStatus } from 'react-native';
import NetInfo, { type NetInfoState } from '@react-native-community/netinfo';
import { create } from 'zustand';
import { runSync, type SyncReport } from './engine';
import { createLocalStore, resetBranchData, type PulledHook } from './localStore';
import { httpTransport } from './transport';
import { getDb, onTablesChanged } from '../db/database';
import { outboxCounts, pruneDone } from '../db/outbox';
import { setMeta, getMeta } from '../db/meta';
import { runtime } from '../lib/runtime';
import { currentPrefs } from '../lib/prefs';

/**
 * When to sync. Automatically: when the internet comes back, when the app
 * comes to the front, every few minutes while open, a moment after any
 * change made on the phone, when a push notification arrives, and every
 * ~15 minutes in the background (see tasks.ts). Manually: "Sync now".
 */
export type SyncTrigger = 'manual' | 'startup' | 'online' | 'foreground' | 'interval' | 'change' | 'push' | 'background' | 'branch' | 'login';

type SyncUiState = {
  running: boolean;
  online: boolean | null;
  connection: string | null;
  lastSyncAt: string | null;
  lastOkAt: string | null;
  lastError: string | null;
  lastReport: SyncReport | null;
  waiting: number;
  attention: number;
  otherUsers: number;
  progress: string | null;
};

export const useSync = create<SyncUiState>(() => ({
  running: false,
  online: null,
  connection: null,
  lastSyncAt: null,
  lastOkAt: null,
  lastError: null,
  lastReport: null,
  waiting: 0,
  attention: 0,
  otherUsers: 0,
  progress: null,
}));

let pulledHook: PulledHook | undefined;
let running: Promise<SyncReport | null> | null = null;
let again: { trigger: SyncTrigger; force: boolean } | null = null;
let timer: ReturnType<typeof setInterval> | null = null;
let changeTimer: ReturnType<typeof setTimeout> | null = null;
let unsubscribers: (() => void)[] = [];

export function setPulledHook(fn: PulledHook) {
  pulledHook = fn;
}

export async function refreshCounts() {
  const counts = await outboxCounts();
  useSync.setState({ waiting: counts.waiting, attention: counts.attention, otherUsers: counts.otherUsers });
}

/**
 * Run a sync now, or right after the one in progress (never two at once).
 * Resolves with the report, or null when skipped.
 */
export async function requestSync(trigger: SyncTrigger, opts: { force?: boolean; only?: string[] } = {}): Promise<SyncReport | null> {
  const { token, userId } = runtime.get();
  if (!token || !userId) return null;
  const force = opts.force ?? trigger === 'manual';

  if (running) {
    again = { trigger, force: force || !!again?.force };
    return running;
  }

  const prefs = await currentPrefs();
  const net = await NetInfo.fetch().catch(() => null);
  // "Sync now" and a sync started because the server just answered always try:
  // the phone's network state can lag behind, and a failed try is reported anyway.
  const alwaysTry = trigger === 'manual' || trigger === 'online';
  if (!alwaysTry && net && net.isConnected === false) {
    useSync.setState({ online: false });
    startProbe();
    return null;
  }
  // "Wi-Fi only" applies to automatic syncs; Sync now always works.
  if (trigger !== 'manual' && prefs.sync.wifiOnly && net?.type === 'cellular') return null;

  running = (async () => {
    const started = Date.now();
    useSync.setState({ running: true, progress: null, lastError: null });
    let report: SyncReport | null = null;
    try {
      report = await runSync(createLocalStore(pulledHook), httpTransport, {
        force,
        only: opts.only,
        onProgress: (p) => useSync.setState({ progress: p.phase === 'push' ? `push:${p.done}` : `pull:${p.done}` }),
      });
      const at = new Date().toISOString();
      useSync.setState({
        lastSyncAt: at,
        lastReport: report,
        lastError: report.ok ? null : report.error,
        online: report.offline ? false : true,
        ...(report.ok ? { lastOkAt: at } : {}),
      });
      if (report.ok) await setMeta('lastOkAt', at);
      if (report.offline) startProbe();
      else stopProbe();
      const db = await getDb();
      await db.runAsync(
        'INSERT INTO sync_log (at, trigger, ok, pushed, pulled, duration_ms, message) VALUES (?, ?, ?, ?, ?, ?, ?)',
        at, trigger, report.ok ? 1 : 0, report.pushed, report.pulled, Date.now() - started, report.error,
      );
      if (report.unauthorized) runtime.unauthorized();
      await pruneDone();
    } catch (e) {
      useSync.setState({ lastError: e instanceof Error ? e.message : String(e) });
    } finally {
      await refreshCounts().catch(() => undefined);
      useSync.setState({ running: false, progress: null });
      running = null;
    }
    if (again) {
      const next = again;
      again = null;
      setTimeout(() => requestSync(next.trigger, { force: next.force }), 300);
    }
    return report;
  })();
  return running;
}

/** A change was saved on the phone: send it shortly (batches quick taps together). */
export function syncSoon() {
  refreshCounts().catch(() => undefined);
  if (changeTimer) clearTimeout(changeTimer);
  changeTimer = setTimeout(async () => {
    const prefs = await currentPrefs();
    if (prefs.sync.auto) requestSync('change');
  }, 1500);
}

async function restartInterval() {
  if (timer) clearInterval(timer);
  const prefs = await currentPrefs();
  if (!prefs.sync.auto) return;
  timer = setInterval(() => {
    if (AppState.currentState === 'active') requestSync('interval');
  }, Math.max(1, prefs.sync.intervalMinutes) * 60_000);
}

// ------------------------------------------------------------ offline probe
// While offline (by the phone's network state or by a failed sync), ask the
// server's health check every 15 s; the moment it answers, sync. Covers Wi-Fi
// without data, captive portals and phones that miss the "back online" event.
const PROBE_MS = 15_000;
let probeTimer: ReturnType<typeof setInterval> | null = null;

async function serverReachable(): Promise<boolean> {
  const { apiUrl } = runtime.get();
  if (!apiUrl) return false;
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 5_000);
  try {
    const res = await fetch(`${apiUrl.replace(/\/+$/, '')}/api/v1/health`, { signal: controller.signal, headers: { Accept: 'application/json' } });
    return res.ok;
  } catch {
    return false;
  } finally {
    clearTimeout(timeout);
  }
}

function startProbe() {
  if (probeTimer || !runtime.get().token) return;
  probeTimer = setInterval(async () => {
    if (AppState.currentState !== 'active' || running) return;
    if (await serverReachable()) {
      stopProbe();
      useSync.setState({ online: true });
      const prefs = await currentPrefs();
      if (prefs.sync.auto) requestSync('online');
    }
  }, PROBE_MS);
}

function stopProbe() {
  if (probeTimer) clearInterval(probeTimer);
  probeTimer = null;
}

/**
 * "Is the internet there?" is answered by our own server's health check, not
 * a Google address: what matters is whether PatoPro's server can be reached,
 * and some networks block or slow down the default check.
 */
function configureReachability() {
  const { apiUrl } = runtime.get();
  if (!apiUrl) return;
  NetInfo.configure({
    reachabilityUrl: `${apiUrl.replace(/\/+$/, '')}/api/v1/health`,
    reachabilityTest: async (response) => response.status === 200,
    reachabilityShortTimeout: 5_000,
    reachabilityLongTimeout: 60_000,
    reachabilityRequestTimeout: 10_000,
  });
}

export async function startSync() {
  stopSync();
  configureReachability();
  useSync.setState({ lastOkAt: await getMeta<string | null>('lastOkAt', null) });
  await refreshCounts();

  let wasOnline: boolean | null = null;
  let wasConnected: boolean | null = null;
  unsubscribers.push(
    NetInfo.addEventListener((state: NetInfoState) => {
      const connected = state.isConnected !== false;
      const online = connected && state.isInternetReachable !== false;
      useSync.setState({ online, connection: state.type });
      if (!online) startProbe();
      // Back on a network: try straight away, without waiting for the reachability
      // check (the sync itself finds out quickly if the server cannot be reached).
      if (connected && wasConnected === false) currentPrefs().then((p) => { if (p.sync.auto) requestSync('online'); });
      else if (online && wasOnline === false) currentPrefs().then((p) => { if (p.sync.auto) requestSync('online'); });
      wasOnline = online;
      wasConnected = connected;
    }),
  );
  const onAppState = (s: AppStateStatus) => {
    if (s === 'active') currentPrefs().then((p) => { if (p.sync.auto) requestSync('foreground'); });
  };
  const sub = AppState.addEventListener('change', onAppState);
  unsubscribers.push(() => sub.remove());
  unsubscribers.push(onTablesChanged((tables) => tables.includes('outbox') && refreshCounts().catch(() => undefined)));

  await restartInterval();
  requestSync('startup');
}

export function stopSync() {
  unsubscribers.forEach((u) => u());
  unsubscribers = [];
  if (timer) clearInterval(timer);
  if (changeTimer) clearTimeout(changeTimer);
  stopProbe();
  timer = null;
}

export async function onSyncPrefsChanged() {
  await restartInterval();
}

export async function switchBranch() {
  await resetBranchData();
  await requestSync('branch', { force: true });
}
