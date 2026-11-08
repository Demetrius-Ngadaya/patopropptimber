"use client";

import { useApi } from "./hooks";
import type { ApiResponse } from "./api";

export type PublicSettings = {
  business_name: string;
  business_type: string;
  business_phone: string;
  business_email: string;
  business_address: string;
  business_tin: string;
  business_vrn?: string;
  business_whatsapp?: string;
  business_website?: string;
  business_po_box?: string;
  business_bank_details?: string;
  business_latitude?: string | null;
  business_longitude?: string | null;
  /** Which company details appear on each kind of document (receipt, invoice, ...). */
  document_company_fields?: Record<string, string[]>;
  delivery_base_fee?: number;
  delivery_fee_per_km?: number;
  delivery_min_fee?: number;
  delivery_max_km?: number;
  business_logo_url: string | null;
  currency: string;
  receipt_width: "58mm" | "80mm" | "a4";
  receipt_footer: string;
  receipt_show_logo: boolean;
  shop_enabled: boolean;
  /** Customers may pay their order by phone (switched on and set up). */
  online_payments_shop?: boolean;
  shop_allow_registration: boolean;
  pos_show_categories?: boolean;
  shop_contact_widget?: boolean;
  shop_contact_phone?: string;
  shop_contact_sms?: string;
  shop_contact_whatsapp?: string;
  shop_contact_message?: string;
  shop_assistant_enabled?: boolean;
  ai_enabled?: boolean;
  shop_delivery_enabled: boolean;
  shop_delivery_fee: number;
  shop_min_order_amount: number;
  shop_show_stock: boolean;
  shop_tagline: string;
  shop_hero_subtitle?: string;
  shop_hero_slides?: boolean;
  session_timeout_minutes: number;
  messaging_channels?: string[];
  barcode_enabled?: boolean;
  qr_enabled?: boolean;
  theme_primary?: string | null;
  theme_accent?: string | null;
  theme_sidebar?: string | null;
};

export function usePublicSettings() {
  const q = useApi<ApiResponse<PublicSettings>>("public/settings");
  return { settings: q.data?.data, ...q };
}
