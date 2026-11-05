<?php

namespace App\Services;

use App\Models\GiftCard;
use App\Models\GiftCardEntry;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Gift-card balances live only in the ledger below: every change is a signed entry written in the same
 * transaction that locks the card row, and `balance` is the running total. Money is integer paisa throughout.
 */
class GiftCards
{
    // No 0/O/1/I/L so a code read aloud or typed from a printout is hard to get wrong.
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public static function hash(string $code): string
    {
        return hash('sha256', self::normalise($code));
    }

    public static function normalise(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    /** @return array{0: GiftCard, 1: string} the card and its plain code (shown once, never stored) */
    public function issue(int $valueMinor, array $attrs, ?string $userId): array
    {
        if ($valueMinor <= 0) {
            throw new RuntimeException('A gift card needs a positive value.');
        }

        return DB::transaction(function () use ($valueMinor, $attrs, $userId) {
            do {
                $plain = collect(range(1, 16))->map(fn () => self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)])->implode('');
            } while (GiftCard::where('code_hash', self::hash($plain))->exists());

            $card = GiftCard::create($attrs + [
                'code_hash' => self::hash($plain), 'code_last4' => substr($plain, -4),
                'initial_value' => Money::toDecimal($valueMinor), 'balance' => Money::toDecimal($valueMinor),
                'created_by' => $userId,
            ]);
            $this->entry($card, 'issue', $valueMinor, $valueMinor, null, null, 'Issued', $userId);

            return [$card, $plain];
        });
    }

    public function find(string $code, bool $lock = false): ?GiftCard
    {
        $q = GiftCard::where('code_hash', self::hash($code));

        return ($lock ? $q->lockForUpdate() : $q)->first();
    }

    /** Why a card can't be spent from right now, or null when it can. */
    public function problem(GiftCard $card): ?string
    {
        return match (true) {
            $card->status === 'void' => 'This gift card has been cancelled.',
            $card->isExpired() => 'This gift card has expired.',
            Money::toMinor($card->balance) <= 0 => 'This gift card has no balance left.',
            default => null,
        };
    }

    /** Take money off a card. Caller holds a transaction and has locked the card (find(..., lock: true)). */
    public function redeem(GiftCard $card, int $amountMinor, ?string $orderId, ?string $paymentId, ?string $userId): void
    {
        $balance = Money::toMinor($card->balance);
        if ($amountMinor <= 0 || $amountMinor > $balance) {
            throw new RuntimeException('Not enough balance on this gift card.');
        }
        $this->entry($card, 'redeem', -$amountMinor, $balance - $amountMinor, $orderId, $paymentId, null, $userId);
    }

    /** Put money back (a voided payment or a refund). Allowed on a void/expired card so nothing is lost; it just can't be spent. */
    public function credit(GiftCard $card, int $amountMinor, string $kind, ?string $orderId, ?string $paymentId, string $reason, ?string $userId): void
    {
        $balance = Money::toMinor($card->balance);
        $this->entry($card, $kind, $amountMinor, $balance + $amountMinor, $orderId, $paymentId, $reason, $userId);
    }

    public function adjust(GiftCard $card, int $deltaMinor, string $reason, ?string $userId): void
    {
        $balance = Money::toMinor($card->balance);
        if ($balance + $deltaMinor < 0) {
            throw new RuntimeException('That would take the balance below zero.');
        }
        $this->entry($card, 'adjust', $deltaMinor, $balance + $deltaMinor, null, null, $reason, $userId);
    }

    public function void(GiftCard $card, string $reason, ?string $userId): void
    {
        $balance = Money::toMinor($card->balance);
        $this->entry($card, 'void', -$balance, 0, null, null, $reason, $userId);
        $card->update(['status' => 'void']);
    }

    private function entry(GiftCard $card, string $kind, int $amount, int $balanceAfter, ?string $orderId, ?string $paymentId, ?string $reason, ?string $userId): void
    {
        GiftCardEntry::create([
            'gift_card_id' => $card->id, 'kind' => $kind, 'amount' => Money::toDecimal($amount),
            'balance_after' => Money::toDecimal($balanceAfter), 'order_id' => $orderId, 'payment_id' => $paymentId,
            'reason' => $reason ? Str::limit($reason, 200, '') : null, 'created_by' => $userId,
        ]);
        $card->balance = Money::toDecimal($balanceAfter);
        $card->save();
    }
}
