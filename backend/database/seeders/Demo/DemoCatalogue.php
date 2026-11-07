<?php

namespace Database\Seeders\Demo;

/**
 * The fixed part of the demo business: "Mwamba Group", one owner with seven
 * branches, each a different kind of shop: a supermarket, two pharmacies, a
 * hardware, a butchery, a drinks depot and a timber yard. Everything else
 * (sales, purchases, transfers, debts, payroll...) is generated day by day.
 */
final class DemoCatalogue
{
    public const PASSWORD = 'Demo12345x';

    /** code => [name, location, address, phone, lat, lng, online orders] (MAIN exists already and is renamed) */
    public const BRANCHES = [
        'MAIN' => ['Mwamba Supermarket Kariakoo', 'Kariakoo, Dar es Salaam', 'Mtaa wa Msimbazi Na. 14, Kariakoo', '255222180001', -6.8197, 39.2734, true],
        'PHARMA1' => ['Mwamba Pharmacy Kariakoo', 'Kariakoo, Dar es Salaam', 'Mtaa wa Uhuru Na. 52, Kariakoo', '255222180002', -6.8170, 39.2755, false],
        'PHARMA2' => ['Mwamba Pharmacy Mbezi', 'Mbezi Luis, Dar es Salaam', 'Barabara ya Morogoro, Mbezi Mwisho', '255222180003', -6.7389, 39.1557, false],
        'HARDWARE' => ['Mwamba Hardware Tegeta', 'Tegeta, Dar es Salaam', 'Barabara ya Bagamoyo, Tegeta Nyuki', '255222180004', -6.6550, 39.2050, true],
        'BUTCHER' => ['Mwamba Butchery Buguruni', 'Buguruni, Dar es Salaam', 'Buguruni Sokoni, Mtaa wa Malapa', '255222180005', -6.8370, 39.2420, true],
        'DRINKS' => ['Mwamba Drinks Depot Mwenge', 'Mwenge, Dar es Salaam', 'Barabara ya Sam Nujoma, Mwenge', '255222180006', -6.7686, 39.2236, true],
        'TIMBER' => ['Mwamba Timber Yard Kibaha', 'Kibaha, Pwani', 'Barabara ya Morogoro, Kibaha Maili Moja', '255232400007', -6.7700, 38.9230, false],
    ];

    /** Till sales per normal day [min, max] (weekends add two). */
    public const DAILY_SALES = [
        'MAIN' => [8, 13], 'PHARMA1' => [6, 10], 'PHARMA2' => [4, 7], 'HARDWARE' => [3, 6],
        'BUTCHER' => [5, 9], 'DRINKS' => [4, 8], 'TIMBER' => [2, 4],
    ];

    /**
     * Branches that get some products from another branch instead of buying them:
     * to => [from, transfer type, share of the sender's opening stock, skus|null (= all of the sender's own products)]
     */
    public const SUPPLIED_BY_TRANSFER = [
        'PHARMA2' => ['PHARMA1', 'free', 0.45, null],
        'MAIN' => ['DRINKS', 'credit', 0.2, ['MAJ-500', 'MAJ-1.5', 'SOD-500', 'SOD-MIR', 'ENE-250']],
    ];

