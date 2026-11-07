"use client";

import { Guard } from "@/components/layout/Guard";
import { PlanEditor } from "@/features/purchase-plans/PlanEditor";

export default function NewPurchasePlanPage() {
  return <Guard perm="purchase_plans.create"><PlanEditor /></Guard>;
}
