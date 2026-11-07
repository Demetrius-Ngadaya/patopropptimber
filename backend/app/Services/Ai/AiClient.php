<?php

namespace App\Services\Ai;

use App\Exceptions\BusinessException;
use App\Models\Ai\AiUsage;
use App\Services\Core\SettingsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * One client for every language-model provider (see config/ai.php).
 *
 * Callers always speak a single message format (content blocks of type
 * "text", "tool_use" and "tool_result"); this class translates to and from
 * the provider's wire format, so the assistant works the same on Groq,
 * Gemini, OpenRouter, a local Ollama or Claude. It also enforces the monthly
 * token budget and records the usage of every call. Keys never leave the
 * server and are never logged.
 */
class AiClient
{
    public function __construct(private readonly SettingsService $settings) {}

    public function provider(): string
    {
        return (string) config('ai.provider');
    }

    /** Human-readable "Groq · openai/gpt-oss-120b" for the status screen. */
    public function label(): string
    {
        return ($this->config()['label'] ?? $this->provider()).' · '.$this->model(false);
    }

    public function configured(): bool
    {
        $config = $this->config();
        if (! $config || ! $this->settings->bool('ai_enabled')) {
            return false;
        }

        return ! ($config['key_required'] ?? true) || filled($config['key'] ?? null);
    }

    public function model(bool $fast): string
    {
        $config = $this->config();

        return (string) ($fast
            // AI_FAST_MODEL, else AI_MODEL (one downloaded Ollama model is common), else the provider default.
            ? (config('ai.fast_model') ?: config('ai.model') ?: ($config['fast_model'] ?? $config['model'] ?? ''))
            : (config('ai.model') ?: ($config['model'] ?? '')));
    }

    /**
     * @param  array<int, array{role: string, content: mixed}>  $messages  text, tool_use and tool_result blocks
     * @param  array<int, array{name: string, description: string, input_schema: array}>  $tools
     * @return array{content: array, stop_reason: ?string, usage: array{input_tokens: int, output_tokens: int}}
     */
    public function messages(string $feature, string $system, array $messages, array $tools = [], ?string $model = null, ?int $maxTokens = null): array
    {
        if (! $this->configured()) {
            throw new BusinessException(__('AI is not set up. Choose AI_PROVIDER and add its API key to the server configuration.'), 'AI_NOT_CONFIGURED', 503);
        }
        $this->assertBudget();

        $model ??= $this->model(false);
        $maxTokens ??= (int) config('ai.max_tokens');
        $anthropic = ($this->config()['driver'] ?? 'openai') === 'anthropic';
        $usageLabel = $this->provider().':'.$model;

        $started = microtime(true);
        try {
            $response = $anthropic
                ? $this->request()->post('/v1/messages', $this->anthropicPayload($system, $messages, $tools, $model, $maxTokens))
                : $this->request()->post('/chat/completions', $this->openAiPayload($system, $messages, $tools, $model, $maxTokens));
        } catch (ConnectionException|RequestException) {
            $this->record($feature, $usageLabel, 0, 0, $started, false, 'connection');
            throw new BusinessException(__('The AI service could not be reached. Check the internet connection and try again.'), 'AI_UNAVAILABLE', 503);
        }

        if ($response->failed()) {
            $this->fail($response, $feature, $usageLabel, $started);
        }

        $result = $anthropic ? $this->fromAnthropic($response) : $this->fromOpenAi($response);
        $this->record($feature, $usageLabel, $result['usage']['input_tokens'], $result['usage']['output_tokens'], $started, true);

        return $result;
    }

    /** Convenience for one-shot text generation. */
    public function text(string $feature, string $system, string $prompt, bool $fast = true, int $maxTokens = 1024): string
    {
        $result = $this->messages($feature, $system, [['role' => 'user', 'content' => $prompt]], [], $this->model($fast), $maxTokens);

        return trim(collect($result['content'])->where('type', 'text')->pluck('text')->implode("\n"));
    }

    public function monthUsage(): int
    {
        return (int) AiUsage::query()->where('created_at', '>=', now()->startOfMonth())->sum(DB::raw('input_tokens + output_tokens'));
    }

    // ------------------------------------------------------------------ transport

    private function config(): array
    {
        return (array) config('ai.providers.'.$this->provider(), []);
    }

    private function request(): PendingRequest
    {
        $config = $this->config();
        $request = Http::baseUrl(rtrim((string) $config['base_url'], '/'))
            ->acceptJson()
            ->timeout((int) config('ai.timeout'))
            // Free tiers answer 429 when busy: a short back-off usually gets through.
            ->retry(2, 1000, fn ($e) => $e instanceof ConnectionException || in_array(optional($e->response ?? null)->status(), [429, 500, 502, 503, 529], true), throw: false);

        if (($config['driver'] ?? 'openai') === 'anthropic') {
            return $request->withHeaders(['x-api-key' => (string) $config['key'], 'anthropic-version' => (string) ($config['version'] ?? '2023-06-01')]);
        }
        if (filled($config['key'] ?? null)) {
            $request = $request->withToken((string) $config['key']);
        }

        return $this->provider() === 'openrouter'
            ? $request->withHeaders(['X-Title' => (string) config('mauzo.brand.product'), 'HTTP-Referer' => (string) config('app.url')])
            : $request;
    }

