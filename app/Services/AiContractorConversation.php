<?php

namespace App\Services;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

use function Laravel\Ai\agent;

/**
 * Natural-language chat that collects matcher criteria from free conversation.
 *
 * @phpstan-import-type Draft from ContractorChatGuide
 *
 * @phpstan-type ChatMessage array{role: string, text: string}
 * @phpstan-type ConversationResult array{message: string, draft: Draft, ready: bool}
 */
class AiContractorConversation
{
    public function __construct(private ContractorCatalog $catalog, private ContractorChatGuide $guide) {}

    /**
     * @param  list<ChatMessage>  $messages
     * @param  Draft  $draft
     * @return ConversationResult|null
     */
    public function converse(array $messages, array $draft): ?array
    {
        if (! config('contractors.ai_enabled')) {
            return null;
        }

        $provider = config('contractors.ai_provider');

        if (! config("ai.providers.{$provider}.key") && ! config("ai.providers.{$provider}.url")) {
            return null;
        }

        if ($provider === 'openai-compatible' && blank(config('ai.providers.openai-compatible.key'))) {
            return null;
        }

        if ($provider === 'openai' && blank(config('ai.providers.openai.key'))) {
            return null;
        }

        try {
            $options = $this->catalog->options();
            $locale = app()->getLocale();
            $payload = json_encode([
                'locale' => $locale,
                'known_draft' => $draft,
                'allowed' => $options,
                'date_range' => ['from' => '2026-09-23', 'to' => '2026-12-31'],
                'messages' => array_slice($messages, -6),
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            $response = agent(
                instructions: 'Ты живой консультант сервиса «seelect» по подбору event-подрядчиков в Казахстане. '
                    .'Говори естественно, коротко (1–3 предложения), на языке locale (kk или ru), без канцелярита и без markdown. '
                    .'Собирай только: city, event_format, category, date (YYYY-MM-DD), budget (целое тенге), language, hours. '
                    .'Значения city/event_format/category/language бери строго из allowed. Если пользователь сказал иначе — мягко уточни по списку. '
                    .'Даты только в диапазоне date_range. Не выдумывай подрядчиков, цены и свободные даты. '
                    .'Можно принять несколько полей из одного сообщения. Не спрашивай всё списком сразу — по 1–2 уточнения. '
                    .'ready_to_match=true только когда city, event_format, category, date и budget уже известны и валидны. '
                    .'language и hours необязательны: пустая строка значит «не важно / пропустить». '
                    .'В reply не повторяй JSON и не пиши служебные поля.',
                schema: fn (JsonSchema $schema): array => [
                    'reply' => $schema->string()->required(),
                    'city' => $schema->string()->nullable(),
                    'event_format' => $schema->string()->nullable(),
                    'category' => $schema->string()->nullable(),
                    'date' => $schema->string()->nullable(),
                    'budget' => $schema->string()->nullable(),
                    'language' => $schema->string()->nullable(),
                    'hours' => $schema->string()->nullable(),
                    'ready_to_match' => $schema->boolean()->required(),
                ],
            )->prompt(
                $payload,
                provider: $provider,
                model: config('contractors.ai_model'),
                timeout: config('contractors.chat_timeout', 12),
            );

            if (! $response instanceof StructuredAgentResponse) {
                return null;
            }

            $merged = $draft;
            foreach (['city', 'event_format', 'category', 'date', 'budget', 'language', 'hours'] as $field) {
                $raw = $response[$field] ?? null;
                if (! is_string($raw) || trim($raw) === '') {
                    continue;
                }
                $parsed = $this->guide->normalize($field, $raw);
                if ($parsed['ok']) {
                    $merged = $this->guide->apply($merged, $field, $parsed['value']);
                }
            }

            $reply = trim((string) ($response['reply'] ?? ''));
            if ($reply === '') {
                $reply = __('Хорошо, продолжим.');
            }

            $ready = (bool) ($response['ready_to_match'] ?? false) && $this->guide->isReady($merged);

            if ((bool) ($response['ready_to_match'] ?? false) && ! $this->guide->isReady($merged)) {
                $missing = $this->guide->nextMissing($merged);
                $reply .= ' '.$this->guide->question($missing);
                $ready = false;
            }

            return [
                'message' => $reply,
                'draft' => $merged,
                'ready' => $ready,
            ];
        } catch (Throwable $exception) {
            Log::warning('Contractor NLP chat unavailable; using guided fallback.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
