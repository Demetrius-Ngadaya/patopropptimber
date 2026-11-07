"use client";

import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { useState } from "react";
import { Toaster } from "sonner";
import { ApiError } from "@/lib/api";
import { AuthProvider } from "@/lib/auth";
import { NetworkStatus } from "./NetworkStatus";
import { BrandColorSync } from "./BrandColorSync";
import { VisitTracker } from "./VisitTracker";
import { I18nProvider, type Locale } from "@/lib/i18n";
import { ThemeProvider, useTheme, type ThemePreference } from "@/lib/theme/ThemeProvider";

export function Providers({ children, locale, theme }: { children: React.ReactNode; locale: Locale; theme: ThemePreference }) {
  const [client] = useState(
    () =>
      new QueryClient({
        defaultOptions: {
          queries: {
            staleTime: 30_000,
            refetchOnWindowFocus: false,
            retry: (count, error) => !(error instanceof ApiError && error.status >= 400 && error.status < 500) && count < 2,
          },
        },
      }),
  );

  return (
    <ThemeProvider initialTheme={theme}>
      <I18nProvider initialLocale={locale}>
        <QueryClientProvider client={client}>
          <AuthProvider>
            <NetworkStatus />
            <BrandColorSync />
            <VisitTracker />
            {children}
            <ThemedToaster />
          </AuthProvider>
        </QueryClientProvider>
      </I18nProvider>
    </ThemeProvider>
  );
}

function ThemedToaster() {
  const { resolved } = useTheme();
  return <Toaster position="top-right" richColors closeButton theme={resolved} toastOptions={{ duration: 4500 }} />;
}
