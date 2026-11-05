<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Bootstrap the permission catalog and default roles. Permission keys
     * mirror the `permission` prop used in the frontend's navigation config
     * and <Can> guard so the two stay in lockstep.
     */
    public function run(): void
    {
        $permissions = [
            'orders.view', 'orders.create', 'orders.cancel',
            'pos.access', 'kds.access',
            'tables.manage', 'reservations.manage', 'kitchen.manage',
            'menu.manage', 'inventory.manage',
            'delivery.manage', 'customers.view', 'customers.manage', 'loyalty.manage',
            'promotions.manage', 'reviews.view', 'reviews.moderate', 'notifications.manage',
            'hr.manage', 'payroll.manage', 'finance.manage', 'tax.manage', 'cash.manage',
            'discounts.apply', 'discounts.override', 'orders.refund', 'printers.manage',
            'giftcards.view', 'giftcards.issue', 'giftcards.manage', 'campaigns.manage',
            'cms.manage', 'integrations.manage',
            'branches.manage', 'users.manage', 'audit.view',
            'reports.view', 'settings.manage', 'backups.manage',
            'rider.app', 'staff.app',
        ];

        $permissionModels = collect($permissions)->mapWithKeys(
            fn ($name) => [$name => Permission::firstOrCreate(['name' => $name])]
        );

        $roles = [
            'Super Admin' => $permissions, // bypasses checks anyway; granted all for consistency
            'Branch Manager' => [
                'orders.view', 'orders.cancel', 'tables.manage', 'reservations.manage',
                'kitchen.manage', 'menu.manage', 'inventory.manage', 'delivery.manage',
                'customers.view', 'customers.manage', 'loyalty.manage', 'promotions.manage',
                'reviews.view', 'reviews.moderate', 'notifications.manage', 'hr.manage',
                'cash.manage', 'tax.manage', 'reports.view',
                'discounts.apply', 'discounts.override', 'orders.refund', 'printers.manage',
                'giftcards.view', 'giftcards.issue', 'giftcards.manage', 'campaigns.manage', 'staff.app',
            ],
            'Cashier' => ['orders.view', 'orders.create', 'pos.access', 'cash.manage', 'discounts.apply', 'customers.view', 'customers.manage', 'giftcards.view', 'giftcards.issue'],
            'Waiter' => ['orders.view', 'orders.create', 'tables.manage', 'reservations.manage', 'staff.app'],
            'Kitchen Staff' => ['kds.access', 'kitchen.manage', 'staff.app'],
            // The rider app only: a rider sees and moves their OWN deliveries. delivery.manage (assign/fail anyone's) is dispatcher-only.
            'Rider' => ['rider.app'],
        ];

        foreach ($roles as $roleName => $rolePermissions) {
            $role = Role::firstOrCreate(['name' => $roleName]);
            $role->permissions()->sync(
                collect($rolePermissions)->map(fn ($p) => $permissionModels[$p]->id)
            );
        }
    }
}
