<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('shop.shop_name', 'Moto Shop');
        $this->migrator->add('shop.logo', null);
        $this->migrator->add('shop.address', null);
        $this->migrator->add('shop.mobile', null);
        $this->migrator->add('shop.email', null);
        $this->migrator->add('shop.tin', null);
        $this->migrator->add('shop.business_hours', 'Mon–Sat 8:00 AM – 6:00 PM');

        $this->migrator->add('slip.document_title', 'Order Slip');
        $this->migrator->add('slip.header_text', null);
        $this->migrator->add('slip.footer_text', 'This is not an official receipt.');
        $this->migrator->add('slip.show_logo', true);
        $this->migrator->add('slip.paper_size', '80mm');
        $this->migrator->add('slip.copies', 1);

        $this->migrator->add('tax.vat_registered', false);
        $this->migrator->add('tax.vat_rate', 12.0);
        $this->migrator->add('tax.prices_vat_inclusive', true);

        $this->migrator->add('pos.cashier_max_discount_percent', 5.0);
        $this->migrator->add('pos.require_reference_for_non_cash', true);
        $this->migrator->add('pos.default_payment_method_id', null);
        $this->migrator->add('pos.allow_price_override', true);
        $this->migrator->add('pos.allow_walk_in_without_customer', true);

        $this->migrator->add('inventory.allow_negative_stock', false);
        $this->migrator->add('inventory.default_reorder_level', 2.0);
        $this->migrator->add('inventory.dead_stock_days', 90);

        $this->migrator->add('service.default_commission_percent', 10.0);
        $this->migrator->add('service.pms_interval_km', 1500);
        $this->migrator->add('service.pms_interval_months', 2);
        $this->migrator->add('service.show_job_ticket_on_create', true);

        $this->migrator->add('security.session_timeout_minutes', 120);
        $this->migrator->add('security.password_min_length', 8);
        $this->migrator->add('security.max_pin_attempts', 5);

        $this->migrator->add('appearance.pos_theme', 'dark');

        $this->migrator->add('backup.last_backup_at', null);
    }
};
