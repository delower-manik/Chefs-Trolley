# Data Schema

Generated from the real migrations (30 migration files, 75 tables). Production target is MySQL 8 / MariaDB 10.6+ (utf8mb4); types below are as reported by the SQLite test database, MySQL maps them to CHAR(36) for UUIDs, DECIMAL(12,2) for money, DATETIME/TIMESTAMP for dates, JSON for json.

**Conventions:** primary keys are UUIDs (`id`); money is stored as `DECIMAL(12,2)` in BDT and computed in integer paisa in code (`App\Support\Money`); most tables carry `branch_id` (multi-branch scoping) and `created_at/updated_at`; `deleted_at` marks soft deletes where present. `audit_logs` is append-only (optionally DB-trigger protected, see DEPLOYMENT.md).

## Table index

`app_settings`, `attendance_records`, `audit_logs`, `backup_runs`, `branch_user`, `branches`, `campaigns`, `cash_movements`, `cash_shifts`, `categories`, `cms_pages`, `combo_items`, `combos`, `customer_addresses`, `customer_segments`, `customer_sessions`, `customers`, `delivery_assignments`, `delivery_zones`, `device_tokens`, `dining_tables`, `employees`, `expense_categories`, `expenses`, `failed_jobs`, `floor_plans`, `food_items`, `gift_card_entries`, `gift_cards`, `ingredient_stocks`, `ingredients`, `jobs`, `kitchen_stations`, `loyalty_ledger_entries`, `media`, `modifier_groups`, `modifiers`, `notification_deliveries`, `notifications`, `order_items`, `orders`, `password_reset_tokens`, `payment_gateway_configs`, `payment_sessions`, `payments`, `payroll_entries`, `payroll_periods`, `permission_role`, `permissions`, `personal_access_tokens`, `printers`, `promotion_redemptions`, `promotions`, `purchase_items`, `purchases`, `recipe_ingredients`, `reservations`, `reviews`, `rider_cash_collections`, `riders`, `role_user`, `roles`, `site_settings`, `stock_ledger_entries`, `stock_transfer_items`, `stock_transfers`, `suppliers`, `tax_rates`, `units`, `users`, `warehouses`, `webhook_deliveries`, `webhooks`, `wifi_vouchers`, `wifi_zones`


## `app_settings`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `key` | varchar | NO |  | PK |
| `value` | TEXT | yes |  |  |
| `updated_by` | varchar | yes |  | FK→users.id |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

Indexes: UNIQUE (key)

## `attendance_records`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `employee_id` | varchar | NO |  | FK→employees.id |
| `branch_id` | varchar | NO |  | FK→branches.id |
| `date` | date | NO |  |  |
| `check_in_time` | datetime | yes |  |  |
| `check_out_time` | datetime | yes |  |  |
| `status` | varchar | NO | 'present' |  |
| `notes` | varchar | yes |  |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

Indexes: UNIQUE (employee_id, date); UNIQUE (id)

## `audit_logs`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `actor_id` | varchar | yes |  | FK→users.id |
| `actor_name` | varchar | yes |  |  |
| `action` | varchar | NO |  |  |
| `subject_type` | varchar | NO |  |  |
| `subject_id` | varchar | NO |  |  |
| `branch_id` | varchar | yes |  | FK→branches.id |
| `changes` | TEXT | yes |  |  |
| `ip_address` | varchar | yes |  |  |
| `created_at` | datetime | NO |  |  |

Indexes: INDEX (action); INDEX (subject_type, subject_id); UNIQUE (id)

## `backup_runs`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `status` | varchar | NO | 'running' |  |
| `trigger` | varchar | NO | 'schedule' |  |
| `filename` | varchar | yes |  |  |
| `size_bytes` | INTEGER | yes |  |  |
| `sha256` | varchar | yes |  |  |
| `encrypted` | tinyint(1) | NO | '0' |  |
| `db_driver` | varchar | yes |  |  |
| `includes_media` | tinyint(1) | NO | '0' |  |
| `offsite_disk` | varchar | yes |  |  |
| `offsite_ok` | tinyint(1) | yes |  |  |
| `error` | varchar | yes |  |  |
| `verified_at` | datetime | yes |  |  |
| `created_by` | varchar | yes |  | FK→users.id |
| `started_at` | datetime | yes |  |  |
| `finished_at` | datetime | yes |  |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

Indexes: INDEX (status, started_at); UNIQUE (id)

## `branch_user`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `branch_id` | varchar | NO |  | PK FK→branches.id |
| `user_id` | varchar | NO |  | PK FK→users.id |

Indexes: UNIQUE (branch_id, user_id)

## `branches`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `name` | varchar | NO |  |  |
| `code` | varchar | NO |  |  |
| `address` | varchar | yes |  |  |
| `phone` | varchar | yes |  |  |
| `timezone` | varchar | NO | 'Asia/Dhaka' |  |
| `is_active` | tinyint(1) | NO | '1' |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |
| `deleted_at` | datetime | yes |  |  |

Indexes: UNIQUE (code); UNIQUE (id)

