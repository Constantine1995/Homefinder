<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Новостройки от застройщика — демо</title>
    <style>
        body { margin: 0; background: #f5f5f7; color: #1d1d1f; font: 16px/1.6 -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
        header { padding: 72px 16px 56px; background: linear-gradient(135deg, #1f3a5f, #3b6e8f); color: #fff; }
        header div, main { max-width: 960px; margin: 0 auto; }
        h1 { margin: 0 0 12px; font-size: 36px; line-height: 1.2; }
        header p { margin: 0; max-width: 560px; opacity: .85; }
        main { padding: 40px 16px 120px; }
        .note { padding: 12px 16px; background: #fff; border-radius: 8px; color: #6e6e73; }
        .complexes { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; margin-top: 24px; }
        .complex { padding: 20px; background: #fff; border-radius: 12px; }
        .complex h2 { margin: 0 0 4px; font-size: 20px; }
        .complex .address { margin: 0 0 12px; color: #6e6e73; font-size: 14px; }
        .complex p { margin: 0; font-size: 15px; }
        code { padding: 2px 6px; background: #f5f5f7; border-radius: 4px; }
    </style>
</head>
<body>
<header>
    <div>
        <h1>Квартиры в новостройках Москвы</h1>
        <p>Подберём квартиру, посчитаем ипотеку и ответим на вопросы о жилых комплексах — напишите в чат в углу страницы, отвечаем круглосуточно.</p>
    </div>
</header>
<main>
    <p class="note">Демонстрационный сайт вымышленного застройщика из пет-проекта HomeFinder: так виджет чата выглядит на сайте настоящего застройщика.</p>

    @if ($widgetKey === null)
        <p class="note">Виджет ещё не подключён: <code>php artisan widget:connect --tenant=1</code></p>
    @endif

    <section class="complexes">
        @foreach ($complexes as $complex)
            <article class="complex">
                <h2>ЖК «{{ $complex->name }}»</h2>
                <p class="address">{{ $complex->address }}</p>
                <p>{{ Str::limit($complex->description, 220) }}</p>
            </article>
        @endforeach
    </section>
</main>

@if ($widgetKey !== null)
    <script src="{{ asset('widget/widget.js') }}" data-key="{{ $widgetKey }}" async></script>
@endif
</body>
</html>
