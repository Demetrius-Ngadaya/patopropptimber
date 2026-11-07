"use client";

import { notFound } from "next/navigation";
import { use } from "react";
import { Guard } from "@/components/layout/Guard";
import { DocumentEditor } from "@/features/documents/DocumentEditor";
import { isDocType } from "@/features/documents/types";

export default function NewDocumentPage({ params }: { params: Promise<{ type: string }> }) {
  const { type } = use(params);
  if (!isDocType(type)) notFound();
  return <Guard perm="documents.create"><DocumentEditor type={type} /></Guard>;
}
