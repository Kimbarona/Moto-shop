export type DiscountType = 'amount' | 'percent';
export type LineType = 'part' | 'labor';
export type OrderStatus = 'pending' | 'paid' | 'voided';
export type ServiceStatus = 'waiting' | 'in_progress' | 'done';

export type Category = { id: number; name: string; icon: string | null };

export type Make = {
    id: number;
    name: string;
    models: { id: number; name: string }[];
};

export type PaymentMethod = {
    id: number;
    name: string;
    type: 'cash' | 'ewallet' | 'bank';
    requires_reference: boolean;
};

export type LaborService = {
    id: number;
    code: string;
    name: string;
    labor_fee: number;
    est_minutes: number | null;
};

export type ServicePackage = {
    id: number;
    name: string;
    description: string | null;
    package_price: number | null;
    regular_price: number;
    items: { name: string; qty: number; type: LineType }[];
};

export type MechanicOption = {
    id: number;
    name: string;
    user_id: number | null;
};

export type PosSettings = {
    cashierMaxDiscountPercent: number;
    allowPriceOverride: boolean;
    defaultPaymentMethodId: number | null;
    allowWalkInWithoutCustomer: boolean;
    vatRegistered: boolean;
    vatRate: number;
    pricesVatInclusive: boolean;
    allowNegativeStock: boolean;
    showJobTicketOnCreate: boolean;
};

export type Catalog = {
    categories: Category[];
    makes: Make[];
    paymentMethods: PaymentMethod[];
    services: LaborService[];
    packages: ServicePackage[];
    mechanics: MechanicOption[];
    settings: PosSettings;
    openOrdersCount: number;
};

/** Product card — never contains cost. */
export type Product = {
    id: number;
    sku: string;
    name: string;
    part_number: string | null;
    brand: string | null;
    category_id: number | null;
    category: string | null;
    unit: string | null;
    allows_decimal: boolean;
    srp: number;
    on_hand: number;
    reserved: number;
    available: number;
    reorder_level: number;
    low_stock: boolean;
    bin_location: string | null;
    is_universal: boolean;
    image: string | null;
    fits?: { id: number; name: string; notes: string | null }[];
};

export type Motorcycle = {
    id: number;
    plate_no: string | null;
    model: string | null;
    model_id?: number | null;
    color: string | null;
    last_odometer?: number | null;
};

export type Customer = {
    id: number;
    name: string;
    mobile: string | null;
    motorcycles: Motorcycle[];
};

export type OrderItem = {
    id: number;
    line_type: LineType;
    product_id: number | null;
    service_id: number | null;
    package: string | null;
    description: string;
    sku: string | null;
    unit: string | null;
    allows_decimal: boolean;
    qty: number;
    qty_returned: number;
    original_price: number;
    unit_price: number;
    discount_type: DiscountType | null;
    discount_value: number;
    discount: number;
    line_total: number;
    discount_share: number;
    net: number;
    max_qty: number | null;
    mechanic_id: number | null;
    mechanic: string | null;
    commission?: number;
    unit_cost?: number | null;
};

export type Payment = {
    id: number;
    method: string | null;
    method_type: string | null;
    amount: number;
    amount_tendered: number | null;
    change_due: number;
    reference_no: string | null;
    paid_at: string | null;
    voided: boolean;
};

export type Order = {
    id: number;
    order_no: string;
    slip_no: string | null;
    type: 'walk_in' | 'service';
    status: OrderStatus;
    status_label: string;
    service_status: ServiceStatus | null;
    refund_status: 'none' | 'partial' | 'full';
    customer: { id: number; name: string; mobile: string | null } | null;
    motorcycle: (Motorcycle & { model_id: number | null }) | null;
    odometer_in: number | null;
    complaint: string | null;
    notes: string | null;
    mechanics: { id: number; name: string }[];
    items: OrderItem[];
    subtotal: number;
    discount_type: DiscountType | null;
    discount_value: number;
    discount_total: number;
    vatable: number;
    vat_amount: number;
    total: number;
    return_credit: number;
    amount_due: number;
    profit?: number;
    payments: Payment[];
    cashier: string | null;
    created_by: string | null;
    voided_by: string | null;
    void_reason: string | null;
    created_at: string | null;
    done_at: string | null;
    paid_at: string | null;
    voided_at: string | null;
    can: {
        editItems: boolean;
        discount: boolean;
        pay: boolean;
        void: boolean;
        serviceStatus: boolean;
    };
};

/** Compact card for Open Orders / Service Board. */
export type OrderCard = {
    id: number;
    order_no: string;
    type: 'walk_in' | 'service';
    service_status: ServiceStatus | null;
    customer: string | null;
    plate_no: string | null;
    model: string | null;
    complaint: string | null;
    mechanics: string[];
    items_count: number;
    total: number;
    created_at: string | null;
    done_at: string | null;
};
