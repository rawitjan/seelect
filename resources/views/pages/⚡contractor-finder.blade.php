<?php

use App\Services\AiContractorChatParser;
use App\Services\AiContractorConversation;
use App\Services\ContractorCatalog;
use App\Services\ContractorChatGuide;
use App\Services\ContractorMatcher;
use App\Support\ShowcaseCatalog;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

new #[Layout('layouts.finder')] class extends Component {
    #[Locked]
    public string $locale = 'kk';

    public string $city = 'Алматы';
    public string $date = '2026-09-23';
    public string $category = 'Ведущий';
    public string $event_format = 'свадьба';
    public string $budget = '1500000';
    public string $hours = '';
    public string $language = '';

    /** @var array<string, mixed>|null */
    #[Locked]
    public ?array $result = null;

    /** @var array<string, mixed> */
    #[Locked]
    public array $submitted = [];

    public bool $chatOpen = false;
    public string $chatInput = '';
    public string $chatStep = 'city';
    public bool $chatBusy = false;
    public bool $chatNlp = false;

    /** Raw value queued for the async chat turn (chips / free text). */
    #[Locked]
    public string $pendingChatRaw = '';

    /** Whether the queued turn should call the natural-language agent. */
    #[Locked]
    public bool $pendingChatUsesNlp = false;

    /** @var list<array{role: string, text: string}> */
    public array $chatMessages = [];

    /** @var array<string, mixed> */
    public array $chatDraft = [];

    public ?string $detailId = null;
    public string $bookingName = '';
    public string $bookingPhone = '';
    public string $bookingNote = '';
    public bool $bookingSent = false;

    public function mount(): void
    {
        $this->locale = app()->getLocale();
        $this->chatMessages = [
            ['role' => 'assistant', 'text' => __('Привет! Я помогу подобрать подрядчиков из каталога. Расскажите про событие своими словами — город, формат, кого ищете, дату и бюджет.')],
        ];
    }

    public function switchLocale(string $locale, ContractorMatcher $matcher): void
    {
        abort_unless(in_array($locale, ['kk', 'ru'], true), 400);
        session()->put('locale', $locale);
        app()->setLocale($locale);
        $this->locale = $locale;
        $this->resetValidation();

        if ($this->result !== null) {
            foreach ($this->result['contractors'] as &$card) {
                $card['explanation'] = $matcher->explain($this->submitted, $card['profile'], $card['excerpt']);
            }
            unset($card);
        }

        $this->dispatch('locale-changed',
            locale: $locale,
            title: __('seelect — подрядчики для вашего события'),
            description: __('Подберите до трёх подрядчиков для мероприятия по городу, дате, бюджету и формату. С понятными причинами выбора.'),
        );
    }

    /** @return array<string, list<string>> */
    #[Computed]
    public function options(): array
    {
        return app(ContractorCatalog::class)->options();
    }

    /** @return list<array{image: string, title: string, caption: string, category: string, event_format: string, city: string}> */
    #[Computed]
    public function showcase(): array
    {
        return ShowcaseCatalog::items();
    }

    /** @return list<array{value: string, label: string}> */
    #[Computed]
    public function chatChips(): array
    {
        if ($this->chatStep === 'done') {
            return [];
        }

        return app(ContractorChatGuide::class)->chips($this->chatStep);
    }

    /**
     * @return array{profile: array<string, mixed>, explanation: ?string}|null
     */
    #[Computed]
    public function selectedContractor(): ?array
    {
        if ($this->detailId === null) {
            return null;
        }

        if ($this->result !== null) {
            foreach ($this->result['contractors'] as $card) {
                if ($card['profile']['id'] === $this->detailId) {
                    return [
                        'profile' => $card['profile'],
                        'explanation' => $card['explanation'] ?? null,
                    ];
                }
            }
        }

        $profile = app(ContractorCatalog::class)->find($this->detailId);

        if ($profile === null) {
            return null;
        }

        return ['profile' => $profile, 'explanation' => null];
    }

    public function openContractorDetail(string $id): void
    {
        abort_unless(app(ContractorCatalog::class)->find($id) !== null, 404);

        $this->detailId = $id;
        $this->bookingSent = false;
        $this->resetValidation(['bookingName', 'bookingPhone', 'bookingNote']);
        $this->chatOpen = false;
    }

    public function closeContractorDetail(): void
    {
        $this->detailId = null;
        $this->bookingSent = false;
        $this->bookingName = '';
        $this->bookingPhone = '';
        $this->bookingNote = '';
        $this->resetValidation(['bookingName', 'bookingPhone', 'bookingNote']);
    }

    public function submitBooking(): void
    {
        abort_unless($this->detailId !== null, 404);

        $this->validate([
            'bookingName' => ['required', 'string', 'min:2', 'max:80'],
            'bookingPhone' => ['required', 'string', 'min:8', 'max:32'],
            'bookingNote' => ['nullable', 'string', 'max:500'],
        ], [
            'bookingName.required' => __('Укажите ваше имя.'),
            'bookingPhone.required' => __('Укажите телефон для связи.'),
        ], [
            'bookingName' => __('Имя'),
            'bookingPhone' => __('Телефон'),
            'bookingNote' => __('Комментарий'),
        ]);

        $this->bookingSent = true;
    }

    public function openChat(): void
    {
        $this->chatOpen = true;
        $this->detailId = null;
    }

    public function closeChat(): void
    {
        $this->chatOpen = false;
    }

    public function restartChat(): void
    {
        $this->chatStep = 'city';
        $this->chatDraft = [];
        $this->chatInput = '';
        $this->chatBusy = false;
        $this->chatNlp = false;
        $this->pendingChatRaw = '';
        $this->pendingChatUsesNlp = false;
        $this->chatMessages = [
            ['role' => 'assistant', 'text' => __('Давайте сначала. Где и какое событие планируете?')],
        ];
        $this->chatOpen = true;
    }

    public function useShowcase(int $index): void
    {
        $item = ShowcaseCatalog::items()[$index] ?? null;
        abort_unless($item !== null, 404);

        $this->city = $item['city'];
        $this->category = $item['category'];
        $this->event_format = $item['event_format'];
        $this->chatDraft = [
            'city' => $item['city'],
            'category' => $item['category'],
            'event_format' => $item['event_format'],
        ];
        $this->chatStep = 'date';
        $this->openChat();
        $this->chatMessages[] = [
            'role' => 'assistant',
            'text' => __('Открыл подбор по витрине «:title». Осталось уточнить дату и бюджет — напишите, как удобно.', ['title' => __($item['title'])]),
        ];
    }

    public function selectChatChip(string $value, ContractorChatGuide $guide): void
    {
        if ($this->chatStep === 'done' || $this->chatBusy) {
            return;
        }

        $label = collect($guide->chips($this->chatStep))->firstWhere('value', $value)['label'] ?? $value;
        $this->queueChatTurn($label === '' ? __('Пропущено') : (string) $label, $value, false);
    }

    public function sendChatMessage(): void
    {
        $text = trim($this->chatInput);
        if ($text === '' || $this->chatBusy || $this->chatStep === 'done') {
            return;
        }

        $this->queueChatTurn($text, $text, true);
    }

    public function skipChatStep(ContractorChatGuide $guide): void
    {
        abort_unless($guide->isOptional($this->chatStep), 400);

        if ($this->chatBusy || $this->chatStep === 'done') {
            return;
        }

        $this->queueChatTurn(__('Пропущено'), '', false);
    }

    public function completeChatTurn(
        ContractorChatGuide $guide,
        AiContractorChatParser $parser,
        AiContractorConversation $conversation,
        ContractorMatcher $matcher,
    ): void {
        if (! $this->chatBusy || $this->chatStep === 'done') {
            return;
        }

        $rawValue = $this->pendingChatRaw;
        $usesNlp = $this->pendingChatUsesNlp;
        $this->pendingChatRaw = '';
        $this->pendingChatUsesNlp = false;

        try {
            $nlp = $usesNlp ? $conversation->converse($this->chatMessages, $this->chatDraft) : null;

            if ($nlp !== null) {
                $this->chatNlp = true;
                $this->chatDraft = $nlp['draft'];
                $this->chatMessages[] = ['role' => 'assistant', 'text' => $nlp['message']];
                $this->chatStep = $guide->nextMissing($this->chatDraft);

                if ($nlp['ready']) {
                    $this->finishChatMatch($guide, $matcher);
                }

                return;
            }

            $this->acceptGuidedAnswer($rawValue, $guide, $parser, $matcher);
        } finally {
            $this->chatBusy = false;
        }
    }

    public function search(ContractorMatcher $matcher): void
    {
        $options = $this->options;
        $validated = $this->validate([
            'city' => ['required', Rule::in($options['cities'])],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2026-09-23', 'before_or_equal:2026-12-31'],
            'category' => ['required', Rule::in($options['categories'])],
            'event_format' => ['required', Rule::in($options['event_formats'])],
            'budget' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'hours' => ['nullable', 'integer', 'min:1', 'max:24'],
            'language' => ['nullable', Rule::in($options['languages'])],
        ], [
            'required' => __('Заполните поле «:attribute».'),
            'in' => __('Выберите значение из списка «:attribute».'),
            'integer' => __('Поле «:attribute» должно быть целым числом.'),
            'min' => __('Поле «:attribute»: минимум :min.'),
            'max' => __('Поле «:attribute»: максимум :max.'),
            'date.date_format' => __('Введите дату в формате ГГГГ-ММ-ДД.'),
            'date.after_or_equal' => __('Выберите дату с 23 сентября по 31 декабря 2026 года.'),
            'date.before_or_equal' => __('Выберите дату с 23 сентября по 31 декабря 2026 года.'),
        ], [
            'city' => __('Город'), 'date' => __('Дата мероприятия'), 'category' => __('Категория подрядчика'),
            'event_format' => __('Тип мероприятия'), 'budget' => __('Бюджет'), 'hours' => __('Длительность'), 'language' => __('Язык работы'),
        ]);

        $this->submitted = [
            'city' => $validated['city'], 'date' => $validated['date'],
            'category' => $validated['category'], 'event_format' => $validated['event_format'],
            'budget' => (int) $validated['budget'],
            'hours' => filled($validated['hours']) ? (int) $validated['hours'] : null,
            'language' => filled($validated['language']) ? $validated['language'] : null,
        ];
        $this->result = $matcher->match($this->submitted);
    }

    private function queueChatTurn(string $display, string $rawValue, bool $usesNlp): void
    {
        $this->chatBusy = true;
        $this->pendingChatRaw = $rawValue;
        $this->pendingChatUsesNlp = $usesNlp;
        $this->chatMessages[] = ['role' => 'user', 'text' => $display];
        $this->chatInput = '';

        if (app()->runningUnitTests()) {
            $this->completeChatTurn(
                app(ContractorChatGuide::class),
                app(AiContractorChatParser::class),
                app(AiContractorConversation::class),
                app(ContractorMatcher::class),
            );

            return;
        }

        // First request morphs the user bubble + "Думаю…"; then run NLP/guided reply.
        // Capture $wire now — Alpine magics are not available inside queueMicrotask/setTimeout.
        $this->js('const wire = $wire; setTimeout(() => wire.completeChatTurn(), 0)');
    }

    private function acceptGuidedAnswer(string $raw, ContractorChatGuide $guide, AiContractorChatParser $parser, ContractorMatcher $matcher): void
    {
        $parsed = $guide->normalize($this->chatStep, $raw);

        if (! $parsed['ok']) {
            $aiValue = $parser->parse($this->chatStep, $raw, $guide->chips($this->chatStep));
            if ($aiValue !== null) {
                $parsed = $guide->normalize($this->chatStep, $aiValue);
            }
        }

        if (! $parsed['ok']) {
            $this->chatMessages[] = ['role' => 'assistant', 'text' => $parsed['error'] ?? __('Не понял ответ. Выберите вариант ниже.')];

            return;
        }

        $this->chatDraft = $guide->apply($this->chatDraft, $this->chatStep, $parsed['value']);
        $this->chatMessages[] = [
            'role' => 'assistant',
            'text' => __('Принял: :value', ['value' => $guide->displayValue($this->chatStep, $parsed['value'])]),
        ];

        $next = $guide->next($this->chatStep);
        $this->chatStep = $next;

        if ($next === 'done') {
            $this->finishChatMatch($guide, $matcher);

            return;
        }

        $this->chatMessages[] = ['role' => 'assistant', 'text' => $guide->question($next)];
    }

    private function finishChatMatch(ContractorChatGuide $guide, ContractorMatcher $matcher): void
    {
        if (! $guide->isReady($this->chatDraft)) {
            $this->chatStep = $guide->nextMissing($this->chatDraft);
            $this->chatMessages[] = ['role' => 'assistant', 'text' => $guide->question($this->chatStep)];

            return;
        }

        $criteria = $guide->toCriteria($this->chatDraft);
        $this->city = $criteria['city'];
        $this->date = $criteria['date'];
        $this->category = $criteria['category'];
        $this->event_format = $criteria['event_format'];
        $this->budget = (string) $criteria['budget'];
        $this->hours = $criteria['hours'] === null ? '' : (string) $criteria['hours'];
        $this->language = $criteria['language'] ?? '';
        $this->submitted = $criteria;
        $this->result = $matcher->match($criteria);
        $this->chatStep = 'done';
        $count = count($this->result['contractors']);
        $this->chatMessages[] = [
            'role' => 'assistant',
            'text' => $count > 0
                ? __('Готово. Ниже — до трёх вариантов с причинами. Карточки также в разделе результатов.')
                : __('По этим условиям карточек нет. Смотрите пояснение в результатах — можно изменить ответ и начать заново.'),
        ];
        $this->dispatch('chat-finished');
    }
}; ?>

