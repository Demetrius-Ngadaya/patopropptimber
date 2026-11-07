<?php

namespace App\Support;

use App\Rules\EmailList;

/**
 * Every configurable setting with its default, type and validation.
 * Settings not listed here cannot be written through the API.
 */
final class SettingDefinitions
{
    /** Company details that can be printed on documents. */
    public const COMPANY_FIELDS = ['logo', 'address', 'phone', 'whatsapp', 'email', 'website', 'tin', 'vrn', 'bank'];

    /** Documents (and report / email headers) whose company details the admin chooses. */
    public const COMPANY_DOCUMENTS = ['receipt', 'invoice', 'quotation', 'proforma', 'statement', 'report', 'email'];

    /** Optional table columns on quotations, proformas and invoices. */
    public const DOCUMENT_COLUMNS = ['sku', 'unit', 'discount', 'tax'];

    /** Optional blocks on quotations, proformas and invoices. */
    public const DOCUMENT_BLOCKS = ['customer_tin', 'customer_vrn', 'customer_address', 'bank_details', 'terms', 'notes', 'signature', 'served_by', 'amount_in_words'];

    /** Product details an admin can put in a QR code / show to customers who scan it. */
    public const QR_FIELDS = ['name', 'price', 'wholesale_price', 'description', 'category', 'brand', 'unit', 'sku', 'barcode', 'image', 'availability', 'business'];

