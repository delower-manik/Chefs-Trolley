<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashMovement;
use App\Models\CashShift;
use App\Models\GiftCard;
use App\Services\GiftCards;
use App\Support\Like;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GiftCardController extends Controller
{
    public function __construct(private GiftCards $cards) {}

    public function index(Request $request)
    {
        $branchId = $request->attributes->get('branch_id');
        $base = GiftCard::query()->when($branchId, fn ($q) => $q->where(fn ($q) => $q->where('branch_id', $branchId)->orWhereNull('branch_id')));

        $liability = (clone $base)->where('status', 'active')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->get()
            ->sum(fn ($c) => Money::toMinor($c->balance));

        $status = $request->query('status');
        $query = (clone $base)->with('customer:id,name')
            ->when($status === 'active', fn ($q) => $q->where('status', 'active')->where('balance', '>', 0)->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now())))
            ->when($status === 'void', fn ($q) => $q->where('status', 'void'))
            ->when($status === 'expired', fn ($q) => $q->where('status', 'active')->whereNotNull('expires_at')->where('expires_at', '<=', now()))
            ->when($status === 'used', fn ($q) => $q->where('status', 'active')->where('balance', '<=', 0))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = Like::contains((string) $request->query('search'));
                $q->where(fn ($q) => $q->whereRaw(Like::clause('recipient_name'), [$term])->orWhere('code_last4', strtoupper(trim((string) $request->query('search')))));
            })
            ->orderByDesc('created_at');

        $paginator = $query->paginate(min((int) $request->query('per_page', 20), 100));

        return response()->json([
            'data' => collect($paginator->items())->map(fn ($c) => $this->serialize($c)),
            'meta' => [
                'currentPage' => $paginator->currentPage(), 'perPage' => $paginator->perPage(),
                'total' => $paginator->total(), 'lastPage' => $paginator->lastPage(),
                'outstandingBalance' => Money::toFloat($liability),
            ],
        ]);
    }

    public function show(GiftCard $giftCard)
    {
        $giftCard->load('customer:id,name');

        return response()->json(['data' => $this->serialize($giftCard) + [
            'entries' => $giftCard->entries->map(fn ($e) => [
                'id' => $e->id, 'kind' => $e->kind, 'amount' => (float) $e->amount, 'balanceAfter' => (float) $e->balance_after,
                'orderId' => $e->order_id, 'reason' => $e->reason, 'createdAt' => $e->created_at,
            ]),
        ]]);
    }

    /** POST /gift-cards/lookup {code} — balance check at the till. The code is the secret, so this is throttled. */
    public function lookup(Request $request)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:40']]);
        $card = $this->cards->find($data['code']);
        if (! $card) {
            return response()->json(['code' => 'gift_card_invalid', 'message' => 'That gift card code was not recognised.'], 404);
        }

        return response()->json(['data' => $this->serialize($card) + ['usable' => $this->cards->problem($card) === null, 'problem' => $this->cards->problem($card)]]);
    }

    /** POST /gift-cards {value, sold_method, sold_reference?, customer_id?, recipient_name?, expires_at?, note?} */
    public function store(Request $request)
    {
        $data = $request->validate([
            'value' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'sold_method' => ['required', 'in:cash,card,mobile_banking,other'],
            'sold_reference' => ['nullable', 'string', 'max:100'],
            'customer_id' => ['nullable', 'uuid', 'exists:customers,id'],
            'recipient_name' => ['nullable', 'string', 'max:120'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        if (! ($branchId = $request->attributes->get('branch_id'))) {
            return response()->json(['code' => 'branch_required', 'message' => 'Select a branch first.'], 422);
        }

        $result = DB::transaction(function () use ($request, $data, $branchId) {
            $value = Money::toMinor($data['value']);

            $shift = null;
            if ($data['sold_method'] === 'cash') {
                $shift = CashShift::where('branch_id', $branchId)->where('opened_by', $request->user()->id)->where('status', 'open')->first();
                if (! $shift) {
                    return $this->error('no_open_shift', 'Open a cash register shift before selling a gift card for cash.');
                }
            }

            [$card, $code] = $this->cards->issue($value, [
                'branch_id' => $branchId, 'customer_id' => $data['customer_id'] ?? null, 'recipient_name' => $data['recipient_name'] ?? null,
                'expires_at' => $data['expires_at'] ?? null, 'note' => $data['note'] ?? null,
                'sold_method' => $data['sold_method'], 'sold_reference' => $data['sold_reference'] ?? null,
            ], $request->user()->id);

            // The cash taken for the card is in the drawer: record it so the shift's expected cash adds up.
            if ($shift) {
                CashMovement::create([
                    'shift_id' => $shift->id, 'branch_id' => $branchId, 'type' => 'in', 'amount' => Money::toDecimal($value),
                    'reason' => 'Gift card sale GC-'.$card->code_last4, 'created_by' => $request->user()->id,
                ]);
            }

            $this->recordAudit($request, 'giftcard.issued', $card, ['value' => Money::toFloat($value), 'sold_method' => $data['sold_method'], 'card' => 'GC-'.$card->code_last4]);

            return [$card, $code];
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        // The plain code is returned exactly once: only its hash is stored.
        return response()->json(['data' => $this->serialize($result[0]), 'code' => $result[1]], 201);
    }

    /** POST /gift-cards/{giftCard}/adjust {amount (signed), reason} */
    public function adjust(Request $request, GiftCard $giftCard)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'not_in:0', 'between:-1000000,1000000'],
            'reason' => ['required', 'string', 'min:3', 'max:200'],
        ]);

        $result = DB::transaction(function () use ($request, $giftCard, $data) {
            $card = GiftCard::lockForUpdate()->findOrFail($giftCard->id);
            if ($card->status === 'void') {
                return $this->error('gift_card_void', 'A cancelled gift card cannot be adjusted.');
            }
            try {
                $this->cards->adjust($card, Money::toMinor($data['amount']), $data['reason'], $request->user()->id);
            } catch (\RuntimeException $e) {
                return $this->error('below_zero', $e->getMessage());
            }
            $this->recordAudit($request, 'giftcard.adjusted', $card, ['amount' => (float) $data['amount'], 'reason' => $data['reason'], 'card' => 'GC-'.$card->code_last4]);

            return $card;
        });

        return $result instanceof JsonResponse ? $result : response()->json(['data' => $this->serialize($result)]);
    }

    /** POST /gift-cards/{giftCard}/void {reason} — cancels the card and zeroes what is left. Cannot be undone. */
    public function void(Request $request, GiftCard $giftCard)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']]);

        $result = DB::transaction(function () use ($request, $giftCard, $data) {
            $card = GiftCard::lockForUpdate()->findOrFail($giftCard->id);
            if ($card->status === 'void') {
                return $this->error('gift_card_void', 'This gift card is already cancelled.');
            }
            $left = (float) $card->balance;
            $this->cards->void($card, $data['reason'], $request->user()->id);
            $this->recordAudit($request, 'giftcard.voided', $card, ['balance_forfeited' => $left, 'reason' => $data['reason'], 'card' => 'GC-'.$card->code_last4]);

            return $card;
        });

        return $result instanceof JsonResponse ? $result : response()->json(['data' => $this->serialize($result)]);
    }

    private function serialize(GiftCard $c): array
    {
        return [
            'id' => $c->id, 'last4' => $c->code_last4, 'initialValue' => (float) $c->initial_value, 'balance' => (float) $c->balance,
            'status' => $c->displayStatus(), 'expiresAt' => $c->expires_at, 'customerId' => $c->customer_id,
            'customerName' => $c->relationLoaded('customer') ? $c->customer?->name : null, 'recipientName' => $c->recipient_name,
            'note' => $c->note, 'soldMethod' => $c->sold_method, 'createdAt' => $c->created_at,
        ];
    }

    private function error(string $code, string $message): JsonResponse
    {
        return response()->json(['code' => $code, 'message' => $message], 422);
    }
}