    /** username => [name, role slug, branch code|null (= all branches), phone, email] */
    public const STAFF = [
        'meneja' => ['Neema Mwakyusa', 'admin', null, '255754100001', 'neema@mwamba-demo.co.tz'],
        'mhasibu' => ['Rehema Kimaro', 'accountant', null, '255754100002', 'rehema@mwamba-demo.co.tz'],
        'hrmanager' => ['Joyce Lyimo', 'hr-manager', null, '255754100003', 'hr@mwamba-demo.co.tz'],
        'stoo' => ['Hamisi Said', 'storekeeper', null, '255754100004', 'stores@mwamba-demo.co.tz'],
        'meneja_super' => ['Emmanuel Shayo', 'branch-admin', 'MAIN', '255754100005', null],
        'super_cashier' => ['Asha Juma', 'cashier', 'MAIN', '255754100006', null],
        'super_cashier2' => ['Fatma Ally', 'cashier', 'MAIN', '255754100007', null],
        'meneja_famasia' => ['Salome Mushi', 'branch-admin', 'PHARMA1', '255754100008', 'famasia@mwamba-demo.co.tz'],
        'famasia_cashier' => ['Baraka Mrema', 'cashier', 'PHARMA1', '255754100009', null],
        'meneja_mbezi' => ['Paulo Ngowi', 'branch-admin', 'PHARMA2', '255754100010', null],
        'mbezi_cashier' => ['Grace Mollel', 'cashier', 'PHARMA2', '255754100011', null],
        'meneja_hardware' => ['Yusuf Kombo', 'branch-admin', 'HARDWARE', '255754100012', null],
        'hardware_cashier' => ['Ramadhani Seif', 'cashier', 'HARDWARE', '255754100013', null],
        'meneja_bucha' => ['Abdallah Mzee', 'branch-admin', 'BUTCHER', '255754100014', null],
        'bucha_cashier' => ['Mwanaisha Omari', 'cashier', 'BUTCHER', '255754100015', null],
        'meneja_vinywaji' => ['Godlisten Massawe', 'branch-admin', 'DRINKS', '255754100016', null],
        'vinywaji_cashier' => ['Happy Urio', 'cashier', 'DRINKS', '255754100017', null],
        'meneja_mbao' => ['Elias Mapunda', 'branch-admin', 'TIMBER', '255754100018', null],
        'mbao_cashier' => ['Daniel Kisanga', 'cashier', 'TIMBER', '255754100019', null],
        'muuzaji_daudi' => ['Daudi Komba', 'seller', 'DRINKS', '255754100020', null],
        'muuzaji_saidi' => ['Saidi Mrisho', 'seller', 'MAIN', '255754100021', null],
        'rider_ally' => ['Ally Bakari', 'delivery', null, '255754100022', null],
        'rider_petro' => ['Petro Mlay', 'delivery', 'DRINKS', '255754100023', null],
        'mlinzi' => ['Zawadi Haji', 'employee', 'MAIN', '255754100024', null],
    ];

    /** First names of the women on the staff list (for the employee records). */
    public const WOMEN = ['Neema', 'Rehema', 'Joyce', 'Asha', 'Fatma', 'Salome', 'Grace', 'Mwanaisha', 'Happy', 'Zawadi'];

    /** Who runs each branch: [manager (wholesale, returns, receiving), cashier(s)] */
    public const BRANCH_PEOPLE = [
        'MAIN' => ['meneja_super', ['super_cashier', 'super_cashier2']],
        'PHARMA1' => ['meneja_famasia', ['famasia_cashier']],
        'PHARMA2' => ['meneja_mbezi', ['mbezi_cashier']],
        'HARDWARE' => ['meneja_hardware', ['hardware_cashier']],
        'BUTCHER' => ['meneja_bucha', ['bucha_cashier']],
        'DRINKS' => ['meneja_vinywaji', ['vinywaji_cashier']],
        'TIMBER' => ['meneja_mbao', ['mbao_cashier']],
    ];

    /** Monthly basic salary, position and department of each staff member. */
    public const PAY = [
        'meneja' => [2200000, 'General Manager', 'Management'],
        'mhasibu' => [1300000, 'Accountant', 'Finance'],
        'hrmanager' => [1100000, 'HR Officer', 'Management'],
        'stoo' => [800000, 'Procurement & Stores Officer', 'Stores'],
        'meneja_super' => [1000000, 'Branch Manager', 'Management'],
        'super_cashier' => [550000, 'Cashier', 'Sales'],
        'super_cashier2' => [500000, 'Cashier', 'Sales'],
        'meneja_famasia' => [1400000, 'Pharmacist in Charge', 'Pharmacy'],
        'famasia_cashier' => [650000, 'Pharmaceutical Technician', 'Pharmacy'],
        'meneja_mbezi' => [1300000, 'Pharmacist in Charge', 'Pharmacy'],
        'mbezi_cashier' => [600000, 'Pharmaceutical Technician', 'Pharmacy'],
        'meneja_hardware' => [950000, 'Branch Manager', 'Management'],
        'hardware_cashier' => [500000, 'Cashier', 'Sales'],
        'meneja_bucha' => [800000, 'Head Butcher', 'Management'],
        'bucha_cashier' => [450000, 'Cashier', 'Sales'],
        'meneja_vinywaji' => [950000, 'Branch Manager', 'Management'],
        'vinywaji_cashier' => [500000, 'Cashier', 'Sales'],
        'meneja_mbao' => [900000, 'Yard Manager', 'Management'],
        'mbao_cashier' => [480000, 'Cashier', 'Sales'],
        'muuzaji_daudi' => [400000, 'Field Seller', 'Sales'],
        'muuzaji_saidi' => [400000, 'Field Seller', 'Sales'],
        'rider_ally' => [380000, 'Delivery Rider', 'Logistics'],
        'rider_petro' => [380000, 'Delivery Rider', 'Logistics'],
        'mlinzi' => [300000, 'Security Guard', 'Logistics'],
    ];