<div class="finder" x-data x-on:locale-changed.window="document.documentElement.lang = $event.detail.locale; document.title = $event.detail.title; document.querySelector('meta[name=description]').content = $event.detail.description">
    <a class="skip-link" href="#finder-form">{{ __('Перейти к подбору') }}</a>
    <header class="site-header shell">
        <a class="wordmark" href="{{ route('home') }}" aria-label="{{ __('seelect — главная') }}"><img src="{{ asset('images/firebird-glyph.svg') }}" width="24" height="31" alt="">seelect</a>
        <div class="header-tools">
            <nav class="language-switch" aria-label="{{ __('Язык интерфейса') }}">
                <button type="button" wire:click="switchLocale('kk')" aria-pressed="{{ $locale === 'kk' ? 'true' : 'false' }}" lang="kk">Қазақша</button>
                <button type="button" wire:click="switchLocale('ru')" aria-pressed="{{ $locale === 'ru' ? 'true' : 'false' }}" lang="ru">Русский</button>
            </nav>
            <button type="button" class="chat-launch" wire:click="openChat" aria-controls="assistant-panel" aria-expanded="{{ $chatOpen ? 'true' : 'false' }}">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v7A2.5 2.5 0 0 1 17.5 16H9l-4 4v-4.5A2.5 2.5 0 0 1 4 13.5v-7Z" stroke="currentColor" stroke-width="1.6"/></svg>
                {{ __('ИИ-чат') }}
            </button>
            <a class="edition" href="#how-it-works">{{ __('Как это работает') }}</a>
        </div>
    </header>

    <main class="shell">
        <section class="hero" aria-labelledby="finder-title">
            <h1 id="finder-title">{{ __('Ваш повод.') }}<br><span class="hero-accent">{{ __('Ваши люди.') }}</span></h1>
            <div class="hero-bottom">
                <p>{{ __('Найдём подрядчиков, которые подходят вашему событию. По дате, бюджету и делу.') }}<br> {{ __('Для свадьбы, большого корпоратива или праздника в кругу самых близких.') }}</p>
                <div class="hero-actions">
                    <button type="button" class="text-link as-button" wire:click="openChat">{{ __('Спросить ИИ') }} <span aria-hidden="true">↗</span></button>
                    <a class="text-link" href="#finder-form">{{ __('Начать подбор') }} <span aria-hidden="true">↘</span></a>
                </div>
            </div>
        </section>

        <section class="finder-specs" aria-label="{{ __('С причинами выбора') }}">
            <div class="finder-spec"><span class="spec-value">AI</span><span>{{ __('С причинами выбора') }}</span></div>
            <div class="finder-spec"><span class="spec-value">03</span><span>{{ __('До 3 рекомендаций') }}</span></div>
            <div class="finder-spec"><span class="spec-value spec-languages">KZ <span>/</span> RU</span><span>{{ __('Язык интерфейса') }}</span></div>
            <div class="finder-spec"><span class="spec-value">₸</span><span>{{ __('Бюджет, ₸') }}</span></div>
        </section>

        <section class="showcase" id="showcase" aria-labelledby="showcase-title" x-data="{ filter: 'all' }">
            <div class="showcase-heading">
                <div>
                    <span class="eyebrow">{{ __('Витрина') }}</span>
                    <h2 id="showcase-title">{{ __('Атмосфера событий.') }}</h2>
                    <p>{{ __('Живые кадры форматов из каталога. Нажмите карточку — откроем ИИ-чат с этими параметрами.') }}</p>
                </div>
                <div class="showcase-filters" role="group" aria-label="{{ __('Фильтр витрины') }}">
                    <button type="button" class="filter-chip" :aria-pressed="filter === 'all'" @click="filter = 'all'">{{ __('Все') }}</button>
                    <button type="button" class="filter-chip" :aria-pressed="filter === 'свадьба'" @click="filter = 'свадьба'">{{ __('свадьба') }}</button>
                    <button type="button" class="filter-chip" :aria-pressed="filter === 'корпоратив'" @click="filter = 'корпоратив'">{{ __('корпоратив') }}</button>
                    <button type="button" class="filter-chip" :aria-pressed="filter === 'той'" @click="filter = 'той'">{{ __('той') }}</button>
                </div>
            </div>
            <div class="showcase-grid">
                @foreach ($this->showcase as $index => $item)
                    <button
                        type="button"
                        wire:key="showcase-{{ $index }}"
                        class="showcase-card"
                        wire:click="useShowcase({{ $index }})"
                        x-show="filter === 'all' || filter === '{{ $item['event_format'] }}'"
                        x-transition.opacity.duration.200ms
                    >
                        <img src="{{ asset($item['image']) }}" alt="{{ __($item['title']) }}" width="640" height="480" loading="{{ $index < 2 ? 'eager' : 'lazy' }}" decoding="async">
                        <span class="showcase-copy">
                            <span class="showcase-meta">{{ __($item['category']) }} · {{ __($item['city']) }}</span>
                            <span class="showcase-name">{{ __($item['title']) }}</span>
                            <span class="showcase-caption">{{ __($item['caption']) }}</span>
                        </span>
                    </button>
                @endforeach
            </div>
        </section>

        <section class="brief-section" id="finder-form" aria-labelledby="brief-title">
            <aside class="section-intro">
                <span class="eyebrow">{{ __('Ваши условия') }}</span>
                <h2 id="brief-title">{{ __('Что планируете?') }}</h2>
                <p>{{ __('Расскажите о событии — мы сузим круг поиска.') }}</p>
                <div class="calendar-note"><span aria-hidden="true">↗</span><div>{{ __('С причинами выбора') }}<strong>{{ __('Календарь каталога: 23 сентября — 31 декабря 2026 года.') }}</strong></div></div>
            </aside>

            <form wire:submit="search" class="brief-form" aria-label="{{ __('Параметры мероприятия') }}" novalidate>
                <p class="form-note">{{ __('Покажем до трёх вариантов и причины выбора') }}</p>
                <fieldset wire:loading.attr="disabled" wire:target="search">
                    <legend class="sr-only">{{ __('Параметры мероприятия') }}</legend>
                    <div class="field-grid">
                        <div class="field">
                            <label for="city">{{ __('Город') }} <span aria-hidden="true">*</span></label>
                            <select id="city" wire:model="city" required aria-invalid="{{ $errors->has('city') ? 'true' : 'false' }}" @error('city') aria-describedby="city-error" @enderror>
                                <option value="">{{ __('Выберите город') }}</option>
                                @foreach ($this->options['cities'] as $option)
                                    <option wire:key="city-{{ $loop->index }}" value="{{ $option }}">{{ __($option) }}</option>
                                @endforeach
                            </select>
                            @error('city')<p class="field-error" id="city-error">{{ $message }}</p>@enderror
                        </div>
                        <div class="field">
                            <label for="event_format">{{ __('Тип мероприятия') }} <span aria-hidden="true">*</span></label>
                            <select id="event_format" wire:model="event_format" required aria-invalid="{{ $errors->has('event_format') ? 'true' : 'false' }}" @error('event_format') aria-describedby="event-format-error" @enderror>
                                <option value="">{{ __('Выберите формат') }}</option>
                                @foreach ($this->options['event_formats'] as $option)
                                    <option wire:key="format-{{ $loop->index }}" value="{{ $option }}">{{ mb_ucfirst(__($option)) }}</option>
                                @endforeach
                            </select>
                            @error('event_format')<p class="field-error" id="event-format-error">{{ $message }}</p>@enderror
                        </div>
                        <div class="field">
                            <label for="category">{{ __('Категория подрядчика') }} <span aria-hidden="true">*</span></label>
                            <select id="category" wire:model="category" required aria-invalid="{{ $errors->has('category') ? 'true' : 'false' }}" @error('category') aria-describedby="category-error" @enderror>
                                <option value="">{{ __('Выберите категорию') }}</option>
                                @foreach ($this->options['categories'] as $option)
                                    <option wire:key="category-{{ $loop->index }}" value="{{ $option }}">{{ __($option) }}</option>
                                @endforeach
                            </select>
                            @error('category')<p class="field-error" id="category-error">{{ $message }}</p>@enderror
                        </div>
                        <div class="field">
                            <label for="date">{{ __('Дата мероприятия') }} <span aria-hidden="true">*</span></label>
                            <input id="date" type="date" wire:model="date" min="2026-09-23" max="2026-12-31" required aria-invalid="{{ $errors->has('date') ? 'true' : 'false' }}" @error('date') aria-describedby="date-error" @enderror>
                            @error('date')<p class="field-error" id="date-error">{{ $message }}</p>@enderror
                        </div>
                        <div class="field field-wide">
                            <label for="budget">{{ __('Бюджет, ₸') }} <span aria-hidden="true">*</span></label>
                            <input id="budget" type="number" wire:model="budget" min="1" max="1000000000" step="1" placeholder="500000" required aria-invalid="{{ $errors->has('budget') ? 'true' : 'false' }}" @error('budget') aria-describedby="budget-error" @enderror>
                            @error('budget')<p class="field-error" id="budget-error">{{ $message }}</p>@enderror
                        </div>
                        <div class="field">
                            <label for="hours">{{ __('Длительность, часы') }} <span class="optional">{{ __('Необязательно') }}</span></label>
                            <input id="hours" type="number" wire:model="hours" min="1" max="24" step="1" placeholder="{{ __('Необязательно') }}" aria-invalid="{{ $errors->has('hours') ? 'true' : 'false' }}" @error('hours') aria-describedby="hours-error" @enderror>
                            @error('hours')<p class="field-error" id="hours-error">{{ $message }}</p>@enderror
                        </div>
                        <div class="field">
                            <label for="language">{{ __('Язык работы') }} <span class="optional">{{ __('Необязательно') }}</span></label>
                            <select id="language" wire:model="language" aria-invalid="{{ $errors->has('language') ? 'true' : 'false' }}" @error('language') aria-describedby="language-error" @enderror>
                                <option value="">{{ __('Любой') }}</option>
                                @foreach ($this->options['languages'] as $option)
                                    <option wire:key="language-{{ $loop->index }}" value="{{ $option }}">{{ mb_ucfirst(__($option)) }}</option>
                                @endforeach
                            </select>
                            @error('language')<p class="field-error" id="language-error">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <button class="submit-button" type="submit" wire:loading.attr="disabled" wire:target="search">
                        <span wire:loading.remove wire:target="search">{{ __('Подобрать подрядчиков') }}</span>
                        <span wire:loading wire:target="search">{{ __('Подбираем варианты…') }}</span>
                        <span aria-hidden="true">↗</span>
                    </button>
                </fieldset>
            </form>
        </section>

        <section class="results-section" id="results-anchor" aria-live="polite" aria-atomic="true" aria-label="{{ __('Результаты подбора') }}">
            <span class="eyebrow">{{ __('Ваша подборка') }}</span>
            <p wire:loading wire:target="search" role="status">{{ __('Проверяем доступность и подбираем варианты…') }}</p>
            <div wire:loading.remove wire:target="search">
                @if ($result)
                    <h2>{{ match ($result['status']) { 'matched' => __('Есть совпадение.'), 'no_category' => __('Пока нет в каталоге.'), default => __('Нужны другие условия.') } }}</h2>
                    <p class="result-context">{{ __($submitted['city']) }} · {{ __($submitted['category']) }} · {{ \Carbon\CarbonImmutable::parse($submitted['date'])->format('d.m.Y') }}</p>
                    <p wire:dirty class="dirty-notice">{{ __('Параметры изменены. Нажмите «Подобрать подрядчиков», чтобы обновить результаты.') }}</p>
                    @if ($result['status'] === 'no_category')
                        <div class="empty-state">
                            <span class="empty-symbol" aria-hidden="true">↗</span>
                            <div>
                                <h3>{{ __('Пока нет в каталоге.') }}</h3>
                                <p>{{ __('В городе «:city» нет подрядчиков категории «:category» в имеющемся каталоге.', ['city' => __($submitted['city']), 'category' => __($submitted['category'])]) }}</p>
                                <p>{{ __('Попробуйте выбрать другой город или категорию.') }}</p>
                            </div>
                        </div>
                    @else
                        <p class="result-message">{{ __('В городе и категории: :total. Подходят всем условиям: :eligible. Показываем: :shown.', ['total' => $result['total'], 'eligible' => $result['eligible'], 'shown' => count($result['contractors'])]) }}</p>
                        @if ($result['eligible'] > 0 && $result['eligible'] < 3)
                            <p class="result-message">{{ __('В каталоге нашлось меньше трёх подходящих вариантов.') }}</p>
                        @elseif ($result['eligible'] === 0)
                            <div class="empty-state">
                                <span class="empty-symbol" aria-hidden="true">↗</span>
                                <div>
                                    <h3>{{ __('Нужны другие условия.') }}</h3>
                                    <p>{{ __('Кандидаты есть, но ни один не прошёл все условия. Попробуйте изменить дату, бюджет или дополнительные параметры.') }}</p>
                                </div>
                            </div>
                        @endif
                        <div class="cards">
                            @foreach ($result['contractors'] as $card)
                                @php($profile = $card['profile'])
                                <article wire:key="contractor-{{ $profile['id'] }}" class="contractor-card">
                                    <button type="button" class="card-photo-btn" wire:click="openContractorDetail({{ \Illuminate\Support\Js::from($profile['id']) }})" aria-label="{{ __('Подробнее о :name', ['name' => $profile['anon_name']]) }}">
                                        <img class="card-photo" src="{{ asset($profile['photo']) }}" alt="{{ $profile['anon_name'] }}" width="480" height="640" loading="lazy" decoding="async">
                                    </button>
                                    <div class="card-top"><span class="eyebrow">0{{ $loop->iteration }}</span><span aria-hidden="true">↗</span></div>
                                    @if ($profile['synthetic'])
                                        <p class="synthetic-badge">{{ __('Синтетический профиль') }}</p>
                                    @endif
                                    <h3>{{ $profile['anon_name'] }}</h3>
                                    <p class="card-meta">{{ implode(' · ', array_map(fn ($category) => __($category), $profile['categories'])) }} · {{ __($profile['city']) }}</p>
                                    <p class="price">{{ __('от :price ₸', ['price' => number_format($profile['price_from_kzt'], 0, '.', ' ')]) }}</p>
                                    <div class="explanation">
                                        <h4>{{ __('Почему подходит') }}</h4>
                                        <p>{{ $card['explanation'] }}</p>
                                    </div>
                                    <p class="card-footnote">{{ __('По календарю каталога дата свободна. Итоговую цену и доступность нужно подтвердить у подрядчика.') }}</p>
                                    @if ($profile['city_imputed'] || $profile['price_imputed'])
                                        <p class="imputed">{{ $profile['city_imputed'] ? __('Город восстановлен в датасете. ') : '' }}{{ $profile['price_imputed'] ? __('Цена оценочная из датасета.') : '' }}</p>
                                    @endif
                                    <div class="card-actions">
                                        <button type="button" class="text-link as-button" wire:click="openContractorDetail({{ \Illuminate\Support\Js::from($profile['id']) }})">{{ __('Подробнее') }} <span aria-hidden="true">↗</span></button>
                                        <button type="button" class="card-book" wire:click="openContractorDetail({{ \Illuminate\Support\Js::from($profile['id']) }})">{{ __('Записаться') }}</button>
                                    </div>
                                </article>
                            @endforeach
                        </div>
                        @if (array_sum($result['reasons']) > 0)
                            <div class="reasons">
                                <h3>{{ __('Почему подошли не все') }}</h3>
                                <ul>
                                    @foreach (['busy' => __('Заняты на дату'), 'budget' => __('Цена выше бюджета'), 'format' => __('Не указан нужный формат'), 'language' => __('Не указан нужный язык'), 'hours' => __('Превышен лимит часов')] as $reason => $label)
                                        @if ($result['reasons'][$reason] > 0)
                                            <li wire:key="reason-{{ $reason }}">{{ $label }}: <strong>{{ $result['reasons'][$reason] }}</strong></li>
                                        @endif
                                    @endforeach
                                </ul>
                                <p>{{ __('Причины могут пересекаться: один профиль может не подходить по нескольким условиям.') }}</p>
                            </div>
                        @endif
                        @if ($result['eligible'] > 0)
                            <p class="ranking-note">{{ $result['ranking'] === 'ai' ? __('Порядок и цитаты подобраны ИИ. Дата, цена и условия проверены по каталогу.') : __('Подбор по правилам: сначала меньшая цена, при равной цене — ID профиля. Объяснения основаны на каталоге.') }}</p>
                        @endif
                    @endif
                @else
                    <h2>{{ __('Подбор с объяснением.') }}</h2>
                    <div class="empty-state">
                        <span class="empty-symbol" aria-hidden="true">✳</span>
                        <div>
                            <h3>{{ __('Здесь появятся ваши варианты') }}</h3>
                            <p>{{ __('Заполните форму выше. Для каждого результата расскажем, почему он подходит вашему событию.') }}</p>
                        </div>
                    </div>
                @endif
            </div>
        </section>

        <section class="roadmap-section" aria-labelledby="roadmap-title">
            <div class="roadmap-intro">
                <p class="eyebrow">{{ __('Скоро в seelect') }}</p>
                <h2 id="roadmap-title">{{ __('Инструменты, которые помогают специалистам расти.') }}</h2>
                <p>{{ __('Мы создаём рабочее пространство для агентств и независимых специалистов: от первого обращения до сайта, который приводит новых клиентов.') }}</p>
            </div>
            <div class="roadmap-grid">
                <article class="roadmap-feature roadmap-feature-primary">
                    <p class="roadmap-status">{{ __('В разработке') }}</p>
                    <span class="roadmap-number">01</span>
                    <h3>{{ __('CRM для агентств и специалистов') }}</h3>
                    <p>{{ __('Заявки, бронирования, календарь, команда и клиентская история в одном понятном рабочем пространстве.') }}</p>
                    <div class="roadmap-rail" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
                </article>
                <article class="roadmap-feature roadmap-feature-secondary">
                    <p class="roadmap-status">{{ __('В разработке') }}</p>
                    <span class="roadmap-number">02</span>
                    <h3>{{ __('Ваш сайт под вашим брендом') }}</h3>
                    <p>{{ __('White-label сайт агентства или специалиста с услугами и рекомендациями из вашего собственного каталога.') }}</p>
                    <div class="roadmap-site-preview" aria-hidden="true">
                        <span></span><span></span><span></span>
                    </div>
                </article>
            </div>
        </section>

        <section id="how-it-works" class="process" aria-labelledby="process-title">
            <span class="eyebrow">{{ __('Как это работает') }}</span>
            <h2 id="process-title">{{ __('Меньше поиска.') }}<br><em>{{ __('Больше повода.') }}</em></h2>
            <div class="process-steps">
                @foreach ([['01', __('Ваши условия'), __('Укажите город, дату и бюджет. Язык и длительность — по желанию.')], ['02', __('Честный отбор'), __('Проверяем занятость и условия по каталогу. Если вариантов нет, объясним почему.')], ['03', __('Понятный выбор'), __('До трёх профилей с фактами и описанием, чтобы проще было сравнить.')]] as [$number, $heading, $text])
                    <div wire:key="step-{{ $number }}" class="process-step">
                        <span>{{ $number }}</span>
                        <h3>{{ $heading }}</h3>
                        <p>{{ $text }}</p>
                    </div>
                @endforeach
            </div>
        </section>
    </main>
    <footer class="site-footer shell">
        <a class="wordmark" href="{{ route('home') }}" aria-label="{{ __('seelect — главная') }}"><img src="{{ asset('images/firebird-glyph.svg') }}" width="24" height="31" alt="">seelect</a>
        <p>{{ __('seelect / Каталог для вашего события') }}</p>
        <span class="edition">{{ __('Демо · Осень — зима 2026 · Заявка на запись') }}</span>
    </footer>
    <div class="brand-signoff shell" aria-hidden="true"><span>seelect</span><img src="{{ asset('images/firebird-glyph.svg') }}" width="218" height="284" alt="" loading="lazy"></div>

    <div
        id="assistant-panel"
        class="chat-panel"
        role="dialog"
        aria-modal="true"
        aria-labelledby="chat-title"
        @if (! $chatOpen) hidden @endif
        x-data
        x-on:chat-finished.window="$el.querySelector('.chat-log')?.scrollTo({ top: 99999, behavior: 'smooth' })"
    >
        <div class="chat-scrim" wire:click="closeChat" aria-hidden="true"></div>
        <div class="chat-sheet">
            <header class="chat-header">
                <div>
                    <p class="eyebrow" id="chat-title">{{ __('ИИ-помощник') }}</p>
                    <p class="chat-subtitle">{{ __('Живой диалог на Alem qwen3-8. В конце — до трёх карточек.') }}</p>
                </div>
                <div class="chat-header-actions">
                    <button type="button" class="chat-icon-btn" wire:click="restartChat" aria-label="{{ __('Начать заново') }}">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 12a8 8 0 0 1 13.7-5.7M20 12a8 8 0 0 1-13.7 5.7" stroke="currentColor" stroke-width="1.6"/><path d="M18 3v5h-5M6 21v-5h5" stroke="currentColor" stroke-width="1.6"/></svg>
                    </button>
                    <button type="button" class="chat-icon-btn" wire:click="closeChat" aria-label="{{ __('Закрыть чат') }}">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" stroke="currentColor" stroke-width="1.6"/></svg>
                    </button>
                </div>
            </header>

            <div class="chat-log" role="log" aria-live="polite" aria-relevant="additions">
                @foreach ($chatMessages as $index => $message)
                    <div wire:key="chat-msg-{{ $index }}" class="chat-bubble chat-{{ $message['role'] }}">{{ $message['text'] }}</div>
                @endforeach
                @if ($chatBusy)
                    <div class="chat-bubble chat-assistant chat-typing" role="status">{{ __('Думаю…') }}</div>
                @endif
            </div>

            @if ($chatStep !== 'done')
                <div class="chat-chips" role="group" aria-label="{{ __('Быстрые ответы') }}">
                    @foreach ($this->chatChips as $chip)
                        <button type="button" wire:key="chip-{{ $chatStep }}-{{ $chip['value'] !== '' ? $chip['value'] : 'empty' }}" class="chip" wire:click="selectChatChip({{ \Illuminate\Support\Js::from($chip['value']) }})" wire:loading.attr="disabled" wire:target="selectChatChip, sendChatMessage, skipChatStep, completeChatTurn">{{ $chip['label'] }}</button>
                    @endforeach
                    @if (in_array($chatStep, ['language', 'hours'], true))
                        <button type="button" class="chip chip-skip" wire:click="skipChatStep" wire:loading.attr="disabled" wire:target="selectChatChip, sendChatMessage, skipChatStep, completeChatTurn">{{ __('Пропустить') }}</button>
                    @endif
                </div>
                <form wire:submit="sendChatMessage" class="chat-compose">
                    <label class="sr-only" for="chat-input">{{ __('Ваш ответ') }}</label>
                    <input
                        id="chat-input"
                        type="text"
                        wire:model.live="chatInput"
                        autocomplete="off"
                        placeholder="{{ __('Напишите, как другу…') }}"
                        @disabled($chatBusy)
                        wire:loading.attr="disabled"
                        wire:target="selectChatChip, sendChatMessage, skipChatStep, completeChatTurn"
                    >
                    <button type="submit" class="chat-send" wire:loading.attr="disabled" wire:target="selectChatChip, sendChatMessage, skipChatStep, completeChatTurn" aria-label="{{ __('Отправить') }}">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 11.5 19 4l-4.5 16L11 13z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>
                    </button>
                </form>
            @else
                <div class="chat-done-actions">
                    <a class="submit-button chat-done-link" href="#results-anchor" wire:click="closeChat">{{ __('Смотреть результаты') }} <span aria-hidden="true">↘</span></a>
                    <button type="button" class="chip" wire:click="restartChat">{{ __('Начать заново') }}</button>
                </div>
            @endif
        </div>
    </div>

    @php($selected = $this->selectedContractor)
    <div
        id="contractor-detail"
        class="detail-panel"
        role="dialog"
        aria-modal="true"
        aria-labelledby="detail-title"
        @if ($detailId === null || $selected === null) hidden @endif
    >
        <div class="chat-scrim" wire:click="closeContractorDetail" aria-hidden="true"></div>
        @if ($selected !== null)
            @php($profile = $selected['profile'])
            <div class="detail-sheet" wire:key="detail-{{ $profile['id'] }}">
                <header class="chat-header">
                    <div>
                        <p class="eyebrow" id="detail-title">{{ __('Профиль специалиста') }}</p>
                        <p class="chat-subtitle">{{ $profile['id'] }} · {{ __($profile['city']) }}</p>
                    </div>
                    <div class="chat-header-actions">
                        <button type="button" class="chat-icon-btn" wire:click="closeContractorDetail" aria-label="{{ __('Закрыть') }}">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" stroke="currentColor" stroke-width="1.6"/></svg>
                        </button>
                    </div>
                </header>

                <div class="detail-body">
                    <img class="detail-photo" src="{{ asset($profile['photo']) }}" alt="{{ $profile['anon_name'] }}" width="640" height="800" loading="lazy" decoding="async">
                    <div class="detail-copy">
                        <h2>{{ $profile['anon_name'] }}</h2>
                        <p class="card-meta">{{ implode(' · ', array_map(fn ($category) => __($category), $profile['categories'])) }}</p>
                        <p class="price">{{ __('от :price ₸', ['price' => number_format($profile['price_from_kzt'], 0, '.', ' ')]) }}</p>

                        @if ($selected['explanation'])
                            <div class="explanation">
                                <h4>{{ __('Почему подходит') }}</h4>
                                <p>{{ $selected['explanation'] }}</p>
                            </div>
                        @endif

                        <div class="detail-facts">
                            <div>
                                <h4>{{ __('Форматы') }}</h4>
                                <p>{{ implode(', ', array_map(fn ($format) => __($format), $profile['event_formats'])) }}</p>
                            </div>
                            <div>
                                <h4>{{ __('Языки') }}</h4>
                                <p>{{ $profile['languages'] === [] ? __('Любой') : implode(', ', array_map(fn ($lang) => __($lang), $profile['languages'])) }}</p>
                            </div>
                            <div>
                                <h4>{{ __('Длительность') }}</h4>
                                <p>{{ $profile['max_hours'] === null ? __('Уточняется') : __(':hours ч', ['hours' => $profile['max_hours']]) }}</p>
                            </div>
                            @if ($submitted !== [])
                                <div>
                                    <h4>{{ __('Дата мероприятия') }}</h4>
                                    <p>{{ \Carbon\CarbonImmutable::parse($submitted['date'])->format('d.m.Y') }}</p>
                                </div>
                            @endif
                        </div>

                        <div class="detail-description">
                            <h4>{{ __('О специалисте') }}</h4>
                            <p>{{ $profile['description'] }}</p>
                        </div>

                        @if ($bookingSent)
                            <div class="booking-success" role="status">
                                <h3>{{ __('Заявка отправлена') }}</h3>
                                <p>{{ __('Мы передадим :name ваши контакты. Специалист свяжется с вами для подтверждения.', ['name' => $profile['anon_name']]) }}</p>
                                <button type="button" class="chip" wire:click="closeContractorDetail">{{ __('Закрыть') }}</button>
                            </div>
                        @else
                            <form wire:submit="submitBooking" class="booking-form" aria-label="{{ __('Запись к специалисту') }}">
                                <h3>{{ __('Записаться') }}</h3>
                                <p class="form-note">{{ __('Оставьте контакты — специалист подтвердит дату и условия.') }}</p>
                                <div class="field">
                                    <label for="booking-name">{{ __('Имя') }} <span aria-hidden="true">*</span></label>
                                    <input id="booking-name" type="text" wire:model="bookingName" autocomplete="name" required @error('bookingName') aria-invalid="true" aria-describedby="booking-name-error" @enderror>
                                    @error('bookingName') <p id="booking-name-error" class="field-error">{{ $message }}</p> @enderror
                                </div>
                                <div class="field">
                                    <label for="booking-phone">{{ __('Телефон') }} <span aria-hidden="true">*</span></label>
                                    <input id="booking-phone" type="tel" wire:model="bookingPhone" autocomplete="tel" required placeholder="+7 …" @error('bookingPhone') aria-invalid="true" aria-describedby="booking-phone-error" @enderror>
                                    @error('bookingPhone') <p id="booking-phone-error" class="field-error">{{ $message }}</p> @enderror
                                </div>
                                <div class="field">
                                    <label for="booking-note">{{ __('Комментарий') }}</label>
                                    <textarea id="booking-note" wire:model="bookingNote" rows="3" maxlength="500" placeholder="{{ __('Пожелания к формату или времени') }}"></textarea>
                                </div>
                                <button class="submit-button" type="submit">{{ __('Отправить заявку') }} <span aria-hidden="true">↗</span></button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
