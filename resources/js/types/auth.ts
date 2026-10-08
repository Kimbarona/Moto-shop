export type Role =
    'super_admin' | 'admin' | 'cashier' | 'mechanic' | 'inventory_clerk';

export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    role: Role;
    role_label: string;
    must_change_password: boolean;
    [key: string]: unknown;
};

/**
 * Capability flags computed on the server (Gates). Cost/profit data is never sent
 * to roles without viewCost; these flags only drive what the UI shows.
 */
export type Can = {
    accessAdmin: boolean;
    viewCost: boolean;
    usePos: boolean;
    useServiceBoard: boolean;
    takePayment: boolean;
    needsManagerPin: boolean;
};

export type Auth = {
    user: User;
    can: Can;
};