## `campaigns`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `branch_id` | varchar | yes |  | FK→branches.id |
| `segment_id` | varchar | yes |  | FK→customer_segments.id |
| `name` | varchar | NO |  |  |
| `title` | varchar | NO |  |  |
| `body` | TEXT | NO |  |  |
| `channels` | TEXT | NO |  |  |
| `status` | varchar | NO | 'draft' |  |
| `scheduled_at` | datetime | yes |  |  |
| `sent_at` | datetime | yes |  |  |
| `audience_size` | INTEGER | NO | '0' |  |
| `queued` | TEXT | yes |  |  |
| `created_by` | varchar | yes |  | FK→users.id |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

Indexes: INDEX (status, scheduled_at); UNIQUE (id)

## `cash_movements`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `shift_id` | varchar | NO |  | FK→cash_shifts.id |
| `branch_id` | varchar | NO |  |  |
| `type` | varchar | NO |  |  |
| `amount` | numeric | NO |  |  |
| `reason` | varchar | NO |  |  |
| `created_by` | varchar | NO |  |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

Indexes: UNIQUE (id)

## `cash_shifts`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `branch_id` | varchar | NO |  | FK→branches.id |
| `opened_by` | varchar | NO |  | FK→users.id |
| `opening_float` | numeric | NO | '0' |  |
| `opened_at` | datetime | NO |  |  |
| `closed_by` | varchar | yes |  |  |
| `closed_at` | datetime | yes |  |  |
| `expected_cash` | numeric | yes |  |  |
| `counted_cash` | numeric | yes |  |  |
| `variance` | numeric | yes |  |  |
| `notes` | varchar | yes |  |  |
| `status` | varchar | NO | 'open' |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

Indexes: INDEX (branch_id, status); UNIQUE (id)

## `categories`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `branch_id` | varchar | yes |  | FK→branches.id |
| `name` | varchar | NO |  |  |
| `slug` | varchar | NO |  |  |
| `description` | TEXT | yes |  |  |
| `sort_order` | INTEGER | NO | '0' |  |
| `is_active` | tinyint(1) | NO | '1' |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |
| `deleted_at` | datetime | yes |  |  |

Indexes: UNIQUE (branch_id, slug); UNIQUE (id)

## `cms_pages`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `slug` | varchar | NO |  |  |
| `title` | varchar | NO |  |  |
| `sections` | TEXT | yes |  |  |
| `meta_title` | varchar | yes |  |  |
| `meta_description` | varchar | yes |  |  |
| `og_media_id` | varchar | yes |  | FK→media.id |
| `is_published` | tinyint(1) | NO | '0' |  |
| `published_at` | datetime | yes |  |  |
| `updated_by` | varchar | yes |  | FK→users.id |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |
| `deleted_at` | datetime | yes |  |  |

Indexes: UNIQUE (slug); UNIQUE (id)

## `combo_items`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `combo_id` | varchar | NO |  | FK→combos.id |
| `food_item_id` | varchar | NO |  | FK→food_items.id |
| `quantity` | INTEGER | NO | '1' |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

Indexes: UNIQUE (combo_id, food_item_id); UNIQUE (id)

## `combos`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `branch_id` | varchar | NO |  | FK→branches.id |
| `name` | varchar | NO |  |  |
| `description` | TEXT | yes |  |  |
| `price` | numeric | NO |  |  |
| `starts_at` | datetime | yes |  |  |
| `ends_at` | datetime | yes |  |  |
| `is_active` | tinyint(1) | NO | '1' |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |
| `deleted_at` | datetime | yes |  |  |

Indexes: UNIQUE (branch_id, name); UNIQUE (id)

## `customer_addresses`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `customer_id` | varchar | NO |  | FK→customers.id |
| `label` | varchar | NO |  |  |
| `address` | varchar | NO |  |  |
| `is_default` | tinyint(1) | NO | '0' |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

Indexes: INDEX (customer_id); UNIQUE (id)

## `customer_segments`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `branch_id` | varchar | yes |  | FK→branches.id |
| `name` | varchar | NO |  |  |
| `description` | varchar | yes |  |  |
| `match` | varchar | NO | 'all' |  |
| `rules` | TEXT | NO |  |  |
| `created_by` | varchar | yes |  | FK→users.id |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

Indexes: UNIQUE (id)

## `customer_sessions`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `customer_id` | varchar | NO |  | FK→customers.id |
| `token_hash` | varchar | NO |  |  |
| `ip_address` | varchar | yes |  |  |
| `user_agent` | varchar | yes |  |  |
| `last_used_at` | datetime | yes |  |  |
| `expires_at` | datetime | NO |  |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

Indexes: UNIQUE (token_hash); INDEX (customer_id, expires_at); UNIQUE (id)

## `customers`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `branch_id` | varchar | yes |  | FK→branches.id |
| `name` | varchar | NO |  |  |
| `phone` | varchar | NO |  |  |
| `email` | varchar | yes |  |  |
| `address` | varchar | yes |  |  |
| `notes` | TEXT | yes |  |  |
| `loyalty_points` | INTEGER | NO | '0' |  |
| `is_active` | tinyint(1) | NO | '1' |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |
| `deleted_at` | datetime | yes |  |  |
| `marketing_opt_out` | tinyint(1) | NO | '0' |  |
| `marketing_opt_out_at` | datetime | yes |  |  |
| `locale` | varchar | yes |  |  |
| `phone_verified_at` | datetime | yes |  |  |
| `last_login_at` | datetime | yes |  |  |

Indexes: UNIQUE (phone); UNIQUE (id)