    /** category => [Swahili label for description, show online] */
    public const CATEGORIES = [
        'Groceries' => ['Vyakula vikavu', true],
        'Household' => ['Usafi na matumizi ya nyumbani', true],
        'Dairy & Fresh' => ['Maziwa, mayai na vyakula vibichi', true],
        'Medicines' => ['Dawa', false],
        'Health & First Aid' => ['Huduma ya kwanza na afya', false],
        'Building Materials' => ['Vifaa vya ujenzi', true],
        'Electrical & Plumbing' => ['Umeme na mabomba', true],
        'Meat & Poultry' => ['Nyama na kuku', true],
        'Water & Soft Drinks' => ['Maji, soda na juisi', true],
        'Beer & Spirits' => ['Bia, mvinyo na vinywaji vikali', false],
        'Timber & Boards' => ['Mbao na mbao za kupanga', false],
        'Services' => ['Huduma', false],
    ];

    /**
     * sku => [name, category, unit, cost, price, wholesale price|null, wholesale min qty|null,
     *         reorder level, opening qty, supplier code|null, flags, home branch(es)]
     * flags: e = tracks expiry, d = sold in decimals, s = service, o = not shown online, x = opening batch close to expiry
     * Opening stock is entered at the home branch; services list every branch that offers them.
     */
    public const PRODUCTS = [
        // Supermarket
        'SUK-1KG' => ['Sukari 1kg', 'Groceries', 'pc', 2700, 3200, 3000, 25, 60, 500, 'BAKH', '', 'MAIN'],
        'MCH-5KG' => ['Mchele Super 5kg', 'Groceries', 'bag', 12500, 14500, 13800, 10, 30, 180, 'BAKH', '', 'MAIN'],
        'UNG-2KG' => ['Unga wa Sembe 2kg', 'Groceries', 'pkt', 3300, 4000, 3700, 20, 50, 300, 'AZAM', '', 'MAIN'],
        'NGA-1KG' => ['Unga wa Ngano Azam 1kg', 'Groceries', 'pkt', 2300, 2800, 2600, 20, 40, 240, 'AZAM', '', 'MAIN'],
        'MAF-1L' => ['Mafuta ya Kupikia Korie 1L', 'Groceries', 'btl', 5400, 6500, 6100, 12, 40, 220, 'BAKH', '', 'MAIN'],
        'MAF-5L' => ['Mafuta ya Kupikia Korie 5L', 'Groceries', 'tin', 26000, 30500, 29000, 4, 10, 60, 'BAKH', '', 'MAIN'],
        'MAH-1KG' => ['Maharage 1kg', 'Groceries', 'kg', 2600, 3200, null, null, 40, 250, 'WAKULIMA', 'd', 'MAIN'],
        'CHA-100' => ['Chai Majani Simba 100g', 'Groceries', 'pkt', 1500, 2000, 1800, 12, 30, 150, 'AZAM', '', 'MAIN'],
        'CHU-1KG' => ['Chumvi 1kg', 'Groceries', 'pkt', 600, 900, 800, 20, 40, 250, 'AZAM', '', 'MAIN'],
        'TAM-500' => ['Tambi Spaghetti 500g', 'Groceries', 'pkt', 1600, 2200, 2000, 20, 30, 160, 'AZAM', '', 'MAIN'],
        'JUI-1L' => ['Juisi Azam Embe 1L', 'Groceries', 'btl', 2200, 3000, 2700, 12, 36, 200, 'AZAM', 'e', 'MAIN'],
        'SAB-MIL' => ['Sabuni ya Kufulia Jamaa', 'Household', 'pc', 1100, 1500, 1350, 24, 60, 400, 'KAPI', '', 'MAIN'],
        'OMO-1KG' => ['Omo Unga wa Kufulia 1kg', 'Household', 'pkt', 4800, 5800, 5400, 10, 30, 160, 'KAPI', '', 'MAIN'],
        'DET-750' => ['Sabuni ya Vyombo 750ml', 'Household', 'btl', 2900, 3800, 3500, 12, 24, 140, 'KAPI', '', 'MAIN'],
        'TIS-10' => ['Tishu Rolls (10)', 'Household', 'pkt', 6500, 8000, 7500, 6, 20, 100, 'KAPI', '', 'MAIN'],
        'MKA-BOX' => ['Kiberiti Box (10)', 'Household', 'box', 800, 1200, 1000, 10, 30, 150, 'KAPI', '', 'MAIN'],
        'MAZ-500' => ['Maziwa Fresh Tanga 500ml', 'Dairy & Fresh', 'pkt', 1100, 1500, null, null, 40, 200, 'TANGA', 'e', 'MAIN'],
        'MTI-500' => ['Mtindi Tanga 500ml', 'Dairy & Fresh', 'pkt', 1200, 1700, null, null, 30, 160, 'TANGA', 'e', 'MAIN'],
        'MAY-30' => ['Mayai Tray (30)', 'Dairy & Fresh', 'pc', 9500, 12000, 11000, 5, 20, 100, 'WAKULIMA', 'e', 'MAIN'],

        // Pharmacies (bought by Kariakoo, which feeds Mbezi by transfer)
        'PAR-500' => ['Paracetamol 500mg (strip ya 10)', 'Medicines', 'strip', 400, 1000, 800, 20, 60, 600, 'SHELYS', 'eo', 'PHARMA1'],
        'AMO-250' => ['Amoxicillin 250mg (strip ya 10)', 'Medicines', 'strip', 1500, 3000, 2500, 20, 40, 300, 'SHELYS', 'eo', 'PHARMA1'],
        'MET-400' => ['Metronidazole 400mg (strip ya 10)', 'Medicines', 'strip', 900, 2000, 1600, 20, 30, 240, 'SHELYS', 'eo', 'PHARMA1'],
        'IBU-400' => ['Ibuprofen 400mg (strip ya 10)', 'Medicines', 'strip', 700, 1500, 1200, 20, 30, 300, 'AFYA', 'eo', 'PHARMA1'],
        'CET-10' => ['Cetirizine 10mg (strip ya 10)', 'Medicines', 'strip', 600, 1500, 1200, 20, 30, 240, 'AFYA', 'eo', 'PHARMA1'],
        'CIP-500' => ['Ciprofloxacin 500mg (strip ya 10)', 'Medicines', 'strip', 1800, 3500, 3000, 10, 20, 160, 'SHELYS', 'eo', 'PHARMA1'],
        'ALU-24' => ['Artemether/Lumefantrine (dozi ya 24)', 'Medicines', 'box', 3500, 6000, 5200, 10, 20, 200, 'AFYA', 'eo', 'PHARMA1'],
        'SYR-COF' => ['Dawa ya Kikohozi 100ml', 'Medicines', 'btl', 2500, 4500, 3800, 10, 15, 120, 'AFYA', 'eox', 'PHARMA1'],
        'ORS-1' => ['ORS Sachet', 'Health & First Aid', 'pc', 300, 700, 560, 50, 40, 400, 'SHELYS', 'eo', 'PHARMA1'],
        'VIT-C' => ['Vitamin C 500mg (tin ya 100)', 'Health & First Aid', 'tin', 4500, 8000, 7000, 5, 10, 80, 'AFYA', 'eo', 'PHARMA1'],
        'PLA-10' => ['Plasta Box (10)', 'Health & First Aid', 'box', 1800, 2800, 2400, 10, 15, 120, 'SHELYS', 'o', 'PHARMA1'],
        'GLO-100' => ['Gloves za Mkono (box ya 100)', 'Health & First Aid', 'box', 9000, 14000, 12500, 5, 10, 60, 'AFYA', 'o', 'PHARMA1'],
        'SAN-500' => ['Sanitizer ya Mikono 500ml', 'Health & First Aid', 'btl', 3000, 5000, 4300, 10, 15, 120, 'AFYA', 'eo', 'PHARMA1'],
        'MRDT-1' => ['Kipimo cha Malaria (mRDT)', 'Health & First Aid', 'pc', 900, 2500, 2000, 25, 30, 300, 'AFYA', 'eo', 'PHARMA1'],

        // Hardware
        'CEM-50' => ['Saruji Twiga 50kg', 'Building Materials', 'bag', 16500, 19000, 18200, 20, 40, 400, 'TWIGA', '', 'HARDWARE'],
        'BAT-28' => ['Bati Geji 28 (3m)', 'Building Materials', 'pc', 18000, 23000, 21500, 10, 30, 250, 'ALAF', '', 'HARDWARE'],
        'NON-12' => ['Nondo 12mm (urefu 12m)', 'Building Materials', 'pc', 19500, 23500, 22500, 20, 40, 300, 'ALAF', '', 'HARDWARE'],
        'MSU-3' => ['Misumari 3" (kg)', 'Building Materials', 'kg', 3500, 5000, 4500, 20, 40, 300, 'ALAF', 'd', 'HARDWARE'],
        'TIL-40' => ['Tiles 40x40 (box)', 'Building Materials', 'box', 22000, 28000, 26000, 10, 20, 150, 'TWIGA', '', 'HARDWARE'],
        'RAN-4L' => ['Rangi Nyeupe 4L', 'Building Materials', 'tin', 32000, 40000, 37500, 5, 12, 60, 'ALAF', '', 'HARDWARE'],
        'WAY-2.5' => ['Waya wa Umeme 2.5mm (m)', 'Electrical & Plumbing', 'm', 1200, 1800, 1600, 50, 100, 1500, 'KIBO', 'd', 'HARDWARE'],
        'SWI-1' => ['Swichi ya Umeme (single)', 'Electrical & Plumbing', 'pc', 2500, 4000, 3500, 20, 30, 200, 'KIBO', '', 'HARDWARE'],
        'BUL-LED' => ['Bulb ya LED 12W', 'Electrical & Plumbing', 'pc', 2800, 4500, 4000, 20, 40, 300, 'KIBO', '', 'HARDWARE'],
        'PVC-05' => ['Bomba la PVC 1/2" (6m)', 'Electrical & Plumbing', 'pc', 6500, 9000, 8200, 10, 20, 200, 'KIBO', '', 'HARDWARE'],

        // Butchery (bought twice a week; meat is sold by the kilo)
        'NYA-STK' => ['Nyama ya Ng\'ombe Steki (kg)', 'Meat & Poultry', 'kg', 10500, 14000, 13000, 10, 15, 90, 'MACHINJIO', 'd', 'BUTCHER'],
        'NYA-MCH' => ['Nyama ya Ng\'ombe Mchanganyiko (kg)', 'Meat & Poultry', 'kg', 8500, 11000, 10200, 10, 20, 140, 'MACHINJIO', 'd', 'BUTCHER'],
        'NYA-MBU' => ['Nyama ya Mbuzi (kg)', 'Meat & Poultry', 'kg', 11000, 15000, 14000, 10, 10, 70, 'MACHINJIO', 'd', 'BUTCHER'],
        'MAI-KG' => ['Maini ya Ng\'ombe (kg)', 'Meat & Poultry', 'kg', 9000, 12000, null, null, 5, 30, 'MACHINJIO', 'd', 'BUTCHER'],
        'MIF-KG' => ['Mifupa ya Supu (kg)', 'Meat & Poultry', 'kg', 3000, 5000, null, null, 10, 50, 'MACHINJIO', 'd', 'BUTCHER'],
        'KUK-1' => ['Kuku wa Kisasa (mzima)', 'Meat & Poultry', 'pc', 8500, 11000, 10200, 10, 25, 160, 'KUKU', '', 'BUTCHER'],
        'SOS-500' => ['Sausage za Ng\'ombe 500g', 'Meat & Poultry', 'pkt', 5500, 7500, 7000, 10, 10, 60, 'KUKU', 'e', 'BUTCHER'],

        // Drinks depot (also feeds the supermarket by transfer)
        'MAJ-1.5' => ['Maji Kilimanjaro 1.5L', 'Water & Soft Drinks', 'btl', 650, 1000, 850, 12, 96, 1500, 'BONITE', '', 'DRINKS'],
        'MAJ-500' => ['Maji Uhai 500ml', 'Water & Soft Drinks', 'btl', 350, 500, 420, 24, 120, 2400, 'BONITE', '', 'DRINKS'],
        'SOD-500' => ['Soda Pepsi 500ml', 'Water & Soft Drinks', 'btl', 700, 1000, 850, 24, 96, 1800, 'SBC', '', 'DRINKS'],
        'SOD-MIR' => ['Soda Mirinda 350ml', 'Water & Soft Drinks', 'btl', 550, 800, 680, 24, 96, 1200, 'SBC', '', 'DRINKS'],
        'ENE-250' => ['Energy Drink Mo 250ml', 'Water & Soft Drinks', 'btl', 900, 1500, 1300, 24, 48, 720, 'SBC', '', 'DRINKS'],
        'BIA-SAF' => ['Bia Safari Lager 500ml', 'Beer & Spirits', 'btl', 2000, 2800, 2450, 25, 100, 1500, 'TBL', 'o', 'DRINKS'],
        'BIA-KIL' => ['Bia Kilimanjaro 500ml', 'Beer & Spirits', 'btl', 1950, 2700, 2400, 25, 100, 1500, 'TBL', 'o', 'DRINKS'],
        'KON-750' => ['Konyagi 750ml', 'Beer & Spirits', 'btl', 11000, 14000, 13000, 12, 24, 240, 'TBL', 'o', 'DRINKS'],
        'WIN-750' => ['Mvinyo wa Dodoma 750ml', 'Beer & Spirits', 'btl', 9500, 13000, 12000, 12, 12, 120, 'TBL', 'o', 'DRINKS'],

        // Timber yard
        'MBA-2X2' => ['Mbao 2x2 (futi 12)', 'Timber & Boards', 'pc', 3500, 5000, 4500, 50, 100, 1200, 'MAFINGA', '', 'TIMBER'],
        'MBA-2X4' => ['Mbao 2x4 (futi 12)', 'Timber & Boards', 'pc', 7000, 9500, 8800, 50, 80, 900, 'MAFINGA', '', 'TIMBER'],
        'MBA-2X6' => ['Mbao 2x6 (futi 12)', 'Timber & Boards', 'pc', 10500, 14000, 13000, 30, 60, 600, 'MAFINGA', '', 'TIMBER'],
        'MBA-1X8' => ['Bodi 1x8 (futi 12)', 'Timber & Boards', 'pc', 8500, 12000, 11000, 30, 50, 500, 'SAOHILL', '', 'TIMBER'],
        'MBA-1X12' => ['Bodi 1x12 Mninga (futi 12)', 'Timber & Boards', 'pc', 28000, 38000, 35000, 10, 20, 150, 'SAOHILL', '', 'TIMBER'],
        'PLY-12' => ['Plywood 12mm (4x8)', 'Timber & Boards', 'pc', 32000, 42000, 39000, 10, 20, 200, 'SAOHILL', '', 'TIMBER'],
        'NGU-POL' => ['Nguzo ya Mti (mita 6)', 'Timber & Boards', 'pc', 12000, 17000, 15500, 20, 30, 300, 'MAFINGA', '', 'TIMBER'],

        // Services (no stock)
        'SVC-DEL' => ['Huduma ya Kusafirisha (Delivery)', 'Services', 'pc', 0, 3000, null, null, 0, 0, null, 'so', 'MAIN,HARDWARE,DRINKS,TIMBER'],
        'SVC-KAT' => ['Kukata Bati / Nondo', 'Services', 'pc', 0, 2000, null, null, 0, 0, null, 'so', 'HARDWARE'],
        'SVC-SAGA' => ['Kusaga Nyama (kwa kg)', 'Services', 'kg', 0, 1000, null, null, 0, 0, null, 'sod', 'BUTCHER'],
        'SVC-RAN' => ['Kuranda na Kukata Mbao (kipande)', 'Services', 'pc', 0, 1500, null, null, 0, 0, null, 'so', 'TIMBER'],
        'SVC-BP' => ['Kupima Presha (BP)', 'Services', 'pc', 0, 2000, null, null, 0, 0, null, 'so', 'PHARMA1,PHARMA2'],
        'SVC-MAL' => ['Kupima Malaria', 'Services', 'pc', 0, 3000, null, null, 0, 0, null, 'so', 'PHARMA1,PHARMA2'],
    ];

