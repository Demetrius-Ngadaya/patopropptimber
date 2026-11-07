"use client";

import { Guard } from "@/components/layout/Guard";
import { ResourcePage } from "@/components/resource/ResourcePage";
import { Badge } from "@/components/ui";
import { useT } from "@/lib/i18n";

export default function ExpenseCategoriesPage() {
  const t = useT();
  const manage = "expenses.manage_categories";
  return (
    <Guard perm={manage}>
      <ResourcePage
        title={t("Expense categories")}
        description={t("Used to group expenses in reports.")}
        endpoint="admin/expense-categories"
        perm="expenses"
        perms={{ create: manage, update: manage, delete: manage, restore: manage }}
        noun="category"
        columns={[
          { key: "name", header: t("Name"), render: (r) => <span className="font-medium">{String(r.name)}</span> },
          { key: "description", header: t("Description"), hideOnMobile: true },
          { key: "is_active", header: t("Status"), render: (r) => (r.is_active ? <Badge tone="green">{t("Active")}</Badge> : <Badge tone="red">{t("Inactive")}</Badge>) },
        ]}
        fields={[
          { name: "name", label: t("Name"), required: true },
          { name: "description", label: t("Description"), type: "textarea" },
          { name: "is_active", label: t("Active"), type: "switch" },
        ]}
        defaults={{ name: "", description: "", is_active: true }}
      />
    </Guard>
  );
}