## `delivery_assignments`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `order_id` | varchar | NO |  | FK→orders.id |
| `branch_id` | varchar | NO |  | FK→branches.id |
| `rider_id` | varchar | yes |  | FK→riders.id |
| `delivery_zone_id` | varchar | yes |  | FK→delivery_zones.id |
| `delivery_address` | varchar | yes |  |  |
| `status` | varchar | NO | 'pending' |  |
| `assigned_at` | datetime | yes |  |  |
| `picked_up_at` | datetime | yes |  |  |
| `delivered_at` | datetime | yes |  |  |
| `failed_at` | datetime | yes |  |  |
| `failure_reason` | varchar | yes |  |  |
| `recipient_name` | varchar | yes |  |  |
| `proof_note` | TEXT | yes |  |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

Indexes: UNIQUE (order_id); INDEX (rider_id); INDEX (branch_id, status); UNIQUE (id)

## `delivery_zones`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `branch_id` | varchar | NO |  | FK→branches.id |
| `name` | varchar | NO |  |  |
| `delivery_fee` | numeric | NO | '0' |  |
| `is_active` | tinyint(1) | NO | '1' |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |
| `deleted_at` | datetime | yes |  |  |

Indexes: UNIQUE (branch_id, name); UNIQUE (id)

## `device_tokens`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `owner_type` | varchar | NO |  |  |
| `owner_id` | varchar | NO |  |  |
| `token` | varchar | NO |  |  |
| `platform` | varchar | NO |  |  |
| `last_seen_at` | datetime | yes |  |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

Indexes: UNIQUE (token); INDEX (owner_type, owner_id); UNIQUE (id)

## `dining_tables`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `branch_id` | varchar | NO |  | FK→branches.id |
| `label` | varchar | NO |  |  |
| `capacity` | INTEGER | NO |  |  |
| `status` | varchar | NO | 'available' |  |
| `pos_x` | INTEGER | NO | '0' |  |
| `pos_y` | INTEGER | NO | '0' |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |
| `deleted_at` | datetime | yes |  |  |
| `floor_plan_id` | varchar | yes |  | FK→floor_plans.id |
| `width` | INTEGER | NO | '80' |  |
| `height` | INTEGER | NO | '80' |  |
| `shape` | varchar | NO | 'rect' |  |

Indexes: UNIQUE (branch_id, label); UNIQUE (id)

## `employees`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `branch_id` | varchar | NO |  | FK→branches.id |
| `user_id` | varchar | yes |  | FK→users.id |
| `name` | varchar | NO |  |  |
| `phone` | varchar | NO |  |  |
| `email` | varchar | yes |  |  |
| `role_title` | varchar | NO |  |  |
| `employment_type` | varchar | NO | 'full_time' |  |
| `monthly_salary` | numeric | NO | '0' |  |
| `hire_date` | date | yes |  |  |
| `is_active` | tinyint(1) | NO | '1' |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |
| `deleted_at` | datetime | yes |  |  |

Indexes: UNIQUE (phone); INDEX (branch_id, is_active); UNIQUE (id)

## `expense_categories`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `branch_id` | varchar | yes |  | FK→branches.id |
| `name` | varchar | NO |  |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |
| `deleted_at` | datetime | yes |  |  |

Indexes: UNIQUE (branch_id, name); UNIQUE (id)

## `expenses`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `branch_id` | varchar | NO |  | FK→branches.id |
| `category_id` | varchar | NO |  | FK→expense_categories.id |
| `amount` | numeric | NO |  |  |
| `description` | varchar | yes |  |  |
| `expense_date` | date | NO |  |  |
| `created_by` | varchar | yes |  | FK→users.id |
| `reference_type` | varchar | yes |  |  |
| `reference_id` | varchar | yes |  |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |
| `deleted_at` | datetime | yes |  |  |

Indexes: INDEX (branch_id, expense_date); UNIQUE (id)

## `failed_jobs`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | INTEGER | NO |  | PK |
| `uuid` | varchar | NO |  |  |
| `connection` | TEXT | NO |  |  |
| `queue` | TEXT | NO |  |  |
| `payload` | TEXT | NO |  |  |
| `exception` | TEXT | NO |  |  |
| `failed_at` | datetime | NO | CURRENT_TIMESTAMP |  |

Indexes: UNIQUE (uuid)

## `floor_plans`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `branch_id` | varchar | NO |  | FK→branches.id |
| `name` | varchar | NO |  |  |
| `width` | INTEGER | NO | '1000' |  |
| `height` | INTEGER | NO | '600' |  |
| `sort_order` | INTEGER | NO | '0' |  |
| `is_active` | tinyint(1) | NO | '1' |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |
| `deleted_at` | datetime | yes |  |  |

Indexes: UNIQUE (branch_id, name); UNIQUE (id)

## `food_items`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `category_id` | varchar | NO |  | FK→categories.id |
| `branch_id` | varchar | yes |  | FK→branches.id |
| `name` | varchar | NO |  |  |
| `slug` | varchar | NO |  |  |
| `description` | TEXT | yes |  |  |
| `base_price` | numeric | NO |  |  |
| `tax_rate` | numeric | NO | '0' |  |
| `image_path` | varchar | yes |  |  |
| `is_available` | tinyint(1) | NO | '1' |  |
| `sort_order` | INTEGER | NO | '0' |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |
| `deleted_at` | datetime | yes |  |  |
| `tax_rate_id` | varchar | yes |  | FK→tax_rates.id |
| `kitchen_station_id` | varchar | yes |  | FK→kitchen_stations.id |

