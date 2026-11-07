"use client";

import { Guard } from "@/components/layout/Guard";
import { ResourcePage } from "@/components/resource/ResourcePage";
import { Badge } from "@/components/ui";
import { useLookups } from "@/lib/lookups";
import { useT } from "@/lib/i18n";

export default function CategoriesPage() {
  const t = useT();
  const { categories = [] } = useLookups(["categories"]);
  return (
    <Guard perm="categories.view">
      <ResourcePage
        title={t("Categories")}
        description={t("Group products for the POS, reports and the online shop.")}
        endpoint="admin/categories"
        perm="categories"
        noun="category"
        columns={[
          { key: "name", header: t("Name"), render: (r) => <span className="font-medium">{String(r.name)}</span> },
          { key: "parent.name", header: t("Parent") },
          { key: "show_online", header: t("Online shop"), render: (r) => (r.show_online ? <Badge tone="green">{t("Shown")}</Badge> : <Badge>{t("Hidden")}</Badge>) },
          { key: "is_active", header: t("Status"), render: (r) => (r.is_active ? <Badge tone="green">{t("Active")}</Badge> : <Badge tone="red">{t("Inactive")}</Badge>) },
        ]}
        fields={[
          { name: "name", label: t("Name"), required: true },
          { name: "parent_id", label: t("Parent category"), type: "select", options: categories.map((c) => ({ value: c.id, label: c.name })) },
          { name: "sort_order", label: t("Sort order"), type: "number", hint: t("Lower numbers show first in the shop.") },
          { name: "description", label: t("Description"), type: "textarea" },
          { name: "show_online", label: t("Show in the online shop"), type: "switch" },
          { name: "is_active", label: t("Active"), type: "switch" },
        ]}
        defaults={{ name: "", parent_id: null, sort_order: 0, description: "", show_online: true, is_active: true }}
      />
    </Guard>
  );
}
