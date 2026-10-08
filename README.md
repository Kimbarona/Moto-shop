# Moto Shop Management System

Parts inventory, POS and service (job order) management for a single-branch motorcycle
parts & maintenance shop in the Philippines.

- **Front of house** (React + Inertia): POS, Open Orders, Service Board, Returns, Stock lookup, My Summary
- **Back-office** at `/admin` (Filament): masterlist, stock, sales records, dashboard, 23 reports, settings, audit log
- One Laravel 12 app, one MySQL database, one login page for everyone

Payment methods (Cash, GCash, Maya, Bank Transfer) are **labels for tracking only**. There
is no payment gateway. The printed slip is an **Order Slip, not an official receipt**.

---

## Setup (3 commands)

Requires PHP 8.3+, Composer, Node 20+, and MySQL 8 (Laragon works). The database
`moto_shop_db` must already exist (utf8mb4_unicode_ci). Setup **never drops or recreates it**.
It only runs migrations inside it.

```bash
composer run setup   # install, .env, key, check DB, migrate, seed, test DB, build assets
composer run dev     # web server + queue worker + Vite
# open http://localhost:8001
```

`composer run setup` stops at `php artisan db:show` if it can't reach MySQL. Check
`DB_*` in `.env`: the default is `root` with an empty password.

The app runs on **http://localhost:8001**, its own host and port, so it never shares
cookies with another local app on `127.0.0.1:8000`.

### Tests

```bash
composer test
```

Tests run **only** against `moto_shop_db_test`. `phpunit.xml` forces it, every test checks
the name ends in `_test`, and `php artisan app:setup-testing` creates it. Your shop data in
`moto_shop_db` is never touched by tests.

### Default logins (dev seed)

All passwords are `password`. The manager PIN for the admin accounts is `1234`.

| Email | Role | Lands on |
|---|---|---|
| superadmin@example.com | Super Admin | /admin |
| admin@example.com | Admin / Manager | /admin |
| cashier@example.com | Cashier | /pos |
| mechanic@example.com | Mechanic | /service-board |
| clerk@example.com | Inventory Clerk | /admin |

**Before going live:** change every password, set each manager's own PIN (user menu →
*My manager PIN*), and replace the demo products in the masterlist.

---

## Roles at a glance

| | Super Admin | Admin | Cashier | Mechanic | Clerk |
|---|:-:|:-:|:-:|:-:|:-:|
| POS, take payment | ✅ | ✅ | ✅ | | |
| Service Board | ✅ | ✅ | ✅ | ✅ (own jobs) | |
| Stock in / adjust / count | ✅ | ✅ | | | ✅ |
| See cost & profit | ✅ | ✅ | | | |
| Sales reports | ✅ | ✅ | | | |
| Inventory reports | ✅ | ✅ | | | ✅ (no cost columns) |
| Audit log | ✅ | ✅ | | | |
| App Settings, Backup | ✅ edit | view only | | | |
| Manage users | ✅ | ✅ (not super admins) | | | |

Cost and profit are removed from the data sent to Cashiers and Mechanics, not just hidden
on screen. The last active Super Admin can't be deactivated or demoted.

**Manager PIN.** When a cashier goes past their limits, a manager types their PIN on the
cashier's screen to approve it. This applies to a discount above the cashier limit, a price
below SRP by more than that limit, a void, or a return. Every approval is logged with who
approved what, and when. Too many wrong PINs lock the PIN for a while.

---

## How orders work

### Pending → Paid → (Void)

Every sale or job is an **order** (`ORD-000123`).

1. **Pending**: still open. A walk-in cart can be *Saved as Pending*. A service order stays
   Pending while the bike is in the shop (Waiting → In progress → Done on the Service Board).
2. **Paid**: the cashier takes payment. In one database transaction:
   - stock is deducted once,
   - each part's cost is frozen on the line (for profit reports),
   - the next **slip number** is assigned (`OS-000046`),
   - the mechanic's labor commission is calculated.
3. **Voided**: needs a reason, and a manager PIN for cashiers.
   - A Pending order can be voided at any time.
   - A Paid order can be voided **on the same day only**. Stock comes back and the payment
     and commission are reversed.
   - From the next day on, use a **Return** instead.

Paid orders are locked and can't be edited. Nothing is ever deleted: orders are voided,
returned or adjusted, so the history always adds up.

### Reservation

Parts on a **Pending** order are *reserved*, so two customers can't be promised the same
last unit.

- **On hand**: physically in the shop.
- **Reserved**: on Pending orders (calculated live, never stored).
- **Available** = On hand − Reserved. This is what the POS lets you sell.

Adding a part to an order fails if Available is short. Removing the line or voiding the
order releases it straight away. The stock itself only moves when the order is **paid**.
(App Settings → Inventory → *Allow negative stock* turns this check off. Leave it off.)

### Slip numbers

Order, slip and return numbers come from a row-locked `sequences` table. They are
sequential, never reused and never reset: a failed payment doesn't burn a number, and a
voided sale keeps its number. In App Settings you can change the prefixes and move the next
slip number **forward**, never back. This keeps a later move to BIR-accredited receipts easy.

---

## How returns work

Returns are made against a **Paid** order: *Returns* in the top bar, or *Return* on the
order. Cashiers need a manager PIN.

1. Find the slip and pick the lines and quantities. You can't return more than was sold,
   minus earlier returns.
2. Choose the **reason**: Wrong part, Defective, Not needed, Other.
3. For each **part**, choose its **disposition**:
   - **Back to stock**: the part is fine. It goes back on the shelf at its original cost
     and is sellable again.
   - **Defective**: it goes to **Inventory → Defective Stock**, never back on the shelf. Each
     one is later marked *Returned to supplier* or *Written off*.
   - **Labor** lines are refund only. The mechanic's commission for that amount is reversed.