Indexes: INDEX (category_id, is_available); UNIQUE (id)

## `gift_card_entries`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `gift_card_id` | varchar | NO |  | FK→gift_cards.id |
| `kind` | varchar | NO |  |  |
| `amount` | numeric | NO |  |  |
| `balance_after` | numeric | NO |  |  |
| `order_id` | varchar | yes |  | FK→orders.id |
| `payment_id` | varchar | yes |  | FK→payments.id |
| `reason` | varchar | yes |  |  |
| `created_by` | varchar | yes |  | FK→users.id |
| `created_at` | datetime | NO | CURRENT_TIMESTAMP |  |

Indexes: INDEX (gift_card_id, created_at); UNIQUE (id)

## `gift_cards`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `branch_id` | varchar | yes |  | FK→branches.id |
| `code_hash` | varchar | NO |  |  |
| `code_last4` | varchar | NO |  |  |
| `initial_value` | numeric | NO |  |  |
| `balance` | numeric | NO |  |  |
| `status` | varchar | NO | 'active' |  |
| `expires_at` | datetime | yes |  |  |
| `customer_id` | varchar | yes |  | FK→customers.id |
| `recipient_name` | varchar | yes |  |  |
| `note` | varchar | yes |  |  |
| `sold_method` | varchar | yes |  |  |
| `sold_reference` | varchar | yes |  |  |
| `created_by` | varchar | yes |  | FK→users.id |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

Indexes: UNIQUE (code_hash); INDEX (status, expires_at); UNIQUE (id)

## `ingredient_stocks`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `ingredient_id` | varchar | NO |  | FK→ingredients.id |
| `warehouse_id` | varchar | NO |  | FK→warehouses.id |
| `quantity` | numeric | NO | '0' |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

Indexes: UNIQUE (ingredient_id, warehouse_id); UNIQUE (id)

## `ingredients`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `branch_id` | varchar | NO |  | FK→branches.id |
| `unit_id` | varchar | NO |  | FK→units.id |
| `name` | varchar | NO |  |  |
| `reorder_level` | numeric | NO | '0' |  |
| `cost_per_unit` | numeric | NO | '0' |  |
| `is_active` | tinyint(1) | NO | '1' |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |
| `deleted_at` | datetime | yes |  |  |

Indexes: INDEX (branch_id, is_active); UNIQUE (branch_id, name); UNIQUE (id)

## `jobs`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | INTEGER | NO |  | PK |
| `queue` | varchar | NO |  |  |
| `payload` | TEXT | NO |  |  |
| `attempts` | INTEGER | NO |  |  |
| `reserved_at` | INTEGER | yes |  |  |
| `available_at` | INTEGER | NO |  |  |
| `created_at` | INTEGER | NO |  |  |

Indexes: INDEX (queue)

## `kitchen_stations`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `branch_id` | varchar | NO |  | FK→branches.id |
| `name` | varchar | NO |  |  |
| `sort_order` | INTEGER | NO | '0' |  |
| `is_active` | tinyint(1) | NO | '1' |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |
| `deleted_at` | datetime | yes |  |  |

Indexes: UNIQUE (branch_id, name); UNIQUE (id)

## `loyalty_ledger_entries`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `customer_id` | varchar | NO |  | FK→customers.id |
| `type` | varchar | NO |  |  |
| `points_change` | INTEGER | NO |  |  |
| `balance_after` | INTEGER | NO |  |  |
| `reason` | varchar | yes |  |  |
| `reference_type` | varchar | yes |  |  |
| `reference_id` | varchar | yes |  |  |
| `actor_id` | varchar | yes |  | FK→users.id |
| `created_at` | datetime | NO |  |  |

Indexes: INDEX (customer_id, created_at); UNIQUE (id)

## `media`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `disk` | varchar | NO | 'public' |  |
| `path` | varchar | NO |  |  |
| `original_filename` | varchar | NO |  |  |
| `mime_type` | varchar | NO |  |  |
| `size_bytes` | INTEGER | NO |  |  |
| `alt_text` | varchar | yes |  |  |
| `uploaded_by` | varchar | yes |  | FK→users.id |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |
| `deleted_at` | datetime | yes |  |  |

Indexes: UNIQUE (id)

## `modifier_groups`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `food_item_id` | varchar | NO |  | FK→food_items.id |
| `name` | varchar | NO |  |  |
| `is_required` | tinyint(1) | NO | '0' |  |
| `min_select` | INTEGER | NO | '0' |  |
| `max_select` | INTEGER | NO | '1' |  |
| `sort_order` | INTEGER | NO | '0' |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

Indexes: UNIQUE (id)

## `modifiers`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `modifier_group_id` | varchar | NO |  | FK→modifier_groups.id |
| `name` | varchar | NO |  |  |
| `price` | numeric | NO | '0' |  |
| `is_active` | tinyint(1) | NO | '1' |  |
| `sort_order` | INTEGER | NO | '0' |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

Indexes: UNIQUE (id)