    private function fail(Response $response, string $feature, string $model, float $started): never
    {
        $type = (string) ($response->json('error.type') ?? $response->json('error.code') ?? 'http_'.$response->status());
        $this->record($feature, $model, 0, 0, $started, false, $type);
        Log::warning('AI request failed', ['provider' => $this->provider(), 'feature' => $feature, 'status' => $response->status(), 'type' => $type]);

        throw match (true) {
            in_array($response->status(), [401, 403], true) => new BusinessException(__('The AI key was rejected. Check the API key for the chosen AI provider.'), 'AI_AUTH', 503),
            $response->status() === 429 => new BusinessException(__('The AI service is busy or the free daily limit is used up. Try again later.'), 'AI_RATE_LIMITED', 429),
            $response->status() === 404 => new BusinessException(__('The AI model was not found. Check AI_MODEL for the chosen provider.'), 'AI_MODEL', 502),
            default => new BusinessException(__('The AI service returned an error. Try again later.'), 'AI_ERROR', 502),
        };
    }

    // ------------------------------------------------------------------ Anthropic format

    private function anthropicPayload(string $system, array $messages, array $tools, string $model, int $maxTokens): array
    {
        return array_filter([
            'model' => $model,
            'max_tokens' => $maxTokens,
            'system' => $system,
            'messages' => $messages,
            'tools' => $tools ?: null,
        ], fn ($v) => $v !== null);
    }

    private function fromAnthropic(Response $response): array
    {
        return [
            'content' => (array) $response->json('content', []),
            'stop_reason' => $response->json('stop_reason'),
            'usage' => ['input_tokens' => (int) $response->json('usage.input_tokens', 0), 'output_tokens' => (int) $response->json('usage.output_tokens', 0)],
        ];
    }

    // ------------------------------------------------------------------ OpenAI-compatible format

    private function openAiPayload(string $system, array $messages, array $tools, string $model, int $maxTokens): array
    {
        $out = [['role' => 'system', 'content' => $system]];
        foreach ($messages as $message) {
            $content = $message['content'];
            if (is_string($content)) {
                $out[] = ['role' => $message['role'], 'content' => $content];

                continue;
            }
            $blocks = collect($content);
            $text = $blocks->where('type', 'text')->pluck('text')->implode("\n");

            if ($message['role'] === 'assistant') {
                $calls = $blocks->where('type', 'tool_use')->map(fn ($b) => [
                    'id' => $b['id'],
                    'type' => 'function',
                    'function' => ['name' => $b['name'], 'arguments' => json_encode((object) ($b['input'] ?? []), JSON_UNESCAPED_UNICODE)],
                ])->values()->all();
                $out[] = array_filter(['role' => 'assistant', 'content' => $text !== '' ? $text : null, 'tool_calls' => $calls ?: null], fn ($v) => $v !== null) + ['content' => null];

                continue;
            }
            foreach ($blocks->where('type', 'tool_result') as $result) {
                $body = is_string($result['content']) ? $result['content'] : json_encode($result['content'], JSON_UNESCAPED_UNICODE);
                $out[] = ['role' => 'tool', 'tool_call_id' => $result['tool_use_id'], 'content' => $body];
            }
            if ($text !== '') {
                $out[] = ['role' => 'user', 'content' => $text];
            }
        }

        return array_filter([
            'model' => $model,
            'max_tokens' => $maxTokens,
            'messages' => $out,
            'tools' => $tools ? array_map(fn ($t) => ['type' => 'function', 'function' => [
                'name' => $t['name'],
                'description' => $t['description'],
                'parameters' => $t['input_schema'] ?: ['type' => 'object', 'properties' => new \stdClass],
            ]], $tools) : null,
            'tool_choice' => $tools ? 'auto' : null,
        ], fn ($v) => $v !== null);
    }

    private function fromOpenAi(Response $response): array
    {
        $message = (array) $response->json('choices.0.message', []);
        $content = [];
        $text = trim((string) ($message['content'] ?? ''));
        if ($text !== '') {
            $content[] = ['type' => 'text', 'text' => $this->stripThinking($text)];
        }
        foreach ((array) ($message['tool_calls'] ?? []) as $i => $call) {
            $args = json_decode((string) ($call['function']['arguments'] ?? '{}'), true);
            $content[] = [
                'type' => 'tool_use',
                'id' => (string) ($call['id'] ?? 'call_'.$i),
                'name' => (string) ($call['function']['name'] ?? ''),
                'input' => is_array($args) ? $args : [],
            ];
        }
        $hasCalls = collect($content)->contains('type', 'tool_use');

        return [
            'content' => $content,
            // Some providers report "stop" even when they ask for tools; the calls themselves are what count.
            'stop_reason' => $hasCalls ? 'tool_use' : (($response->json('choices.0.finish_reason') === 'length') ? 'max_tokens' : 'end_turn'),
            'usage' => ['input_tokens' => (int) $response->json('usage.prompt_tokens', 0), 'output_tokens' => (int) $response->json('usage.completion_tokens', 0)],
        ];
    }

    /** Open "reasoning" models sometimes return their scratch work in <think> tags; users only need the answer. */
    private function stripThinking(string $text): string
    {
        return trim((string) preg_replace('/<think>.*?<\/think>/s', '', $text));
    }

    // ------------------------------------------------------------------ budget & usage

    private function assertBudget(): void
    {
        $budget = $this->settings->int('ai_monthly_token_budget');
        if ($budget > 0 && $this->monthUsage() >= $budget) {
            throw new BusinessException(__('This month\'s AI budget has been used up. An administrator can raise it in Settings.'), 'AI_BUDGET_EXCEEDED', 429);
        }
    }

    private function record(string $feature, string $model, int $in, int $out, float $started, bool $ok, ?string $error = null): void
    {
        AiUsage::create([
            'user_id' => Auth::id(),
            'feature' => $feature,
            'model' => mb_substr($model, 0, 80),
            'input_tokens' => $in,
            'output_tokens' => $out,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'success' => $ok,
            'error' => $error ? mb_substr($error, 0, 255) : null,
        ]);
    }
}
