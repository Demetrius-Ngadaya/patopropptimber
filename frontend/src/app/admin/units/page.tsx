"use client";

import { Guard } from "@/components/layout/Guard";
import { ResourcePage } from "@/components/resource/ResourcePage";
import { Badge } from "@/components/ui";
import { useT } from "@/lib/i18n";

export default function UnitsPage() {
  const t = useT();
  return (
    <Guard perm="units.view">
      <ResourcePage
        title={t("Units of measure")}
        description={t("How products are counted. Turn on decimals for anything sold by weight or volume (kg, litre).")}
        endpoint="admin/units"
        perm="units"
        noun="unit"
        columns={[
          { key: "name", header: t("Name"), render: (r) => <span className="font-medium">{String(r.name)}</span> },
          { key: "short_name", header: t("Short name") },
          { key: "allow_decimal", header: t("Decimals"), render: (r) => (r.allow_decimal ? <Badge tone="blue">e.g. 0.25</Badge> : <Badge>{t("Whole units")}</Badge>) },
          { key: "is_active", header: t("Status"), render: (r) => (r.is_active ? <Badge tone="green">{t("Active")}</Badge> : <Badge tone="red">{t("Inactive")}</Badge>) },
        ]}
        fields={[
          { name: "name", label: t("Name"), required: true, placeholder: t("Kilogram") },
          { name: "short_name", label: t("Short name"), required: true, placeholder: "kg" },
          { name: "allow_decimal", label: t("Allow decimal quantities"), type: "switch", hint: t("For meat, vegetables, fuel, fabric ...") },
          { name: "is_active", label: t("Active"), type: "switch" },
        ]}
        defaults={{ name: "", short_name: "", allow_decimal: false, is_active: true }}
      />
    </Guard>
  );
}
