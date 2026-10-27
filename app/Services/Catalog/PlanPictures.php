<?php

namespace App\Services\Catalog;

use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Gets flats' floor plans as pictures a messenger shows: PNG or JPEG as they are, SVG drawn into PNG by rsvg-convert.
 */
class PlanPictures
{
    /**
     * Width of a drawn plan in pixels: twice the demo plans' size, sharp on a phone.
     */
    public const int WIDTH = 960;

    public function __construct(
        #[Config('app.url')] private string $appUrl,
        #[Config('app.internal_url')] private ?string $internalUrl,
    ) {}

    /**
     * Get the plan at the URL as a picture, or null when it cannot be had.
     */
    public function picture(string $planUrl): ?string
    {
        try {
            $response = Http::timeout(10)->get($this->reachableUrl($planUrl));
        } catch (ConnectionException $exception) {
            Log::warning('The floor plan could not be downloaded.', ['url' => $planUrl, 'exception' => $exception]);

            return null;
        }

        $type = Str::before((string) $response->header('Content-Type'), ';');

        if ($response->failed() || ! in_array($type, ['image/svg+xml', 'image/png', 'image/jpeg'], true)) {
            Log::warning('The floor plan is not a picture.', ['url' => $planUrl, 'status' => $response->status(), 'type' => $type]);

            return null;
        }

        return $type === 'image/svg+xml' ? $this->draw($response->body(), $planUrl) : $response->body();
    }

    /**
     * Files the application serves itself are fetched by the address its own containers reach it at.
     */
    private function reachableUrl(string $planUrl): string
    {
        $appUrl = rtrim($this->appUrl, '/').'/';

        return $this->internalUrl !== null && $this->internalUrl !== '' && str_starts_with($planUrl, $appUrl)
            ? rtrim($this->internalUrl, '/').'/'.Str::after($planUrl, $appUrl)
            : $planUrl;
    }

    private function draw(string $svg, string $planUrl): ?string
    {
        $result = Process::input($svg)
            ->timeout(30)
            ->run(['rsvg-convert', '--format', 'png', '--width', (string) self::WIDTH, '--keep-aspect-ratio', '--background-color', 'white']);

        if ($result->failed()) {
            Log::warning('The floor plan could not be drawn.', ['url' => $planUrl, 'error' => $result->errorOutput()]);

            return null;
        }

        return $result->output();
    }
}