    /** Bigger packs sold with their own price: sku => [[pack name, unit, factor, retail price, wholesale price|null]] */
    public const PACKS = [
        'SUK-1KG' => [['Mfuko wa 25kg', 'bag', 25, 76000, 73000]],
        'SAB-MIL' => [['Katoni ya 24', 'ctn', 24, 33000, 31000]],
        'MAZ-500' => [['Katoni ya 20', 'ctn', 20, 28000, null]],
        'PAR-500' => [['Box ya strip 10', 'box', 10, 9000, 8000]],
        'AMO-250' => [['Box ya strip 10', 'box', 10, 27000, 24500]],
        'ORS-1' => [['Box ya 50', 'box', 50, 30000, 28000]],
        'MAJ-1.5' => [['Katoni ya 12', 'ctn', 12, 11000, 9800]],
        'MAJ-500' => [['Katoni ya 24', 'ctn', 24, 11000, 9600]],
        'SOD-500' => [['Kreti ya 24', 'crt', 24, 22000, 19500]],
        'SOD-MIR' => [['Kreti ya 24', 'crt', 24, 18000, 15800]],
        'ENE-250' => [['Katoni ya 24', 'ctn', 24, 33000, 30000]],
        'BIA-SAF' => [['Kreti ya 25', 'crt', 25, 65000, 60000]],
        'BIA-KIL' => [['Kreti ya 25', 'crt', 25, 63000, 58500]],
        'KON-750' => [['Katoni ya 12', 'ctn', 12, 160000, 150000]],
        'MSU-3' => [['Box ya kg 25', 'box', 25, 115000, 108000]],
        'WAY-2.5' => [['Roll ya mita 100', 'roll', 100, 165000, 155000]],
    ];