    /**
     * @return array<string, array{default: mixed, group: string, type: string, rules: array, public?: bool, label: string}>
     */
    public static function all(): array
    {
        return [
            // Business profile
            'business_name' => ['default' => 'My Business', 'group' => 'business', 'type' => 'string', 'rules' => ['required', 'string', 'max:120'], 'public' => true, 'label' => 'Business name'],
            'business_type' => ['default' => 'shop', 'group' => 'business', 'type' => 'string', 'rules' => ['required', 'in:shop,supermarket,pharmacy,butcher,hardware,wholesale,restaurant,other'], 'public' => true, 'label' => 'Business type'],
            'business_phone' => ['default' => '', 'group' => 'business', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:60'], 'public' => true, 'label' => 'Phone'],
            'business_email' => ['default' => '', 'group' => 'business', 'type' => 'string', 'rules' => ['nullable', 'email', 'max:120'], 'public' => true, 'label' => 'Email'],
            'business_address' => ['default' => '', 'group' => 'business', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:255'], 'public' => true, 'label' => 'Address'],
            'business_tin' => ['default' => '', 'group' => 'business', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:40'], 'public' => true, 'label' => 'TIN (Tax Identification Number)'],
            'business_vrn' => ['default' => '', 'group' => 'business', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:40'], 'public' => true, 'label' => 'VRN (VAT Registration Number)'],
            'business_whatsapp' => ['default' => '', 'group' => 'business', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:30'], 'public' => true, 'label' => 'WhatsApp number'],
            'business_website' => ['default' => '', 'group' => 'business', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:120'], 'public' => true, 'label' => 'Website'],
            'business_po_box' => ['default' => '', 'group' => 'business', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:60'], 'public' => true, 'label' => 'P.O. Box'],
            'business_bank_details' => ['default' => '', 'group' => 'documents', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:1000'], 'public' => true, 'label' => 'Bank and mobile money details for payment'],
            'delivery_base_fee' => ['default' => 0, 'group' => 'delivery', 'type' => 'float', 'rules' => ['required', 'numeric', 'min:0'], 'public' => true, 'label' => 'Starting fee for every delivery'],
            'delivery_fee_per_km' => ['default' => 0, 'group' => 'delivery', 'type' => 'float', 'rules' => ['required', 'numeric', 'min:0'], 'public' => true, 'label' => 'Fee for each kilometre (0 = use the flat delivery fee of the online shop)'],
            'delivery_min_fee' => ['default' => 0, 'group' => 'delivery', 'type' => 'float', 'rules' => ['required', 'numeric', 'min:0'], 'public' => true, 'label' => 'Lowest delivery fee'],
            'delivery_road_factor' => ['default' => 1.3, 'group' => 'delivery', 'type' => 'float', 'rules' => ['required', 'numeric', 'min:1', 'max:3'], 'label' => 'Road distance factor (roads are longer than a straight line; 1.3 is typical in town)'],
            'delivery_max_km' => ['default' => 0, 'group' => 'delivery', 'type' => 'float', 'rules' => ['required', 'numeric', 'min:0', 'max:1000'], 'public' => true, 'label' => 'Furthest we deliver, in km (0 = no limit)'],
            'delivery_round_to' => ['default' => 100, 'group' => 'delivery', 'type' => 'int', 'rules' => ['required', 'integer', 'min:1', 'max:10000'], 'label' => 'Round the fee up to the nearest'],
            'business_latitude' => ['default' => null, 'group' => 'delivery', 'type' => 'string', 'rules' => ['nullable', 'numeric', 'between:-90,90'], 'public' => true, 'label' => 'Shop location: latitude'],
            'business_longitude' => ['default' => null, 'group' => 'delivery', 'type' => 'string', 'rules' => ['nullable', 'numeric', 'between:-180,180'], 'public' => true, 'label' => 'Shop location: longitude'],

            // Which company details appear on which document.
            'document_company_fields' => ['default' => [
                'receipt' => ['logo', 'address', 'phone', 'tin', 'vrn'],
                'invoice' => ['logo', 'address', 'phone', 'email', 'website', 'tin', 'vrn', 'bank'],
                'quotation' => ['logo', 'address', 'phone', 'email', 'website', 'tin', 'vrn'],
                'proforma' => ['logo', 'address', 'phone', 'email', 'tin', 'vrn', 'bank'],
                'statement' => ['logo', 'address', 'phone', 'email', 'tin'],
                'report' => ['logo', 'address', 'phone', 'tin'],
                'email' => ['logo', 'address', 'phone', 'email'],
            ], 'group' => 'documents', 'type' => 'json', 'rules' => ['array'], 'public' => true, 'nested' => [
                'document_company_fields.*' => ['array'],
                'document_company_fields.*.*' => ['string', 'in:logo,address,phone,whatsapp,email,website,tin,vrn,bank'],
            ], 'label' => 'Company details on each document'],

            // Quotations, proforma invoices and invoices.
            'invoice_title' => ['default' => 'Tax invoice', 'group' => 'documents', 'type' => 'string', 'rules' => ['required', 'string', 'max:60'], 'label' => 'Invoice title'],
            'invoice_due_days' => ['default' => 14, 'group' => 'documents', 'type' => 'int', 'rules' => ['required', 'integer', 'min:0', 'max:365'], 'label' => 'Days until an invoice is due'],
            'invoice_terms' => ['default' => 'Payment is due by the date shown. Thank you for your business.', 'group' => 'documents', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:2000'], 'label' => 'Invoice terms'],
            'invoice_footer' => ['default' => '', 'group' => 'documents', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:300'], 'label' => 'Invoice footer'],
            'invoice_columns' => ['default' => ['unit', 'discount', 'tax'], 'group' => 'documents', 'type' => 'json', 'rules' => ['array'], 'nested' => ['invoice_columns.*' => ['string', 'in:sku,unit,discount,tax']], 'label' => 'Invoice columns'],
            'invoice_blocks' => ['default' => ['customer_tin', 'customer_vrn', 'customer_address', 'bank_details', 'terms', 'notes', 'signature', 'served_by'], 'group' => 'documents', 'type' => 'json', 'rules' => ['array'], 'nested' => ['invoice_blocks.*' => ['string', 'in:customer_tin,customer_vrn,customer_address,bank_details,terms,notes,signature,served_by,amount_in_words']], 'label' => 'Invoice sections'],
            'quotation_title' => ['default' => 'Quotation', 'group' => 'documents', 'type' => 'string', 'rules' => ['required', 'string', 'max:60'], 'label' => 'Quotation title'],
            'quotation_valid_days' => ['default' => 30, 'group' => 'documents', 'type' => 'int', 'rules' => ['required', 'integer', 'min:1', 'max:365'], 'label' => 'Days a quotation stays valid'],
            'quotation_terms' => ['default' => 'Prices are valid until the date shown and depend on stock availability.', 'group' => 'documents', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:2000'], 'label' => 'Quotation terms'],
            'quotation_footer' => ['default' => '', 'group' => 'documents', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:300'], 'label' => 'Quotation footer'],
            'quotation_columns' => ['default' => ['unit', 'discount'], 'group' => 'documents', 'type' => 'json', 'rules' => ['array'], 'nested' => ['quotation_columns.*' => ['string', 'in:sku,unit,discount,tax']], 'label' => 'Quotation columns'],
            'quotation_blocks' => ['default' => ['customer_tin', 'customer_address', 'terms', 'notes', 'signature', 'served_by'], 'group' => 'documents', 'type' => 'json', 'rules' => ['array'], 'nested' => ['quotation_blocks.*' => ['string', 'in:customer_tin,customer_vrn,customer_address,bank_details,terms,notes,signature,served_by,amount_in_words']], 'label' => 'Quotation sections'],
            'proforma_title' => ['default' => 'Proforma invoice', 'group' => 'documents', 'type' => 'string', 'rules' => ['required', 'string', 'max:60'], 'label' => 'Proforma title'],
            'proforma_valid_days' => ['default' => 14, 'group' => 'documents', 'type' => 'int', 'rules' => ['required', 'integer', 'min:1', 'max:365'], 'label' => 'Days a proforma stays valid'],
            'proforma_terms' => ['default' => 'Goods are released once payment is received.', 'group' => 'documents', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:2000'], 'label' => 'Proforma terms'],
            'proforma_footer' => ['default' => '', 'group' => 'documents', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:300'], 'label' => 'Proforma footer'],
            'proforma_columns' => ['default' => ['unit', 'discount', 'tax'], 'group' => 'documents', 'type' => 'json', 'rules' => ['array'], 'nested' => ['proforma_columns.*' => ['string', 'in:sku,unit,discount,tax']], 'label' => 'Proforma columns'],
            'proforma_blocks' => ['default' => ['customer_tin', 'customer_vrn', 'customer_address', 'bank_details', 'terms', 'notes', 'signature'], 'group' => 'documents', 'type' => 'json', 'rules' => ['array'], 'nested' => ['proforma_blocks.*' => ['string', 'in:customer_tin,customer_vrn,customer_address,bank_details,terms,notes,signature,served_by,amount_in_words']], 'label' => 'Proforma sections'],

            'business_logo' => ['default' => null, 'group' => 'business', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:255'], 'public' => true, 'label' => 'Logo'],
            // Appearance: the brand colours used across the system (empty = built-in colours).
            'theme_primary' => ['default' => '', 'group' => 'appearance', 'type' => 'string', 'rules' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'], 'public' => true, 'label' => 'Main colour (buttons, links, highlights)'],
            'theme_accent' => ['default' => '', 'group' => 'appearance', 'type' => 'string', 'rules' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'], 'public' => true, 'label' => 'Accent colour (totals, attention)'],
            'theme_sidebar' => ['default' => '', 'group' => 'appearance', 'type' => 'string', 'rules' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'], 'public' => true, 'label' => 'Sidebar colour'],

            // Software Engineer only (never listed for other users): modules switched off for this installation.
            'disabled_modules' => ['default' => [], 'group' => 'developer', 'type' => 'json', 'rules' => ['array'], 'nested' => ['disabled_modules.*' => ['string', 'max:40']], 'label' => 'Switched-off modules'],
            'currency' => ['default' => 'TZS', 'group' => 'business', 'type' => 'string', 'rules' => ['required', 'string', 'max:10'], 'public' => true, 'label' => 'Currency'],

            // HR & payroll (Tanzania mainland defaults; check current TRA / NSSF / WCF rates)
            'hr_paye_bands' => ['default' => [
                ['from' => 0, 'to' => 270000, 'rate' => 0, 'base' => 0],
                ['from' => 270000, 'to' => 520000, 'rate' => 8, 'base' => 0],
                ['from' => 520000, 'to' => 760000, 'rate' => 20, 'base' => 20000],
                ['from' => 760000, 'to' => 1000000, 'rate' => 25, 'base' => 68000],
                ['from' => 1000000, 'to' => null, 'rate' => 30, 'base' => 128000],
            ], 'group' => 'payroll', 'type' => 'json', 'rules' => ['required', 'array', 'min:1', 'max:15'], 'label' => 'Monthly PAYE bands', 'nested' => [
                'hr_paye_bands.*.from' => ['required', 'numeric', 'min:0'],
                'hr_paye_bands.*.to' => ['nullable', 'numeric', 'gt:hr_paye_bands.*.from'],
                'hr_paye_bands.*.rate' => ['required', 'numeric', 'min:0', 'max:100'],
                'hr_paye_bands.*.base' => ['required', 'numeric', 'min:0'],
            ]],
            'hr_nssf_employee_rate' => ['default' => 10, 'group' => 'payroll', 'type' => 'float', 'rules' => ['required', 'numeric', 'min:0', 'max:50'], 'label' => 'NSSF employee contribution (% of gross)'],
            'hr_nssf_employer_rate' => ['default' => 10, 'group' => 'payroll', 'type' => 'float', 'rules' => ['required', 'numeric', 'min:0', 'max:50'], 'label' => 'NSSF employer contribution (% of gross)'],
            'hr_sdl_rate' => ['default' => 3.5, 'group' => 'payroll', 'type' => 'float', 'rules' => ['required', 'numeric', 'min:0', 'max:20'], 'label' => 'Skills Development Levy (% of gross, employer)'],
            'hr_sdl_min_employees' => ['default' => 10, 'group' => 'payroll', 'type' => 'int', 'rules' => ['required', 'integer', 'min:0', 'max:1000'], 'label' => 'SDL applies from this many employees'],
            'hr_wcf_rate' => ['default' => 0.5, 'group' => 'payroll', 'type' => 'float', 'rules' => ['required', 'numeric', 'min:0', 'max:20'], 'label' => 'Workers Compensation Fund (% of gross, employer)'],
            'hr_nhif_employee_rate' => ['default' => 0, 'group' => 'payroll', 'type' => 'float', 'rules' => ['required', 'numeric', 'min:0', 'max:20'], 'label' => 'Health insurance employee (% of basic)'],
            'hr_nhif_employer_rate' => ['default' => 0, 'group' => 'payroll', 'type' => 'float', 'rules' => ['required', 'numeric', 'min:0', 'max:20'], 'label' => 'Health insurance employer (% of basic)'],
            'hr_working_days_per_month' => ['default' => 26, 'group' => 'payroll', 'type' => 'int', 'rules' => ['required', 'integer', 'min:1', 'max:31'], 'label' => 'Working days per month (for unpaid leave)'],
            'hr_work_start_time' => ['default' => '08:00', 'group' => 'payroll', 'type' => 'string', 'rules' => ['required', 'date_format:H:i'], 'label' => 'Work starts at (late after this)'],
            'hr_late_grace_minutes' => ['default' => 15, 'group' => 'payroll', 'type' => 'int', 'rules' => ['required', 'integer', 'min:0', 'max:240'], 'label' => 'Minutes of grace before marked late'],
            'hr_max_advance_percent' => ['default' => 50, 'group' => 'payroll', 'type' => 'int', 'rules' => ['required', 'integer', 'min:0', 'max:100'], 'label' => 'Maximum salary advance (% of basic)'],

            // AI
            'ai_enabled' => ['default' => true, 'group' => 'ai', 'type' => 'bool', 'rules' => ['boolean'], 'public' => true, 'label' => 'Enable AI features'],
            'ai_monthly_token_budget' => ['default' => 3000000, 'group' => 'ai', 'type' => 'int', 'rules' => ['required', 'integer', 'min:0', 'max:1000000000'], 'label' => 'Monthly AI token budget (0 = no limit)'],
            'ai_user_daily_messages' => ['default' => 60, 'group' => 'ai', 'type' => 'int', 'rules' => ['required', 'integer', 'min:1', 'max:5000'], 'label' => 'Assistant questions per user per day'],
            'ai_forecast_lead_days' => ['default' => 7, 'group' => 'ai', 'type' => 'int', 'rules' => ['required', 'integer', 'min:1', 'max:120'], 'label' => 'Supplier lead time used for reorder suggestions (days)'],
            'ai_forecast_cover_days' => ['default' => 14, 'group' => 'ai', 'type' => 'int', 'rules' => ['required', 'integer', 'min:1', 'max:180'], 'label' => 'Days of stock to keep after reordering'],
            'ai_service_level' => ['default' => 95, 'group' => 'ai', 'type' => 'int', 'rules' => ['required', 'integer', 'in:80,85,90,95,97,99'], 'label' => 'Service level for safety stock (%)'],

            'default_locale' => ['default' => 'en', 'group' => 'business', 'type' => 'string', 'rules' => ['required', 'in:en,sw'], 'public' => true, 'label' => 'Default language'],

            // Security
            'session_timeout_minutes' => ['default' => 30, 'group' => 'security', 'type' => 'int', 'rules' => ['required', 'integer', 'min:1', 'max:1440'], 'public' => true, 'label' => 'Staff session timeout (minutes of inactivity)'],
            'customer_session_timeout_minutes' => ['default' => 10080, 'group' => 'security', 'type' => 'int', 'rules' => ['required', 'integer', 'min:5', 'max:43200'], 'public' => true, 'label' => 'Customer session timeout (minutes)'],
            'mobile_session_days' => ['default' => 30, 'group' => 'security', 'type' => 'int', 'rules' => ['required', 'integer', 'min:1', 'max:365'], 'label' => 'Mobile app: days a phone stays signed in (it can sell offline in that time)'],
            'mobile_idle_minutes' => ['default' => 0, 'group' => 'security', 'type' => 'int', 'rules' => ['required', 'integer', 'min:0', 'max:43200'], 'label' => 'Mobile app: sign out after this many minutes unused (0 = never; the app has its own fingerprint / PIN lock)'],
            'max_login_attempts' => ['default' => 5, 'group' => 'security', 'type' => 'int', 'rules' => ['required', 'integer', 'min:3', 'max:20'], 'label' => 'Failed logins before lock'],
            'lockout_minutes' => ['default' => 15, 'group' => 'security', 'type' => 'int', 'rules' => ['required', 'integer', 'min:1', 'max:1440'], 'label' => 'Lock duration (minutes)'],
            'password_min_length' => ['default' => 8, 'group' => 'security', 'type' => 'int', 'rules' => ['required', 'integer', 'min:6', 'max:64'], 'label' => 'Minimum password length'],
            'single_session' => ['default' => false, 'group' => 'security', 'type' => 'bool', 'rules' => ['boolean'], 'label' => 'Log out other devices on login'],
            'two_factor_required_roles' => ['default' => '', 'group' => 'security', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:500', 'regex:/^[a-z0-9\-, ]*$/'], 'label' => 'Roles that must use two-factor login (comma-separated role codes, e.g. super-admin,admin,accountant)'],
            'log_read_requests' => ['default' => false, 'group' => 'security', 'type' => 'bool', 'rules' => ['boolean'], 'label' => 'Record page views (GET requests) in the activity log'],

            // Sales & stock
            'pos_show_categories' => ['default' => true, 'group' => 'sales', 'type' => 'bool', 'rules' => ['boolean'], 'public' => true, 'label' => 'Show category buttons at the till (off: frequently sold products and search only)'],
            'allow_negative_stock' => ['default' => false, 'group' => 'sales', 'type' => 'bool', 'rules' => ['boolean'], 'label' => 'Allow selling more than stock on hand'],
            'block_expired_sales' => ['default' => true, 'group' => 'sales', 'type' => 'bool', 'rules' => ['boolean'], 'label' => 'Block selling expired batches'],
            'expiry_alert_days' => ['default' => 180, 'group' => 'sales', 'type' => 'int', 'rules' => ['required', 'integer', 'min:1', 'max:1095'], 'label' => 'Warn this many days before expiry'],
            'default_tax_rate' => ['default' => 0, 'group' => 'sales', 'type' => 'float', 'rules' => ['required', 'numeric', 'min:0', 'max:100'], 'label' => 'Default tax rate (%)'],
            'prices_include_tax' => ['default' => true, 'group' => 'sales', 'type' => 'bool', 'rules' => ['boolean'], 'label' => 'Selling prices include tax'],
            'auto_generate_barcode' => ['default' => true, 'group' => 'labels', 'type' => 'bool', 'rules' => ['boolean'], 'label' => 'Generate a barcode for new products without one'],
            'receipt_width' => ['default' => '80mm', 'group' => 'sales', 'type' => 'string', 'rules' => ['required', 'in:58mm,80mm,a4'], 'public' => true, 'label' => 'Receipt paper'],
            'receipt_footer' => ['default' => 'Thank you for your business!', 'group' => 'sales', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:300'], 'public' => true, 'label' => 'Receipt footer'],
            'receipt_show_logo' => ['default' => true, 'group' => 'sales', 'type' => 'bool', 'rules' => ['boolean'], 'public' => true, 'label' => 'Show logo on receipt'],

            'auto_wholesale_by_quantity' => ['default' => true, 'group' => 'sales', 'type' => 'bool', 'rules' => ['boolean'], 'label' => 'Give the wholesale price automatically when the quantity reaches the product\'s wholesale minimum'],
            'credit_requires_approval' => ['default' => false, 'group' => 'sales', 'type' => 'bool', 'rules' => ['boolean'], 'label' => 'Only customers marked "can buy on credit" may take goods on credit'],
            'credit_default_days' => ['default' => 7, 'group' => 'sales', 'type' => 'int', 'rules' => ['required', 'integer', 'min:1', 'max:365'], 'public' => true, 'label' => 'Suggested days until a credit sale is due'],

            // Online shop
            'online_payments_enabled' => ['default' => false, 'group' => 'payments', 'type' => 'bool', 'rules' => ['boolean'], 'label' => 'Online payments by mobile money (Snippe)'],
            'online_payments_pos' => ['default' => false, 'group' => 'payments', 'type' => 'bool', 'rules' => ['boolean'], 'label' => 'Cashiers can request payment by phone at the till'],
            'online_payments_shop' => ['default' => false, 'group' => 'payments', 'type' => 'bool', 'rules' => ['boolean'], 'label' => 'Online-shop customers can pay their order by phone'],
            'shop_enabled' => ['default' => true, 'group' => 'shop', 'type' => 'bool', 'rules' => ['boolean'], 'public' => true, 'label' => 'Online shop enabled'],
            'shop_allow_registration' => ['default' => true, 'group' => 'shop', 'type' => 'bool', 'rules' => ['boolean'], 'public' => true, 'label' => 'Customers can register themselves'],
            'shop_delivery_enabled' => ['default' => true, 'group' => 'shop', 'type' => 'bool', 'rules' => ['boolean'], 'public' => true, 'label' => 'Offer delivery'],
            'shop_delivery_fee' => ['default' => 0, 'group' => 'shop', 'type' => 'float', 'rules' => ['required', 'numeric', 'min:0'], 'public' => true, 'label' => 'Delivery fee'],
            'shop_min_order_amount' => ['default' => 0, 'group' => 'shop', 'type' => 'float', 'rules' => ['required', 'numeric', 'min:0'], 'public' => true, 'label' => 'Minimum order amount'],
            'shop_show_stock' => ['default' => true, 'group' => 'shop', 'type' => 'bool', 'rules' => ['boolean'], 'public' => true, 'label' => 'Show "in stock / out of stock" to customers'],
            'shop_tagline' => ['default' => 'Fresh products, delivered to you.', 'group' => 'shop', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:200'], 'public' => true, 'label' => 'Shop tagline'],
            'shop_contact_widget' => ['default' => true, 'group' => 'shop', 'type' => 'bool', 'rules' => ['boolean'], 'public' => true, 'label' => 'Show the floating contact button (call, SMS, WhatsApp)'],
            'shop_contact_phone' => ['default' => '', 'group' => 'shop', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+ ()-]*$/'], 'public' => true, 'label' => 'Contact button: number for calls (empty = business phone)'],
            'shop_contact_sms' => ['default' => '', 'group' => 'shop', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+ ()-]*$/'], 'public' => true, 'label' => 'Contact button: number for SMS (empty = the call number)'],
            'shop_contact_whatsapp' => ['default' => '', 'group' => 'shop', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+ ()-]*$/'], 'public' => true, 'label' => 'Contact button: WhatsApp number (empty = business WhatsApp)'],
            'shop_contact_message' => ['default' => 'Habari, nina swali kuhusu bidhaa zenu.', 'group' => 'shop', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:300'], 'public' => true, 'label' => 'Contact button: first words of the SMS / WhatsApp message'],
            'shop_assistant_enabled' => ['default' => true, 'group' => 'shop', 'type' => 'bool', 'rules' => ['boolean'], 'public' => true, 'label' => 'Show the AI chat assistant in the online shop'],
            'shop_assistant_daily_limit' => ['default' => 40, 'group' => 'shop', 'type' => 'int', 'rules' => ['required', 'integer', 'min:1', 'max:1000'], 'label' => 'AI chat assistant: questions per visitor per day'],

            // Accounting
            'accounting_auto_post' => ['default' => true, 'group' => 'accounting', 'type' => 'bool', 'rules' => ['boolean'], 'label' => 'Post sales, purchases, expenses, savings and payroll to the books automatically'],
            'account_code_cash' => ['default' => null, 'group' => 'accounting', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:20', 'exists:accounts,code'], 'label' => 'Account that receives cash (code)'],
            'account_code_bank' => ['default' => null, 'group' => 'accounting', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:20', 'exists:accounts,code'], 'label' => 'Account that receives bank, card and cheque payments (code)'],
            'account_code_online' => ['default' => null, 'group' => 'accounting', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:20', 'exists:accounts,code'], 'label' => 'Account that receives payments by phone through Snippe (code; empty = the mobile money account)'],
            'account_code_mobile_money' => ['default' => null, 'group' => 'accounting', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:20', 'exists:accounts,code'], 'label' => 'Account that receives mobile money and Lipa namba (code)'],

            // Messages & notifications
            'messaging_channels' => ['default' => ['sms', 'email'], 'group' => 'notifications', 'type' => 'json', 'rules' => ['array'], 'nested' => ['messaging_channels.*' => ['string', 'distinct', 'in:sms,whatsapp,email']], 'public' => true, 'label' => 'Ways of sending messages that staff can choose'],
            'notification_channels' => ['default' => NotificationTypes::defaultRouting(), 'group' => 'notifications', 'type' => 'json', 'rules' => ['array'], 'nested' => ['notification_channels.*' => ['array'], 'notification_channels.*.*' => ['string', 'in:sms,whatsapp,email']], 'label' => 'How each kind of message is sent'],
            'notify_sms_numbers' => ['default' => '', 'group' => 'notifications', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:1000', 'regex:/^[0-9,;\s+]*$/'], 'label' => 'Phone numbers that receive SMS notifications and reports'],
            'notify_whatsapp_numbers' => ['default' => '', 'group' => 'notifications', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:1000', 'regex:/^[0-9,;\s+]*$/'], 'label' => 'Phone numbers that receive WhatsApp notifications and reports'],
            'notify_emails' => ['default' => '', 'group' => 'notifications', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:1000', new EmailList], 'label' => 'Email addresses that receive notifications and reports'],
            'message_locale' => ['default' => 'sw', 'group' => 'notifications', 'type' => 'string', 'rules' => ['required', 'in:en,sw'], 'label' => 'Language of SMS, WhatsApp and email messages'],
            'sms_receipts' => ['default' => false, 'group' => 'notifications', 'type' => 'bool', 'rules' => ['boolean'], 'label' => 'Send a receipt to customers after every sale'],
            'sms_order_updates' => ['default' => true, 'group' => 'notifications', 'type' => 'bool', 'rules' => ['boolean'], 'label' => 'Tell customers when their order status changes'],
            'sms_debt_reminders' => ['default' => true, 'group' => 'notifications', 'type' => 'bool', 'rules' => ['boolean'], 'label' => 'Automatic debt reminders to customers'],
            'debt_reminder_interval_days' => ['default' => 3, 'group' => 'notifications', 'type' => 'int', 'rules' => ['required', 'integer', 'min:1', 'max:60'], 'label' => 'Days between reminders for the same debt'],
            'report_exclude_branch_codes' => ['default' => '', 'group' => 'notifications', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:200'], 'label' => 'Branch codes left out of reports'],

            // Customer insights: how customers are grouped.
            'insights_vip_top_percent' => ['default' => 10, 'group' => 'customers', 'type' => 'int', 'rules' => ['required', 'integer', 'min:1', 'max:50'], 'label' => 'VIP: top customers by spending in the last 12 months (%)'],
            'insights_vip_min_spend' => ['default' => 0, 'group' => 'customers', 'type' => 'float', 'rules' => ['required', 'numeric', 'min:0'], 'label' => 'VIP: or everyone who spent at least this much in 12 months (0 = use the top % only)'],
            'insights_frequent_visits' => ['default' => 6, 'group' => 'customers', 'type' => 'int', 'rules' => ['required', 'integer', 'min:2', 'max:365'], 'label' => 'Frequent: at least this many purchases in the last 90 days'],
            'insights_new_days' => ['default' => 30, 'group' => 'customers', 'type' => 'int', 'rules' => ['required', 'integer', 'min:1', 'max:365'], 'label' => 'New: first purchase within this many days'],
            'insights_lost_days' => ['default' => 60, 'group' => 'customers', 'type' => 'int', 'rules' => ['required', 'integer', 'min:7', 'max:730'], 'label' => 'Stopped coming: no purchase for this many days'],
            'insights_declining_percent' => ['default' => 50, 'group' => 'customers', 'type' => 'int', 'rules' => ['required', 'integer', 'min:10', 'max:95'], 'label' => 'Coming less: purchases dropped by at least this % (last 90 days vs the 90 before)'],

            // Optional features (modules only some businesses need)
            'optional_modules' => ['default' => [], 'group' => 'features', 'type' => 'json', 'rules' => ['array'], 'nested' => ['optional_modules.*' => ['string', 'in:agency,shifts']], 'label' => 'Optional features switched on'],

            // Uwakala
            'agency_difference_alert' => ['default' => 1000, 'group' => 'agency', 'type' => 'float', 'rules' => ['required', 'numeric', 'min:0'], 'label' => 'Warn when the day closes short or over by more than'],
            'agency_carry_forward' => ['default' => true, 'group' => 'agency', 'type' => 'bool', 'rules' => ['boolean'], 'label' => 'Start each morning with last night\'s closing figures filled in'],

            // Shifts
            'shift_handover_required' => ['default' => true, 'group' => 'shifts', 'type' => 'bool', 'rules' => ['boolean'], 'label' => 'Ending a shift needs a handover when someone works next'],
            'shift_start_early_minutes' => ['default' => 60, 'group' => 'shifts', 'type' => 'int', 'rules' => ['required', 'integer', 'min:0', 'max:720'], 'label' => 'A shift can be started this many minutes early'],

            // Purchasing: reminders to pay suppliers
            'purchase_reminder_days_before' => ['default' => 3, 'group' => 'purchasing', 'type' => 'int', 'rules' => ['required', 'integer', 'min:0', 'max:90'], 'label' => 'Remind this many days before a supplier payment is due'],
            'purchase_reminder_channels' => ['default' => ['sms', 'email'], 'group' => 'purchasing', 'type' => 'json', 'rules' => ['array'], 'nested' => ['purchase_reminder_channels.*' => ['string', 'in:sms,whatsapp,email']], 'label' => 'Usual way of reminding'],

            // Barcodes & QR codes
            'barcode_enabled' => ['default' => true, 'group' => 'labels', 'type' => 'bool', 'rules' => ['boolean'], 'public' => true, 'label' => 'Use barcodes'],
            'qr_enabled' => ['default' => true, 'group' => 'labels', 'type' => 'bool', 'rules' => ['boolean'], 'public' => true, 'label' => 'Use QR codes'],
            'qr_mode' => ['default' => 'link', 'group' => 'labels', 'type' => 'string', 'rules' => ['required', 'in:link,text'], 'label' => 'What the QR code holds'],
            'qr_public_fields' => ['default' => ['name', 'price', 'description', 'availability', 'business'], 'group' => 'labels', 'type' => 'json', 'rules' => ['array'], 'nested' => ['qr_public_fields.*' => ['string', 'distinct', 'in:'.implode(',', self::QR_FIELDS)]], 'label' => 'Product details customers see when they scan'],
        ];
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }
}
