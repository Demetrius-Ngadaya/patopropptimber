"use client";

import { notFound } from "next/navigation";
import { use } from "react";
import type { ApiResponse } from "@/lib/api";
import { useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Guard } from "@/components/layout/Guard";
import { EmptyState, ErrorState, Spinner } from "@/components/ui";
import { DocumentEditor } from "@/features/documents/DocumentEditor";
import { isDocType, type DocumentDetail } from "@/features/documents/types";

export default function EditDocumentPage({ params }: { params: Promise<{ type: string; id: string }> }) {
  const t = useT();
  const { type, id } = use(params);
  const q = useApi<ApiResponse<DocumentDetail>>(`admin/documents/${id}`, undefined, { keepPrevious: false });
  if (!isDocType(type)) notFound();
  if (q.isLoading) return <Spinner variant="page" />;
  if (q.error || !q.data) return <ErrorState message={q.error?.message ?? t("Not found.")} onRetry={() => q.refetch()} />;
  if (!q.data.data.editable) return <EmptyState title={t("This can no longer be changed")} message={t("Only drafts and documents still waiting for the customer can be edited.")} />;
  return <Guard perm="documents.create"><DocumentEditor type={type} existing={q.data.data} /></Guard>;
}