    /** code => [name, contact, phone, email, TIN, payment terms days] */
    public const SUPPLIERS = [
        'BAKH' => ['Bakhresa Wholesale Ltd', 'Mussa Bakari', '255713200001', 'orders@bakhresa-demo.co.tz', '100-200-301', 30],
        'AZAM' => ['Azam Distributors', 'Lucy Mrema', '255713200002', 'sales@azam-demo.co.tz', '100-200-302', 14],
        'KAPI' => ['Kapi Household Supplies', 'Halima Omari', '255713200003', null, '100-200-303', 21],
        'TANGA' => ['Tanga Fresh Ltd', 'Peter Shirima', '255713200004', 'dispatch@tangafresh-demo.co.tz', '100-200-304', 0],
        'WAKULIMA' => ['Wakulima Morogoro Coop', 'Rashidi Mfaume', '255713200005', null, null, 0],
        'SHELYS' => ['Shelys Pharmaceuticals', 'Dr. Salma Hussein', '255713200006', 'orders@shelys-demo.co.tz', '100-200-306', 30],
        'AFYA' => ['Afya Medical Distributors', 'Joseph Kweka', '255713200007', 'sales@afyamed-demo.co.tz', '100-200-307', 21],
        'TWIGA' => ['Twiga Cement Agents', 'Agnes Ndege', '255713200008', 'agents@twiga-demo.co.tz', '100-200-308', 30],
        'ALAF' => ['ALAF Steel Dealers', 'Kelvin Mbwambo', '255713200009', null, '100-200-309', 30],
        'KIBO' => ['Kibo Electrical & Plumbing', 'Fredrick Swai', '255713200010', 'kibo@kiboelectric-demo.co.tz', '100-200-310', 14],
        'MACHINJIO' => ['Machinjio ya Vingunguti (Wauzaji wa Nyama)', 'Hassani Mtoro', '255713200011', null, null, 0],
        'KUKU' => ['Kibaha Poultry Farm', 'Mary Kapinga', '255713200012', null, '100-200-312', 7],
        'BONITE' => ['Bonite Bottlers Agents', 'Edward Lema', '255713200013', null, '100-200-313', 14],
        'SBC' => ['SBC Tanzania (Pepsi)', 'John Kessy', '255713200014', null, '100-200-314', 7],
        'TBL' => ['TBL Distributor Mwenge', 'Selemani Kipara', '255713200015', 'mwenge@tbl-demo.co.tz', '100-200-315', 14],
        'MAFINGA' => ['Mafinga Sawmills', 'Obed Mgaya', '255713200016', null, '100-200-316', 30],
        'SAOHILL' => ['Sao Hill Timber Agents', 'Restituta Mlowe', '255713200017', 'sales@saohill-demo.co.tz', '100-200-317', 30],
    ];