## `notification_deliveries`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `branch_id` | varchar | yes |  |  |
| `channel` | varchar | NO |  |  |
| `recipient` | varchar | NO |  |  |
| `customer_id` | varchar | yes |  |  |
| `subject` | varchar | yes |  |  |
| `body` | TEXT | NO |  |  |
| `purpose` | varchar | NO | 'custom' |  |
| `reference_type` | varchar | yes |  |  |
| `reference_id` | varchar | yes |  |  |
| `status` | varchar | NO | 'queued' |  |
| `provider` | varchar | yes |  |  |
| `provider_message_id` | varchar | yes |  |  |
| `attempts` | INTEGER | NO | '0' |  |
| `error` | TEXT | yes |  |  |
| `sent_at` | datetime | yes |  |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

Indexes: INDEX (reference_type, reference_id); INDEX (branch_id, created_at); INDEX (status, created_at); UNIQUE (id)

## `notifications`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `branch_id` | varchar | yes |  | FK→branches.id |
| `title` | varchar | NO |  |  |
| `body` | TEXT | NO |  |  |
| `audience` | varchar | NO |  |  |
| `customer_id` | varchar | yes |  | FK→customers.id |
| `status` | varchar | NO | 'draft' |  |
| `sent_at` | datetime | yes |  |  |
| `created_by` | varchar | yes |  | FK→users.id |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |
| `deleted_at` | datetime | yes |  |  |

Indexes: UNIQUE (id)

## `order_items`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `order_id` | varchar | NO |  | FK→orders.id |
| `food_item_id` | varchar | NO |  |  |
| `name` | varchar | NO |  |  |
| `quantity` | INTEGER | NO |  |  |
| `unit_price` | numeric | NO |  |  |
| `total` | numeric | NO |  |  |
| `modifiers` | TEXT | yes |  |  |
| `notes` | TEXT | yes |  |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |
| `kitchen_station_id` | varchar | yes |  |  |
| `kitchen_state` | varchar | NO | 'new' |  |
| `combo_id` | varchar | yes |  |  |
| `combo_name` | varchar | yes |  |  |

Indexes: INDEX (order_id, kitchen_station_id); UNIQUE (id)

## `orders`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `code` | varchar | NO |  |  |
| `source` | varchar | NO |  |  |
| `branch_id` | varchar | NO |  | FK→branches.id |
| `customer_id` | varchar | yes |  | FK→customers.id |
| `subtotal` | numeric | NO | '0' |  |
| `tax_total` | numeric | NO | '0' |  |
| `discount_total` | numeric | NO | '0' |  |
| `delivery_fee` | numeric | NO | '0' |  |
| `grand_total` | numeric | NO | '0' |  |
| `payment_state` | varchar | NO | 'unpaid' |  |
| `kitchen_state` | varchar | NO | 'new' |  |
| `status` | varchar | NO | 'draft' |  |
| `idempotency_key` | varchar | yes |  |  |
| `created_by` | varchar | yes |  | FK→users.id |
| `cancelled_by` | varchar | yes |  | FK→users.id |
| `cancelled_at` | datetime | yes |  |  |
| `cancellation_reason` | varchar | yes |  |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |
| `deleted_at` | datetime | yes |  |  |
| `tax_inclusive_total` | numeric | NO | '0' |  |
| `dining_table_id` | varchar | yes |  | FK→dining_tables.id |
| `manual_discount_total` | numeric | NO | '0' |  |
| `discount_reason` | varchar | yes |  |  |
| `discount_by` | varchar | yes |  |  |
| `stock_restocked_at` | datetime | yes |  |  |
| `channel` | varchar | yes |  |  |
| `delivery_phone` | varchar | yes |  |  |

Indexes: INDEX (dining_table_id, status); UNIQUE (idempotency_key); UNIQUE (code); INDEX (branch_id, status); INDEX (branch_id, created_at); UNIQUE (id)

## `password_reset_tokens`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `email` | varchar | NO |  | PK |
| `token` | varchar | NO |  |  |
| `created_at` | datetime | yes |  |  |

Indexes: UNIQUE (email)

## `payment_gateway_configs`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `gateway_key` | varchar | NO |  |  |
| `display_name` | varchar | NO |  |  |
| `is_enabled` | tinyint(1) | NO | '0' |  |
| `config` | TEXT | yes |  |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

Indexes: UNIQUE (gateway_key); UNIQUE (id)

## `payment_sessions`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `order_id` | varchar | NO |  | FK→orders.id |
| `branch_id` | varchar | NO |  | FK→branches.id |
| `gateway_key` | varchar | NO |  |  |
| `amount` | numeric | NO |  |  |
| `currency` | varchar | NO | 'BDT' |  |
| `status` | varchar | NO | 'created' |  |
| `provider_ref` | varchar | yes |  |  |
| `provider_trx` | varchar | yes |  |  |
| `redirect_url` | TEXT | yes |  |  |
| `qr_payload` | TEXT | yes |  |  |
| `payload` | TEXT | yes |  |  |
| `payment_id` | varchar | yes |  | FK→payments.id |
| `note` | varchar | yes |  |  |
| `expires_at` | datetime | yes |  |  |
| `settled_at` | datetime | yes |  |  |
| `created_by` | varchar | yes |  | FK→users.id |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

Indexes: UNIQUE (gateway_key, provider_ref); INDEX (order_id, gateway_key); INDEX (status, created_at); UNIQUE (id)