4. Choose the **resolution**:
   - **Refund**: money goes back by Cash / GCash / Maya / Bank (reference no. when needed).
   - **Exchange**: the amount becomes credit, and the POS opens a new sale with the credit
     applied. The customer pays only the difference; if the new sale is cheaper, the balance
     is given back. If the customer comes back later, open the return and click
     *Use credit on a new sale*.

The refund is exactly what the customer paid for those items, after discounts and with VAT.
Returning a line in several goes never adds up to more than it was sold for. The order then
shows **Partially Refunded** or **Refunded**, and a return slip (`RET-000001`) is printed.

In reports, refunds count on the day of the return. A part returned *back to stock*
recovers its cost. A *defective* part stays a cost of the business.

---

## Settings

**Back-office → System → App Settings.** Only a Super Admin can change them; Admins can view.
Every change is written to the audit log with before and after values.

| Tab | What's there |
|---|---|
| Shop Profile | Name, logo (shown on the login page, sidebar and slips), address, contacts, TIN, hours |
| Receipt / Slip | Title ("Order Slip"), header/footer text, show logo, paper size (58mm / 80mm / A4 / half-letter), copies |
| Tax | VAT registered, VAT rate, prices VAT-inclusive or VAT added on top |
| POS | Cashier max discount % without a PIN, require reference no. for e-wallet/bank, default payment method, price override, walk-in without customer |
| Inventory | Allow negative stock, default reorder level, dead-stock days, SKU prefix |
| Service | Default labor commission %, PMS interval, show job ticket on create |
| Order Numbering | Prefixes for ORD / slip / RET / SKU, next slip number (forward only) |
| Security | Session timeout, password minimum length, max PIN attempts |
| Appearance | Default POS theme (dark / light / system). Each user can still toggle it. |
| Backup | Backup now, last backup date, stored backups |

Everyone can change their own password and theme from the user menu → *Settings*.

---

## Audit log

**Back-office → System → Audit Log** (Admins and Super Admins). It is read-only and lists
every create, update, payment, void, return, manager approval, settings change,
price/cost change, stock document and reprint. Each entry shows the user, the time, the IP
address and the before → after values. Filter by user, area, event, record ID or dates.
Entries can't be edited or deleted from the app.

---

## Backups

**Back up at least once a day, and keep a copy off this computer** (USB drive or a cloud folder).

### Backup button

App Settings → **Backup** → **Backup now** (Super Admin). It dumps the whole database with
`mysqldump`, zips it, downloads the zip to your browser, and keeps a copy in
`storage/app/private/moto-shop/`. The tab shows the last backup date, and turns red when
there has been no backup in 24 hours. Older copies are pruned automatically: all are kept for
7 days, then one per day, week and month.

`mysqldump` is found through `DB_DUMP_BINARY_PATH` in `.env`. For Laragon that is:

```
DB_DUMP_BINARY_PATH="C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin"
```

If the button says *Backup failed*, check that path first.

### From the command line

```bash
mysqldump -u root moto_shop_db > backup.sql
# or the same zip the button makes (plus uploaded files like the logo):
php artisan backup:run
```

### Restoring

Restoring **replaces everything** in the database with the backup. Do it with the app
stopped, and make a fresh backup first in case you need to undo.

```bash
# from the zip: extract db-dumps/mysql-moto_shop_db.sql, then
mysql -u root moto_shop_db < mysql-moto_shop_db.sql
```

---

## Reports

**Back-office → Reports.** There are 23 reports in seven groups: Sales, Profit, Inventory,
Service, Returns, Audit and Expenses. Each has date presets, an **Excel** export (real
numbers, one sheet per table) and a **PDF** export (A4 landscape). Exports are audit-logged.

The numbers mean the same thing everywhere (dashboard, reports, exports):

- **Sales**: paid orders by payment date. Voided orders are excluded.
- **Refunds**: returns by return date, shown as negative lines.
- **Net sales** = Sales − Refunds (VAT included).
- **Gross profit** = Net sales without VAT − cost of parts sold, using the cost frozen at
  payment. Parts returned *back to stock* recover their cost; *defective* ones don't.
- **Net income** (P&L) = Gross profit − Expenses.

---

## For developers

- **Business logic** lives in `app/Services`: Stock, Orders (Order, Payment, Void, Return),
  Approvals, Numbering, Reports, Catalog, Expenses, Backup. Controllers and Filament
  pages stay thin.
- **Every stock or money change** runs in a DB transaction with `lockForUpdate()` on the
  product rows involved.
- **Money** is `decimal(12,2)`, quantities `decimal(10,2)` and costs `decimal(12,4)`. Display
  formats are `₱1,234.00` and `M d, Y h:i A`, in the Asia/Manila timezone.
- **Front of house** lives in `resources/js` (pages, components/pos). The **back-office**
  lives in `app/Filament` with its theme in `resources/css/filament/admin/theme.css`. The
  two use separate Vite entry points with the same "Pit Lane" palette and Barlow fonts.
- **Out of scope for now**, with the structure kept ready: barcode scanner, cashier shifts
  and X/Z reading, split payment UI, purchase orders and payables, customer credit (utang),
  PMS SMS reminders, loyalty, multi-branch, BIR-accredited receipts.

### Version pins

The official React starter kit's `main` branch now targets Laravel 13 / Inertia 3. This
project is based on starter-kit commit `8f79a71dd98d81eb5c2f409f3426331cc0d30ded` (the last
Laravel 12 / Inertia 2 version). It pins `laravel/framework:^12.69` and
`filament/filament:^4.15` so `composer update` can't jump a major version.
