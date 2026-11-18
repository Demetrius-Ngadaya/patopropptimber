import { create } from 'zustand';

/** Goes up each time an order update arrives, so open order screens reload. */
export const useOrderAlerts = create<{ version: number; bump: () => void }>((set) => ({
  version: 0,
  bump: () => set((s) => ({ version: s.version + 1 })),
}));