## `payments`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `order_id` | varchar | NO |  | FK→orders.id |
| `branch_id` | varchar | NO |  | FK→branches.id |
| `shift_id` | varchar | yes |  | FK→cash_shifts.id |
| `kind` | varchar | NO | 'payment' |  |
| `method` | varchar | NO |  |  |
| `reference` | varchar | yes |  |  |
| `amount` | numeric | NO |  |  |
| `tip` | numeric | NO | '0' |  |
| `tendered` | numeric | yes |  |  |
| `change_given` | numeric | yes |  |  |
| `refunds_payment_id` | varchar | yes |  |  |
| `reason` | varchar | yes |  |  |
| `restocked` | tinyint(1) | NO | '0' |  |
| `voided_at` | datetime | yes |  |  |
| `voided_by` | varchar | yes |  |  |
| `void_reason` | varchar | yes |  |  |
| `idempotency_key` | varchar | yes |  |  |
| `created_by` | varchar | yes |  |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

Indexes: UNIQUE (idempotency_key); INDEX (shift_id); INDEX (order_id, kind); UNIQUE (id)

## `payroll_entries`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `payroll_period_id` | varchar | NO |  | FK→payroll_periods.id |
| `employee_id` | varchar | NO |  | FK→employees.id |
| `base_salary` | numeric | NO |  |  |
| `absent_days` | INTEGER | NO | '0' |  |
| `deductions` | numeric | NO | '0' |  |
| `bonuses` | numeric | NO | '0' |  |
| `net_pay` | numeric | NO |  |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

Indexes: UNIQUE (payroll_period_id, employee_id); UNIQUE (id)

## `payroll_periods`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `branch_id` | varchar | NO |  | FK→branches.id |
| `period_start` | date | NO |  |  |
| `period_end` | date | NO |  |  |
| `status` | varchar | NO | 'draft' |  |
| `created_by` | varchar | yes |  | FK→users.id |
| `finalized_at` | datetime | yes |  |  |
| `paid_at` | datetime | yes |  |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |
| `deleted_at` | datetime | yes |  |  |

Indexes: UNIQUE (branch_id, period_start, period_end); UNIQUE (id)

## `permission_role`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `permission_id` | varchar | NO |  | PK FK→permissions.id |
| `role_id` | varchar | NO |  | PK FK→roles.id |

Indexes: UNIQUE (permission_id, role_id)

## `permissions`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `name` | varchar | NO |  |  |
| `description` | varchar | yes |  |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

Indexes: UNIQUE (name); UNIQUE (id)

## `personal_access_tokens`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | INTEGER | NO |  | PK |
| `tokenable_type` | varchar | NO |  |  |
| `tokenable_id` | varchar | NO |  |  |
| `name` | varchar | NO |  |  |
| `token` | varchar | NO |  |  |
| `abilities` | TEXT | yes |  |  |
| `last_used_at` | datetime | yes |  |  |
| `expires_at` | datetime | yes |  |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |
| `session_key` | varchar | yes |  |  |
| `ip_address` | varchar | yes |  |  |
| `user_agent` | varchar | yes |  |  |

Indexes: INDEX (session_key); UNIQUE (token); INDEX (tokenable_type, tokenable_id)

## `printers`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `branch_id` | varchar | NO |  | FK→branches.id |
| `name` | varchar | NO |  |  |
| `connection` | varchar | NO |  |  |
| `role` | varchar | NO | 'receipt' |  |
| `host` | varchar | yes |  |  |
| `port` | INTEGER | NO | '9100' |  |
| `kitchen_station_id` | varchar | yes |  | FK→kitchen_stations.id |
| `paper_width` | INTEGER | NO | '80' |  |
| `opens_drawer` | tinyint(1) | NO | '0' |  |
| `is_active` | tinyint(1) | NO | '1' |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

Indexes: UNIQUE (id)

## `promotion_redemptions`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `promotion_id` | varchar | NO |  | FK→promotions.id |
| `order_id` | varchar | NO |  | FK→orders.id |
| `customer_id` | varchar | yes |  | FK→customers.id |
| `discount_amount` | numeric | NO |  |  |
| `created_at` | datetime | NO |  |  |

Indexes: INDEX (promotion_id, customer_id); UNIQUE (id)

## `promotions`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `branch_id` | varchar | yes |  | FK→branches.id |
| `code` | varchar | NO |  |  |
| `name` | varchar | NO |  |  |
| `type` | varchar | NO |  |  |
| `value` | numeric | NO |  |  |
| `min_order_amount` | numeric | NO | '0' |  |
| `max_discount_amount` | numeric | yes |  |  |
| `usage_limit` | INTEGER | yes |  |  |
| `usage_limit_per_customer` | INTEGER | yes |  |  |
| `used_count` | INTEGER | NO | '0' |  |
| `starts_at` | datetime | yes |  |  |
| `expires_at` | datetime | yes |  |  |
| `is_active` | tinyint(1) | NO | '1' |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |
| `deleted_at` | datetime | yes |  |  |

Indexes: UNIQUE (code); UNIQUE (id)

## `purchase_items`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `purchase_id` | varchar | NO |  | FK→purchases.id |
| `ingredient_id` | varchar | NO |  | FK→ingredients.id |
| `quantity` | numeric | NO |  |  |
| `unit_cost` | numeric | NO |  |  |
| `total_cost` | numeric | NO |  |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

