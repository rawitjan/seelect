<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    config(['contractors.ai_enabled' => false]);
    session()->put('locale', 'ru');
    app()->setLocale('ru');
});

it('serves a public finder and searches without database queries', function () {
    DB::listen(fn () => throw new RuntimeException('The finder must not query a database.'));

    $this->get(route('home'))->assertOk()
        ->assertSee('Ваш повод.')
        ->assertSee('Подобрать подрядчиков')
        ->assertSee('CRM для агентств и специалистов')
        ->assertSee('В разработке');
    Livewire::test('pages::contractor-finder')
        ->call('search')
        ->assertHasNoErrors()
        ->assertSet('result.status', 'matched')
        ->assertCount('result.contractors', 3)
        ->assertSee('Мицури Канроджи')
        ->assertSee('Почему подходит')
        ->assertSet('budget', '1500000')
        ->set('date', '2026-09-24')
        ->call('search')
        ->assertCount('result.contractors', 1)
        ->assertSee('Кики')
        ->assertSee('В каталоге нашлось меньше трёх подходящих вариантов.');
});

it('shows distinct empty states and actual exclusion reasons', function () {
    Livewire::test('pages::contractor-finder')
        ->set('city', 'Зарубежье')
        ->set('category', 'Флорист')
        ->call('search')
        ->assertSet('result.status', 'no_category')
        ->assertSee('Пока нет в каталоге.')
        ->set('city', 'Алматы')
        ->set('budget', '1')
        ->call('search')
        ->assertSet('result.status', 'no_matches')
        ->assertSee('Нужны другие условия.')
        ->assertSee('Цена выше бюджета');
});

it('marks synthetic profiles and shows a real pair of florists', function () {
    Livewire::test('pages::contractor-finder')
        ->set('category', 'Флорист')
        ->set('budget', '500000')
        ->call('search')
        ->assertCount('result.contractors', 2)
        ->assertSee('Тони Тони Чоппер')
        ->assertSee('Тихиро Огино')
        ->assertSee('Синтетический профиль')
        ->assertSee('Цена оценочная из датасета.');
});

it('validates all required fields', function () {
    Livewire::test('pages::contractor-finder')
        ->set('city', '')->set('date', '')->set('category', '')->set('event_format', '')->set('budget', '')
        ->call('search')
        ->assertHasErrors(['city', 'date', 'category', 'event_format', 'budget'])
        ->assertSee('Заполните поле «Бюджет».')
        ->assertSet('result', null);
});

it('keeps the previous cards when a later search fails validation', function () {
    Livewire::test('pages::contractor-finder')
        ->call('search')
        ->assertSet('result.status', 'matched')
        ->set('date', '2026-09-22')
        ->call('search')
        ->assertHasErrors(['date' => 'Выберите дату с 23 сентября по 31 декабря 2026 года.'])
        ->assertSee('Выберите дату с 23 сентября по 31 декабря 2026 года.')
        ->assertSet('result.status', 'matched')
        ->assertCount('result.contractors', 3)
        ->assertSee('Мицури Канроджи');
});

it('rejects invalid parameters with a useful inline message', function (string $field, string $value, string $message) {
    Livewire::test('pages::contractor-finder')
        ->set($field, $value)
        ->call('search')
        ->assertHasErrors([$field])
        ->assertSee($message)
        ->assertSet('result', null);
})->with([
    ['city', 'Москва', 'Выберите значение из списка «Город».'],
    ['category', 'Несуществующая', 'Выберите значение из списка «Категория подрядчика».'],
    ['event_format', 'Несуществующий', 'Выберите значение из списка «Тип мероприятия».'],
    ['language', 'французский', 'Выберите значение из списка «Язык работы».'],
    ['date', '2026-09-22', 'Выберите дату с 23 сентября по 31 декабря 2026 года.'],
    ['date', '2027-01-01', 'Выберите дату с 23 сентября по 31 декабря 2026 года.'],
    ['date', '2026-02-30', 'Введите дату в формате ГГГГ-ММ-ДД.'],
    ['budget', '0', 'Поле «Бюджет»: минимум 1.'],
    ['budget', '1.5', 'Поле «Бюджет» должно быть целым числом.'],
    ['budget', '1000000001', 'Поле «Бюджет»: максимум 1000000000.'],
    ['hours', '0', 'Поле «Длительность»: минимум 1.'],
    ['hours', '1.5', 'Поле «Длительность» должно быть целым числом.'],
    ['hours', '25', 'Поле «Длительность»: максимум 24.'],
]);

it('accepts the last calendar date and optional constraints', function () {
    Livewire::test('pages::contractor-finder')
        ->set('date', '2026-12-31')->set('hours', '6')->set('language', 'русский')
        ->call('search')->assertHasNoErrors()
        ->assertSet('submitted.hours', 6)->assertSet('submitted.language', 'русский');
});

it('opens contractor detail with photo and accepts a booking request', function () {
    $component = Livewire::test('pages::contractor-finder')
        ->call('search')
        ->assertSee('Мицури Канроджи')
        ->assertSee('Записаться')
        ->assertSee('images/contractors/portrait-', false);

    $id = $component->get('result')['contractors'][0]['profile']['id'];

    $component
        ->call('openContractorDetail', $id)
        ->assertSet('detailId', $id)
        ->assertSee('Профиль специалиста')
        ->assertSee('О специалисте')
        ->set('bookingName', 'Айгерим')
        ->set('bookingPhone', '+77001234567')
        ->set('bookingNote', 'Нужен ведущий на вечер')
        ->call('submitBooking')
        ->assertHasNoErrors()
        ->assertSet('bookingSent', true)
        ->assertSee('Заявка отправлена');
});
