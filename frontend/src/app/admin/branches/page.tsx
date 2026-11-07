"use client";

import { Guard } from "@/components/layout/Guard";
import { ResourcePage } from "@/components/resource/ResourcePage";
import { Badge } from "@/components/ui";
import { useT } from "@/lib/i18n";

export default function BranchesPage() {
  const t = useT();
  return (
    <Guard perm="branches.view">
      <ResourcePage
        title={t("Branches")}
        description={t("Each branch keeps its own stock, sales and expenses.")}
        endpoint="admin/branches"
        perm="branches"
        noun="branch"
        columns={[
          { key: "name", header: t("Branch"), render: (r) => <span className="font-medium">{String(r.name)} {r.is_main ? <Badge tone="navy" className="ml-1">{t("Main")}</Badge> : null}</span> },
          { key: "code", header: t("Code") },
          { key: "location", header: t("Location"), hideOnMobile: true },
          { key: "phone", header: t("Phone"), hideOnMobile: true },
          { key: "accepts_online_orders", header: t("Online orders"), render: (r) => (r.accepts_online_orders ? <Badge tone="green">{t("Yes")}</Badge> : <Badge>{t("No")}</Badge>) },
          { key: "is_active", header: t("Status"), render: (r) => (r.is_active ? <Badge tone="green">{t("Active")}</Badge> : <Badge tone="red">{t("Inactive")}</Badge>) },
        ]}
        fields={[
          { name: "name", label: t("Name"), required: true },
          { name: "code", label: t("Code"), required: true, hint: t("Short code, e.g. MAIN, KKOO.") },
          { name: "location", label: t("Location") },
          { name: "phone", label: t("Phone") },
          { name: "email", label: t("Email"), type: "email" },
          { name: "address", label: t("Address") },
          { name: "latitude", label: t("Latitude"), placeholder: "-6.7924", hint: t("For delivery fees from this branch. Empty = the shop location in Settings → Delivery.") },
          { name: "longitude", label: t("Longitude"), placeholder: "39.2083", hint: t("Tip: in Google Maps, press and hold on the branch to copy its two numbers.") },
          { name: "is_main", label: t("Main branch (head office)"), type: "switch" },
          { name: "accepts_online_orders", label: t("Accepts online orders"), type: "switch", hint: t("Customers can choose this branch for pickup or delivery.") },
          { name: "is_active", label: t("Active"), type: "switch" },
        ]}
        defaults={{ name: "", code: "", location: "", phone: "", email: "", address: "", latitude: "", longitude: "", is_main: false, accepts_online_orders: true, is_active: true }}
      />
    </Guard>
  );
}