Indexes: UNIQUE (id)

## `purchases`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `branch_id` | varchar | NO |  | FK→branches.id |
| `supplier_id` | varchar | NO |  | FK→suppliers.id |
| `warehouse_id` | varchar | NO |  | FK→warehouses.id |
| `status` | varchar | NO | 'draft' |  |
| `total_cost` | numeric | NO | '0' |  |
| `received_at` | datetime | yes |  |  |
| `created_by` | varchar | yes |  | FK→users.id |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |
| `deleted_at` | datetime | yes |  |  |

Indexes: UNIQUE (id)

## `recipe_ingredients`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `food_item_id` | varchar | NO |  | FK→food_items.id |
| `ingredient_id` | varchar | NO |  | FK→ingredients.id |
| `quantity_required` | numeric | NO |  |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

Indexes: UNIQUE (food_item_id, ingredient_id); UNIQUE (id)

## `reservations`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `branch_id` | varchar | NO |  | FK→branches.id |
| `dining_table_id` | varchar | yes |  | FK→dining_tables.id |
| `customer_name` | varchar | NO |  |  |
| `customer_phone` | varchar | NO |  |  |
| `party_size` | INTEGER | NO |  |  |
| `reserved_for` | datetime | NO |  |  |
| `duration_minutes` | INTEGER | NO | '90' |  |
| `status` | varchar | NO | 'pending' |  |
| `notes` | TEXT | yes |  |  |
| `created_by` | varchar | yes |  | FK→users.id |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |
| `deleted_at` | datetime | yes |  |  |
| `customer_id` | varchar | yes |  | FK→customers.id |

Indexes: INDEX (branch_id, reserved_for); UNIQUE (id)

## `reviews`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `branch_id` | varchar | NO |  | FK→branches.id |
| `order_id` | varchar | yes |  | FK→orders.id |
| `customer_id` | varchar | yes |  | FK→customers.id |
| `reviewer_name` | varchar | yes |  |  |
| `rating` | INTEGER | NO |  |  |
| `comment` | TEXT | yes |  |  |
| `is_published` | tinyint(1) | NO | '0' |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |
| `deleted_at` | datetime | yes |  |  |

Indexes: INDEX (branch_id, is_published); UNIQUE (id)

## `rider_cash_collections`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `branch_id` | varchar | NO |  | FK→branches.id |
| `rider_id` | varchar | NO |  | FK→riders.id |
| `order_id` | varchar | NO |  | FK→orders.id |
| `delivery_assignment_id` | varchar | NO |  | FK→delivery_assignments.id |
| `payment_id` | varchar | yes |  | FK→payments.id |
| `amount` | numeric | NO |  |  |
| `collected_at` | datetime | NO |  |  |
| `settled_at` | datetime | yes |  |  |
| `settled_by` | varchar | yes |  | FK→users.id |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

Indexes: UNIQUE (delivery_assignment_id); INDEX (rider_id, settled_at); UNIQUE (id)

## `riders`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `branch_id` | varchar | NO |  | FK→branches.id |
| `name` | varchar | NO |  |  |
| `phone` | varchar | NO |  |  |
| `vehicle_type` | varchar | NO | 'motorbike' |  |
| `status` | varchar | NO | 'offline' |  |
| `is_active` | tinyint(1) | NO | '1' |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |
| `deleted_at` | datetime | yes |  |  |
| `user_id` | varchar | yes |  | FK→users.id |
| `location_sharing` | tinyint(1) | NO | '0' |  |
| `last_lat` | numeric | yes |  |  |
| `last_lng` | numeric | yes |  |  |
| `last_located_at` | datetime | yes |  |  |

Indexes: UNIQUE (user_id); UNIQUE (phone); UNIQUE (id)

## `role_user`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `role_id` | varchar | NO |  | PK FK→roles.id |
| `user_id` | varchar | NO |  | PK FK→users.id |

Indexes: UNIQUE (role_id, user_id)

## `roles`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `name` | varchar | NO |  |  |
| `description` | varchar | yes |  |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

Indexes: UNIQUE (name); UNIQUE (id)

## `site_settings`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `site_name` | varchar | NO | 'Chefs Trolley' |  |
| `tagline` | varchar | yes |  |  |
| `primary_color` | varchar | NO | '#FF0000' |  |
| `logo_media_id` | varchar | yes |  | FK→media.id |
| `favicon_media_id` | varchar | yes |  | FK→media.id |
| `contact_phone` | varchar | yes |  |  |
| `contact_email` | varchar | yes |  |  |
| `social_facebook` | varchar | yes |  |  |
| `social_instagram` | varchar | yes |  |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

Indexes: UNIQUE (id)

## `stock_ledger_entries`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `ingredient_id` | varchar | NO |  | FK→ingredients.id |
| `warehouse_id` | varchar | NO |  | FK→warehouses.id |
| `branch_id` | varchar | NO |  | FK→branches.id |
| `type` | varchar | NO |  |  |
| `quantity_change` | numeric | NO |  |  |
| `balance_after` | numeric | NO |  |  |
| `reason` | varchar | yes |  |  |
| `reference_type` | varchar | yes |  |  |
| `reference_id` | varchar | yes |  |  |
| `actor_id` | varchar | yes |  | FK→users.id |
| `created_at` | datetime | NO |  |  |

