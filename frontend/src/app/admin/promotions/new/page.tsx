"use client";

import { Guard } from "@/components/layout/Guard";
import { PromotionForm } from "@/features/marketing/PromotionForm";

export default function NewPromotionPage() {
  return <Guard perm="marketing.manage"><PromotionForm /></Guard>;
}
