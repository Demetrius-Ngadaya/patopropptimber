"use client";

import { use } from "react";
import type { ApiResponse } from "@/lib/api";
import { useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Guard } from "@/components/layout/Guard";
import { ErrorState, Spinner } from "@/components/ui";
import { PromotionForm } from "@/features/marketing/PromotionForm";
import type { PromotionDetail } from "@/features/marketing/types";

export default function EditPromotionPage({ params }: { params: Promise<{ id: string }> }) {
  const t = useT();
  const { id } = use(params);
  const q = useApi<ApiResponse<PromotionDetail>>(`admin/promotions/${id}`, undefined, { keepPrevious: false });
  if (q.isLoading) return <Spinner variant="page" />;
  if (q.error || !q.data) return <ErrorState message={q.error?.message ?? t("Not found.")} onRetry={() => q.refetch()} />;
  return <Guard perm="marketing.manage"><PromotionForm existing={q.data.data} /></Guard>;
}