    /**
     * key => [name, phone, type, credit limit (0 = no credit), home branch, email, address, tin, vrn, savings,
     *         branches where they buy]
     */
    public const CUSTOMERS = [
        'mama.ntilie' => ['Mama Ntilie Restaurant (Halima)', '255715300001', 'wholesale', 800000, 'MAIN', null, 'Kariakoo Soko Kuu', null, null, true, ['MAIN', 'BUTCHER', 'DRINKS']],
        'hotel.sea' => ['Sea View Hotel Ltd', '255715300002', 'institution', 4000000, 'BUTCHER', 'accounts@seaview-demo.co.tz', 'Kigamboni, Dar es Salaam', '123-456-789', '40-012345-A', false, ['BUTCHER', 'DRINKS', 'MAIN']],
        'shule.amani' => ['Shule ya Msingi Amani', '255715300003', 'institution', 2000000, 'MAIN', 'bursar@amani-demo.ac.tz', 'Buguruni, Dar es Salaam', '123-456-790', null, false, ['MAIN', 'BUTCHER']],
        'fundi.jumanne' => ['Jumanne Construction Ltd', '255715300004', 'wholesale', 5000000, 'HARDWARE', 'jumanne.build@demo.co.tz', 'Mbezi Beach', '123-456-791', '40-012346-B', false, ['HARDWARE', 'TIMBER']],
        'samani.bora' => ['Samani Bora Furniture Workshop', '255715300005', 'wholesale', 3000000, 'TIMBER', 'samanibora@demo.co.tz', 'Kibaha Picha ya Ndege', '123-456-792', null, false, ['TIMBER', 'HARDWARE']],
        'zahanati.uzima' => ['Zahanati ya Uzima', '255715300006', 'institution', 1500000, 'PHARMA1', 'stores@uzima-demo.or.tz', 'Ilala Sharifu Shamba', '123-456-793', null, false, ['PHARMA1', 'PHARMA2']],
        'kliniki.mbezi' => ['Mbezi Health Clinic', '255715300007', 'institution', 1000000, 'PHARMA2', 'manunuzi@mbeziclinic-demo.co.tz', 'Mbezi Luis', '123-456-794', null, false, ['PHARMA2']],
        'baa.kilele' => ['Kilele Bar & Grill', '255715300008', 'wholesale', 2000000, 'DRINKS', 'kilele.bar@demo.co.tz', 'Sinza Mori', null, null, false, ['DRINKS', 'BUTCHER']],
        'duka.rehema' => ['Duka la Rehema (Retailer)', '255715300009', 'wholesale', 1000000, 'DRINKS', null, 'Makumbusho', null, null, false, ['DRINKS', 'MAIN']],
        'kanisa' => ['Kanisa la KKKT Buguruni', '255715300010', 'institution', 500000, 'MAIN', null, 'Buguruni', null, null, false, ['MAIN', 'DRINKS']],
        'juma' => ['Juma Hassan', '255712300011', 'retail', 200000, 'MAIN', null, 'Buguruni Malapa', null, null, true, ['MAIN', 'BUTCHER']],
        'mariam' => ['Mariam Shabani', '255712300012', 'retail', 150000, 'MAIN', 'mariam.s@demo.co.tz', 'Ilala Bungoni', null, null, true, ['MAIN', 'PHARMA1']],
        'peter' => ['Peter Mwita', '255712300013', 'retail', 100000, 'PHARMA2', null, 'Mbezi Msakuzi', null, null, false, ['PHARMA2', 'HARDWARE']],
        'aisha' => ['Aisha Kombo', '255712300014', 'retail', 0, 'BUTCHER', null, 'Vingunguti', null, null, true, ['BUTCHER', 'MAIN']],
        'godfrey' => ['Godfrey Lema (Fundi Seremala)', '255712300015', 'retail', 250000, 'TIMBER', null, 'Kibaha Mkoani', null, null, false, ['TIMBER', 'HARDWARE']],
        'neema.k' => ['Neema Kileo', '255712300016', 'retail', 0, 'PHARMA1', 'neema.kileo@demo.co.tz', 'Gerezani', null, null, true, ['PHARMA1', 'MAIN']],
        'salim' => ['Salim Abdallah', '255712300017', 'retail', 80000, 'DRINKS', null, 'Mwenge Kijitonyama', null, null, false, ['DRINKS', 'MAIN']],
        'upendo' => ['Upendo Mbwana', '255712300018', 'retail', 0, 'MAIN', null, 'Tandale', null, null, false, ['MAIN']],
        'issa' => ['Issa Mohamed', '255712300019', 'retail', 0, 'HARDWARE', null, 'Tegeta Kibaoni', null, null, false, ['HARDWARE']],
        'lucy' => ['Lucy Mrosso', '255712300020', 'retail', 60000, 'PHARMA2', null, 'Kimara Stop Over', null, null, false, ['PHARMA2']],
        'hamza' => ['Hamza Juma (Fundi Ujenzi)', '255712300021', 'retail', 400000, 'HARDWARE', null, 'Bunju B', null, null, false, ['HARDWARE', 'TIMBER']],
    ];

