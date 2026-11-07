"use client";

import { use } from "react";
import type { ApiResponse } from "@/lib/api";
import { useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Guard } from "@/components/layout/Guard";
import { ErrorState, Spinner } from "@/components/ui";
import { PlanEditor } from "@/features/purchase-plans/PlanEditor";
import type { PurchasePlan } from "@/features/purchase-plans/types";

export default function EditPurchasePlanPage({ params }: { params: Promise<{ id: string }> }) {
  const t = useT();
  const { id } = use(params);
  const q = useApi<ApiResponse<PurchasePlan>>(`admin/purchase-plans/${id}`, undefined, { keepPrevious: false });
  if (q.isLoading) return <Spinner variant="page" />;
  if (q.error || !q.data) return <ErrorState message={q.error?.message ?? t("Not found.")} />;
  return <Guard perm="purchase_plans.create"><PlanEditor existing={q.data.data} /></Guard>;
}
