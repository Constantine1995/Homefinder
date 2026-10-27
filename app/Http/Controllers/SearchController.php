<?php

namespace App\Http\Controllers;

use App\Http\Requests\SearchRequest;
use App\Models\Complex;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class SearchController extends Controller
{
    /**
     * Example messages to try the search with, as buyers write them.
     */
    public const array EXAMPLES = [
        'двушка до 9 млн, до метро пешком, сдача не позже 2027',
        'евродвушка в Тёплых холмах, рядом садик, окна во двор',
        'трёшка для семьи до 16 млн, рядом школа, не первый этаж',
        'студия под ключ до 6 млн для сдачи в аренду',
        'однушка до 5 млн с видом на парк',
    ];

    /**
     * Search the developer's flats by a message in a buyer's words.
     *
     * The agent service understands the message and searches; the tenant is the employee's, never one
     * the message names. Without the service, the page says so and the catalog keeps working.
     */
    public function __invoke(SearchRequest $request, AgentClient $agent): Response
    {
        $text = $request->text();
        $eased = $request->eased();
        $tenantId = $request->user()->tenant_id;

        return Inertia::render('search/Index', [
            'text' => $text,
            'examples' => self::EXAMPLES,
            'complexes' => fn (): array => Complex::query()->orderBy('name')->pluck('name', 'id')->all(),
            'search' => fn (): ?array => match (true) {
                $text === '' => null,
                $eased !== null => $this->ask(fn (): array => [
                    'query' => ['filters' => $eased, 'wishes' => $request->wishes(), 'unknown_complexes' => []],
                    'result' => $agent->searchFlats($tenantId, $eased, $request->wishes()),
                    'relaxations' => [],
                ]),
                default => $this->ask(fn (): array => $agent->queryFlats($tenantId, $text)),
            },
        ]);
    }

    /**
     * @param  callable(): array<string, mixed>  $search
     * @return array<string, mixed>
     */
    private function ask(callable $search): array
    {
        try {
            return $search();
        } catch (AgentException $exception) {
            Log::warning('The free-text search failed.', ['exception' => $exception]);

            return ['error' => 'Поиск по тексту сейчас недоступен: сервис ИИ не отвечает. Каталог с фильтрами работает.'];
        }
    }
}