Indexes: INDEX (reference_type, reference_id); INDEX (warehouse_id, created_at); INDEX (ingredient_id, created_at); UNIQUE (id)

## `stock_transfer_items`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `stock_transfer_id` | varchar | NO |  | FK→stock_transfers.id |
| `ingredient_id` | varchar | NO |  | FK→ingredients.id |
| `quantity` | numeric | NO |  |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

Indexes: UNIQUE (id)

## `stock_transfers`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `branch_id` | varchar | NO |  | FK→branches.id |
| `from_warehouse_id` | varchar | NO |  | FK→warehouses.id |
| `to_warehouse_id` | varchar | NO |  | FK→warehouses.id |
| `created_by` | varchar | yes |  | FK→users.id |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |
| `deleted_at` | datetime | yes |  |  |

Indexes: UNIQUE (id)

## `suppliers`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `branch_id` | varchar | yes |  | FK→branches.id |
| `name` | varchar | NO |  |  |
| `contact_person` | varchar | yes |  |  |
| `phone` | varchar | yes |  |  |
| `email` | varchar | yes |  |  |
| `address` | varchar | yes |  |  |
| `is_active` | tinyint(1) | NO | '1' |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |
| `deleted_at` | datetime | yes |  |  |

Indexes: UNIQUE (id)

## `tax_rates`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `branch_id` | varchar | NO |  | FK→branches.id |
| `name` | varchar | NO |  |  |
| `rate` | numeric | NO |  |  |
| `is_inclusive` | tinyint(1) | NO | '0' |  |
| `is_default` | tinyint(1) | NO | '0' |  |
| `is_active` | tinyint(1) | NO | '1' |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |
| `deleted_at` | datetime | yes |  |  |

Indexes: INDEX (branch_id, is_default); UNIQUE (id)

## `units`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `name` | varchar | NO |  |  |
| `abbreviation` | varchar | NO |  |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

Indexes: UNIQUE (abbreviation); UNIQUE (id)

## `users`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `name` | varchar | NO |  |  |
| `email` | varchar | NO |  |  |
| `email_verified_at` | datetime | yes |  |  |
| `password` | varchar | NO |  |  |
| `active_branch_id` | varchar | yes |  | FK→branches.id |
| `is_active` | tinyint(1) | NO | '1' |  |
| `remember_token` | varchar | yes |  |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |
| `deleted_at` | datetime | yes |  |  |
| `mfa_secret` | TEXT | yes |  |  |
| `mfa_confirmed_at` | datetime | yes |  |  |
| `mfa_recovery_codes` | TEXT | yes |  |  |
| `mfa_last_step` | INTEGER | yes |  |  |
| `failed_login_count` | INTEGER | NO | '0' |  |
| `locked_until` | datetime | yes |  |  |
| `password_changed_at` | datetime | yes |  |  |
| `last_login_at` | datetime | yes |  |  |
| `last_login_ip` | varchar | yes |  |  |

Indexes: UNIQUE (email); UNIQUE (id)

## `warehouses`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `branch_id` | varchar | NO |  | FK→branches.id |
| `name` | varchar | NO |  |  |
| `is_default` | tinyint(1) | NO | '0' |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |
| `deleted_at` | datetime | yes |  |  |

Indexes: UNIQUE (branch_id, name); UNIQUE (id)

## `webhook_deliveries`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `webhook_id` | varchar | NO |  | FK→webhooks.id |
| `event` | varchar | NO |  |  |
| `event_id` | varchar | NO |  |  |
| `payload` | TEXT | NO |  |  |
| `status` | varchar | NO | 'queued' |  |
| `attempts` | INTEGER | NO | '0' |  |
| `response_status` | INTEGER | yes |  |  |
| `response_excerpt` | varchar | yes |  |  |
| `error` | TEXT | yes |  |  |
| `next_attempt_at` | datetime | yes |  |  |
| `delivered_at` | datetime | yes |  |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

Indexes: INDEX (status, created_at); INDEX (webhook_id, created_at); UNIQUE (id)

## `webhooks`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `url` | varchar | NO |  |  |
| `event` | varchar | NO |  |  |
| `secret` | varchar | NO |  |  |
| `is_active` | tinyint(1) | NO | '1' |  |
| `created_by` | varchar | yes |  | FK→users.id |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |
| `deleted_at` | datetime | yes |  |  |

Indexes: UNIQUE (id)

## `wifi_vouchers`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `wifi_zone_id` | varchar | NO |  | FK→wifi_zones.id |
| `code` | varchar | NO |  |  |
| `duration_minutes` | INTEGER | NO |  |  |
| `is_used` | tinyint(1) | NO | '0' |  |
| `used_at` | datetime | yes |  |  |
| `created_by` | varchar | yes |  | FK→users.id |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

Indexes: UNIQUE (code); UNIQUE (id)

## `wifi_zones`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar | NO |  | PK |
| `branch_id` | varchar | NO |  | FK→branches.id |
| `name` | varchar | NO |  |  |
| `ssid` | varchar | yes |  |  |
| `access_type` | varchar | NO | 'open' |  |
| `default_session_minutes` | INTEGER | yes |  |  |
| `is_active` | tinyint(1) | NO | '1' |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |
| `deleted_at` | datetime | yes |  |  |

Indexes: UNIQUE (branch_id, name); UNIQUE (id)
