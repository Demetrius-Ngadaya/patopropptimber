"use client";

import { Guard } from "@/components/layout/Guard";
import { ResourcePage } from "@/components/resource/ResourcePage";
import { Badge } from "@/components/ui";
import { useT } from "@/lib/i18n";

/** Questions and answers shown in the online shop and used by its chat assistant. */
export default function FaqsPage() {
  const t = useT();
  const manage = "faqs.manage";
  return (
    <Guard perm={manage}>
      <ResourcePage
        title={t("Shop FAQs")}
        description={t("Questions and answers shown in the online shop. The chat assistant uses them first, so write the answers your customers should get.")}
        endpoint="admin/faqs"
        perm="faqs"
        perms={{ create: manage, update: manage, delete: manage, restore: manage }}
        noun="question"
        columns={[
          { key: "question", header: t("Question"), render: (r) => <span className="font-medium">{String(r.question)}</span> },
          { key: "category", header: t("Group"), hideOnMobile: true },
          { key: "sort_order", header: t("Order"), align: "right", hideOnMobile: true },
          { key: "is_active", header: t("Status"), render: (r) => (r.is_active ? <Badge tone="green">{t("Published")}</Badge> : <Badge>{t("Hidden")}</Badge>) },
        ]}
        fields={[
          { name: "question", label: t("Question"), required: true, span: 2, placeholder: t("e.g. Do you deliver on Sundays?") },
          { name: "answer", label: t("Answer"), type: "textarea", required: true },
          { name: "category", label: t("Group (optional)"), placeholder: t("e.g. Delivery, Payment, Returns") },
          { name: "sort_order", label: t("Order on the page"), type: "number", hint: t("Smaller numbers come first.") },
          { name: "is_active", label: t("Published in the shop"), type: "switch" },
        ]}
        defaults={{ question: "", answer: "", category: "", sort_order: 0, is_active: true }}
      />
    </Guard>
  );
}
