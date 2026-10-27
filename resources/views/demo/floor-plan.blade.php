@use('App\Demo\Feed\RoomType')
@use('Illuminate\Support\Number')
@php
    $scale = 50;
    $margin = 24;
    $balconyDepth = $layout->balcony === null ? 0 : 1.2;
    $left = $margin;
    $top = $margin + $balconyDepth * $scale;
    $planWidth = $layout->width() * $scale;
    $planHeight = $layout->depth() * $scale;
    $bottom = $top + $planHeight;
    $canvasWidth = $planWidth + 2 * $margin;
    $canvasHeight = $bottom + 104;
    $rooms = $layout->rooms();
    $hall = collect($rooms)->firstWhere('type', RoomType::Hall);
    $squareMeters = fn (float $area): string => Number::format($area, precision: 1, locale: 'ru').' м²';
@endphp
<svg xmlns="http://www.w3.org/2000/svg" width="{{ $canvasWidth }}" height="{{ $canvasHeight }}" viewBox="0 0 {{ $canvasWidth }} {{ $canvasHeight }}" font-family="Arial, Helvetica, sans-serif" role="img">
    <title>{{ $layout->title() }}, {{ $squareMeters($layout->area()) }}</title>
    <rect width="100%" height="100%" fill="#ffffff"/>

    @if ($layout->balcony !== null)
        @php
            $balconyRoom = $rooms[$layout->balconyRoom];
            $balconyX = $left + ($balconyRoom['x'] + 0.2) * $scale;
            $balconyWidth = ($balconyRoom['width'] - 0.4) * $scale;
        @endphp
        <rect x="{{ $balconyX }}" y="{{ $margin }}" width="{{ $balconyWidth }}" height="{{ $balconyDepth * $scale }}" fill="#f4f6f7" stroke="#7f8c8d" stroke-width="2" stroke-dasharray="6 4"/>
        <text x="{{ $balconyX + $balconyWidth / 2 }}" y="{{ $margin + $balconyDepth * $scale / 2 + 4 }}" text-anchor="middle" font-size="12" fill="#636e72">{{ $layout->balcony === 'лоджия' ? 'Лоджия' : 'Балкон' }}</text>
    @endif

    @foreach ($rooms as $room)
        @php
            $x = $left + $room['x'] * $scale;
            $y = $top + $room['y'] * $scale;
            $width = $room['width'] * $scale;
            $height = $room['depth'] * $scale;
            $fill = match ($room['type']) {
                RoomType::Room, RoomType::Bedroom, RoomType::StudioRoom => '#f3ece0',
                RoomType::Kitchen, RoomType::KitchenLiving => '#fbeacc',
                RoomType::Bathroom, RoomType::Bath, RoomType::Toilet, RoomType::Laundry => '#dcebf5',
                RoomType::Hall, RoomType::Wardrobe => '#eeeeee',
            };
        @endphp
        <rect x="{{ $x }}" y="{{ $y }}" width="{{ $width }}" height="{{ $height }}" fill="{{ $fill }}" stroke="#95a5a6" stroke-width="2"/>
        <text x="{{ $x + $width / 2 }}" y="{{ $y + $height / 2 - 4 }}" text-anchor="middle" font-size="13" fill="#2d3436">{{ $width < 90 ? $room['type']->shortLabel() : $room['type']->label() }}</text>
        <text x="{{ $x + $width / 2 }}" y="{{ $y + $height / 2 + 13 }}" text-anchor="middle" font-size="12" fill="#636e72">{{ $squareMeters($room['area']) }}</text>
    @endforeach

    <rect x="{{ $left }}" y="{{ $top }}" width="{{ $planWidth }}" height="{{ $planHeight }}" fill="none" stroke="#2d3436" stroke-width="6"/>

    @foreach ($rooms as $room)
        @if ($room['y'] === 0.0)
            <rect x="{{ $left + ($room['x'] + $room['width'] * 0.2) * $scale }}" y="{{ $top - 3 }}" width="{{ $room['width'] * 0.6 * $scale }}" height="6" fill="#d6ecfa" stroke="#2d3436" stroke-width="1"/>
        @endif
    @endforeach

    @if ($hall !== null)
        @php
            $doorWidth = 0.9 * $scale;
            $doorX = $left + ($hall['x'] + $hall['width'] / 2) * $scale - $doorWidth / 2;
        @endphp
        <line x1="{{ $doorX }}" y1="{{ $bottom }}" x2="{{ $doorX + $doorWidth }}" y2="{{ $bottom }}" stroke="#ffffff" stroke-width="8"/>
        {{-- Entrance doors of Russian flats open outwards, into the common corridor. --}}
        <path d="M {{ $doorX }} {{ $bottom }} L {{ $doorX }} {{ $bottom + $doorWidth }} A {{ $doorWidth }} {{ $doorWidth }} 0 0 0 {{ $doorX + $doorWidth }} {{ $bottom }}" fill="none" stroke="#2d3436" stroke-width="1.5"/>
    @endif

    <text x="{{ $left }}" y="{{ $bottom + 84 }}" font-size="18" font-weight="bold" fill="#2d3436">{{ $layout->title() }} · {{ $squareMeters($layout->area()) }}</text>
</svg>