    /** Online-shop customers who register themselves: [name, phone, email, address, lat, lng] */
    public const SHOP_CUSTOMERS = [
        ['Rose Mwakalinga', '0716400001', 'rose.m@demo.co.tz', 'Upanga, Mtaa wa Mindu', -6.8103, 39.2862],
        ['Kelvin Swai', '0716400002', null, 'Sinza Kumekucha', -6.7838, 39.2264],
        ['Faraji Omari', '0716400003', 'faraji@demo.co.tz', 'Mbezi Beach, Africana', -6.7271, 39.2223],
        ['Doreen Massawe', '0716400004', null, 'Kinondoni Studio', -6.7808, 39.2650],
        ['Ibrahim Nassor', '0716400005', 'ibra.n@demo.co.tz', 'Tegeta Wazo', -6.6700, 39.2150],
    ];

    /** Uwakala lines at the supermarket: [name, provider, agent number, starting float] */
    public const AGENCY_LINES = [
        ['M-Pesa Wakala', 'mpesa', '345678', 1500000],
        ['Mixx by Yas Wakala', 'mixx', '556677', 900000],
        ['Airtel Money Wakala', 'airtel', '778899', 700000],
        ['NMB Wakala', 'nmb', 'NMB-10234', 2500000],
        ['CRDB Wakala', 'crdb', 'CRDB-88231', 2000000],
    ];

    /** @return array<int, string> the home branches of a product */
    public static function homes(string $sku): array
    {
        return explode(',', self::PRODUCTS[$sku][11]);
    }
}
