<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Services\Ai\AiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Voice mode in the app: what the person said → one action the app can run.
 * The phone understands most commands by itself (also offline); it asks here only
 * when it is not sure. The answer is checked against a fixed list of actions, so
 * nothing the AI says is ever run as-is.
 */
class VoiceController extends Controller
{
    public const INTENTS = [
        'navigate', 'sell_add', 'sell_remove', 'sell_clear', 'sell_total', 'sell_customer', 'sell_pay',
        'customer_add', 'customer_balance', 'orders_list', 'order_open', 'order_status',
        'expense_add', 'purchase_add', 'stock_check', 'sales_today', 'help', 'unknown',
    ];

    public const SCREENS = ['home', 'sell', 'orders', 'products', 'more', 'customers', 'sales', 'settings', 'sync', 'notifications', 'checkout', 'scan'];

    public function __construct(private readonly AiClient $ai) {}

    public function interpret(Request $request): JsonResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:500'],
            'locale' => ['nullable', Rule::in(['en', 'sw'])],
            'screen' => ['nullable', 'string', 'max:40'],
            'products' => ['nullable', 'array', 'max:60'],
            'products.*' => ['string', 'max:120'],
            'customers' => ['nullable', 'array', 'max:30'],
            'customers.*' => ['string', 'max:120'],
        ]);
        if (! $this->ai->configured()) {
            throw new BusinessException(__('Voice help from the server is not available (AI is not set up).'), 'AI_NOT_CONFIGURED', 503);
        }

        $system = $this->instructions();
        $prompt = json_encode([
            'said' => $data['text'],
            'language' => $data['locale'] ?? 'sw',
            'current_screen' => $data['screen'] ?? null,
            'product_names' => array_values($data['products'] ?? []),
            'customer_names' => array_values($data['customers'] ?? []),
        ], JSON_UNESCAPED_UNICODE);

        $raw = $this->ai->text('mobile_voice', $system, $prompt, true, 400);
        $json = $this->firstJsonObject($raw);

        return $this->ok($this->clean($json));
    }

    private function instructions(): string
    {
        $intents = implode(', ', self::INTENTS);
        $screens = implode(', ', self::SCREENS);

        return <<<TXT
You turn one spoken command from a shop worker (Swahili or English, often mixed, from speech recognition so words may be misheard) into ONE action for a point-of-sale app.
Reply with ONLY a JSON object, no other text: {"intent": "...", "args": {...}}.
Allowed intents: {$intents}.
- navigate: args.screen one of {$screens}.
- sell_add: args.items = [{"name": product, "quantity": number, "unit": optional unit word}]. Use the closest name from product_names when one clearly matches.
- sell_remove: args.name. sell_clear, sell_total, sales_today, orders_list, help: no args.
- sell_customer: args.name (closest from customer_names when it matches).
- sell_pay: args.method one of cash, mobile_money, bank, credit; optional args.amount (number), args.reference.
- customer_add: args.name, optional args.phone (digits only). customer_balance: args.name.
- order_open: optional args.number. order_status: args.number and args.status one of confirmed, processing, ready, completed, cancelled.
- expense_add: args.amount (number), args.title (short, what it was for), optional args.method (cash, mobile_money, bank).
- purchase_add: args.items = [{"name", "quantity", "unit_cost"}], optional args.supplier, optional args.paid (true/false).
- stock_check: args.name.
Numbers: convert words to digits ("elfu kumi" = 10000, "mia tano" = 500, "three" = 3, "nusu" = 0.5, "laki moja" = 100000).
"Taslimu/cash/keshi" = cash; "M-Pesa/Tigo Pesa/Airtel Money/simu" = mobile_money; "benki" = bank; "mkopo/deni/credit" = credit.
If the command is not clear or not one of these actions, use {"intent": "unknown", "args": {}}.
TXT;
    }

    private function firstJsonObject(string $raw): array
    {
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start === false || $end === false || $end <= $start) {
            return ['intent' => 'unknown', 'args' => []];
        }
        $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : ['intent' => 'unknown', 'args' => []];
    }

    /** Only known intents and plain values go back to the phone. */
    private function clean(array $json): array
    {
        $intent = in_array($json['intent'] ?? null, self::INTENTS, true) ? $json['intent'] : 'unknown';
        $args = is_array($json['args'] ?? null) ? $json['args'] : [];
        $str = fn ($v, $max = 120) => is_scalar($v) ? mb_substr(trim((string) $v), 0, $max) : null;
        $num = fn ($v) => is_numeric($v) ? (float) $v : null;
        $items = fn ($list) => collect(is_array($list) ? $list : [])->take(20)->map(fn ($i) => is_array($i) ? array_filter([
            'name' => $str($i['name'] ?? null),
            'quantity' => $num($i['quantity'] ?? null),
            'unit' => $str($i['unit'] ?? null, 30),
            'unit_cost' => $num($i['unit_cost'] ?? null),
        ], fn ($v) => $v !== null && $v !== '') : null)->filter(fn ($i) => ! empty($i['name']))->values()->all();

        $clean = match ($intent) {
            'navigate' => ['screen' => in_array($args['screen'] ?? null, self::SCREENS, true) ? $args['screen'] : null],
            'sell_add' => ['items' => $items($args['items'] ?? [])],
            'purchase_add' => ['items' => $items($args['items'] ?? []), 'supplier' => $str($args['supplier'] ?? null), 'paid' => isset($args['paid']) ? (bool) $args['paid'] : null],
            'sell_pay' => ['method' => in_array($args['method'] ?? null, ['cash', 'mobile_money', 'bank', 'credit'], true) ? $args['method'] : 'cash', 'amount' => $num($args['amount'] ?? null), 'reference' => $str($args['reference'] ?? null, 60)],
            'customer_add' => ['name' => $str($args['name'] ?? null), 'phone' => $str(preg_replace('/\D+/', '', (string) ($args['phone'] ?? '')), 20)],
            'order_status' => ['number' => $str($args['number'] ?? null, 40), 'status' => in_array($args['status'] ?? null, ['confirmed', 'processing', 'ready', 'completed', 'cancelled'], true) ? $args['status'] : null],
            'order_open' => ['number' => $str($args['number'] ?? null, 40)],
            'expense_add' => ['amount' => $num($args['amount'] ?? null), 'title' => $str($args['title'] ?? null, 150), 'method' => in_array($args['method'] ?? null, ['cash', 'mobile_money', 'bank'], true) ? $args['method'] : 'cash'],
            'sell_remove', 'sell_customer', 'customer_balance', 'stock_check' => ['name' => $str($args['name'] ?? null)],
            default => [],
        };

        return ['intent' => $intent, 'args' => array_filter($clean, fn ($v) => $v !== null && $v !== '' && $v !== [])];
    }
}
