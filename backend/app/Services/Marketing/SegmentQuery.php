<?php

namespace App\Services\Marketing;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Turns a saved rule list into a customer query. Rules are validated against a fixed field/operator table,
 * so nothing from the request ever reaches SQL except as a bound value.
 *
 * "Orders" here means orders whose payment_state is paid or partially_refunded, so unpaid, cancelled and
 * fully-refunded orders never count towards a customer's spend or visit count.
 */
class SegmentQuery
{
    public const OPS = ['gte', 'lte', 'eq'];

    /** field => [type, label]. `number` fields take gte/lte/eq; `bool` fields take eq with true/false. */
    public const FIELDS = [
        'total_spent' => ['number', 'Total spent (৳)'],
        'order_count' => ['number', 'Number of orders'],
        'avg_order_value' => ['number', 'Average order value (৳)'],
        'days_since_last_order' => ['number', 'Days since last order'],
        'days_since_joined' => ['number', 'Days since joined'],
        'loyalty_points' => ['number', 'Loyalty points'],
        'has_ordered' => ['bool', 'Has ordered'],
        'has_phone' => ['bool', 'Has a phone number'],
        'has_email' => ['bool', 'Has an email address'],
    ];

    private const COUNTED = ['paid', 'partially_refunded'];

    public static function validationRules(): array
    {
        return [
            'match' => ['required', 'in:all,any'],
            'rules' => ['present', 'array', 'max:12'],
            'rules.*.field' => ['required', 'in:'.implode(',', array_keys(self::FIELDS))],
            'rules.*.op' => ['required', 'in:'.implode(',', self::OPS)],
            'rules.*.value' => ['required'],
        ];
    }

    /** Extra checks the generic rules can't express (bool fields take true/false, numbers take numbers). Returns an error or null. */
    public static function problem(array $rules): ?string
    {
        foreach ($rules as $rule) {
            $type = self::FIELDS[$rule['field']][0] ?? null;
            if ($type === 'bool') {
                if (! in_array($rule['value'], [true, false, 1, 0, '1', '0', 'true', 'false'], true) || $rule['op'] !== 'eq') {
                    return self::FIELDS[$rule['field']][1].' is a yes/no rule.';
                }
            } elseif (! is_numeric($rule['value']) || (float) $rule['value'] < 0 || (float) $rule['value'] > 100000000) {
                return self::FIELDS[$rule['field']][1].' needs a number of zero or more.';
            }
        }

        return null;
    }

    /** Active customers of the branch (or chain-wide), matching the rules. Opt-out is NOT applied here: use audience(). */
    public function query(array $rules, string $match, ?string $branchId): Builder
    {
        $orders = fn (): QueryBuilder => DB::table('orders')->whereColumn('orders.customer_id', 'customers.id')->whereNull('orders.deleted_at')->whereIn('orders.payment_state', self::COUNTED);

        $columns = [
            'total_spent' => fn () => $orders()->selectRaw('coalesce(sum(orders.grand_total), 0)'),
            'order_count' => fn () => $orders()->selectRaw('count(*)'),
            'avg_order_value' => fn () => $orders()->selectRaw('coalesce(avg(orders.grand_total), 0)'),
        ];

        $query = Customer::query()->where('customers.is_active', true)
            ->when($branchId, fn ($q) => $q->where(fn ($q) => $q->where('customers.branch_id', $branchId)->orWhereNull('customers.branch_id')));

        $group = function (Builder $q) use ($rules, $match, $columns, $orders) {
            foreach ($rules as $rule) {
                $boolean = $match === 'any' ? 'or' : 'and';
                $field = $rule['field'];
                $op = ['gte' => '>=', 'lte' => '<=', 'eq' => '='][$rule['op']];
                $value = $rule['value'];

                if (isset($columns[$field])) {
                    // The number is validated (is_numeric, 0..1e8) and written as a literal on purpose: PDO would bind a float
                    // as text, and SQLite then sorts every number below any text, so the comparison would never match.
                    $literal = number_format((float) $value, 2, '.', '');
                    $q->whereRaw('('.$columns[$field]()->toSql().") {$op} {$literal}", $columns[$field]()->getBindings(), $boolean);
                } elseif ($field === 'loyalty_points') {
                    $q->where('customers.loyalty_points', $op, (int) $value, $boolean);
                } elseif ($field === 'days_since_joined') {
                    // joined N+ days ago ⇔ created_at at or before the cutoff (so the comparison flips).
                    $q->where('customers.created_at', ['>=' => '<=', '<=' => '>=', '=' => '<='][$op], now()->subDays((int) $value)->endOfDay(), $boolean);
                } elseif ($field === 'days_since_last_order') {
                    // Only customers who have ordered can match; "last order N+ days ago" ⇔ latest order at or before the cutoff.
                    $last = '(select max(orders.created_at) from orders where orders.customer_id = customers.id and orders.deleted_at is null and orders.payment_state in (\'paid\', \'partially_refunded\'))';
                    $cutoff = now()->subDays((int) $value);
                    $cmp = match ($rule['op']) {
                        'gte' => [$last.' <= ?', [$cutoff->copy()->endOfDay()]],
                        'lte' => [$last.' >= ?', [$cutoff->copy()->startOfDay()]],
                        default => [$last.' between ? and ?', [$cutoff->copy()->startOfDay(), $cutoff->copy()->endOfDay()]],
                    };
                    $q->whereRaw($cmp[0], $cmp[1], $boolean);
                } elseif ($field === 'has_ordered') {
                    $want = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                    $q->whereRaw(($want ? 'exists' : 'not exists').' ('.$orders()->selectRaw('1')->toSql().')', $orders()->getBindings(), $boolean);
                } elseif ($field === 'has_phone') {
                    $this->presence($q, 'customers.phone', filter_var($value, FILTER_VALIDATE_BOOLEAN), $boolean);
                } elseif ($field === 'has_email') {
                    $this->presence($q, 'customers.email', filter_var($value, FILTER_VALIDATE_BOOLEAN), $boolean);
                }
            }
        };

        // An empty rule list means "every active customer".
        return $rules ? $query->where($group) : $query;
    }

    /** Customers a campaign may actually message: matching, and not opted out. */
    public function audience(array $rules, string $match, ?string $branchId): Builder
    {
        return $this->query($rules, $match, $branchId)->where('customers.marketing_opt_out', false);
    }

    private function presence(Builder $q, string $column, bool $want, string $boolean): void
    {
        $q->where(function ($q) use ($column, $want) {
            $want
                ? $q->whereNotNull($column)->where($column, '!=', '')
                : $q->where(fn ($q) => $q->whereNull($column)->orWhere($column, ''));
        }, null, null, $boolean);
    }
}
