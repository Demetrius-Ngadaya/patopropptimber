/**
 * Small, dependency-free holder for what every network call needs (token,
 * branch, language, server address). The session store fills it; the API
 * client and the background tasks read it. Kept separate so neither imports
 * the other (no import cycles between session, api and sync).
 */
type Listener = () => void;

export type Runtime = {
  apiUrl: string;
  token: string | null;
  userId: number | null;
  branchId: number | null;
  locale: 'en' | 'sw';
  installationId: string | null;
};

const state: Runtime = {
  apiUrl: '',
  token: null,
  userId: null,
  branchId: null,
  locale: 'en',
  installationId: null,
};

let unauthorized: Listener | null = null;

export const runtime = {
  get: (): Readonly<Runtime> => state,
  set(patch: Partial<Runtime>) {
    Object.assign(state, patch);
  },
  /** Called once when the server says the login is no longer valid. */
  onUnauthorized(fn: Listener | null) {
    unauthorized = fn;
  },
  unauthorized() {
    unauthorized?.();
  },
};
