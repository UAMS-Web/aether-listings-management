<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\People\ListingsManagement\Tests\Support;

use Closure;
use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\HttpResponse;
use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\Transport;

/**
 * A transport that answers from the test and records every request, so no test reaches the network.
 *
 * The token exchange is answered with a token unless the test routes it
 * itself. Every other request goes to the router, which returns the response or
 * null for the default: status 200 with an empty JSON object.
 */
final class FakeTransport implements Transport
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> Every request, in order. */
    public array $requests = [];

    /** @var Closure(string, string, ?string): (?HttpResponse) */
    private Closure $router;

    /**
     * @param  ?Closure(string, string, ?string): (?HttpResponse)  $router  Receives method, URL and body; returns a response, or null for the default.
     */
    public function __construct(?Closure $router = null)
    {
        $this->router = $router ?? static fn (): ?HttpResponse => null;
    }

    /**
     * Record the request and answer it.
     *
     * @param  string  $method  The HTTP method.
     * @param  string  $url  The full URL.
     * @param  array<string, string>  $headers  The headers.
     * @param  ?string  $body  The body.
     */
    public function send(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        $response = ($this->router)($method, $url, $body);

        if ($response instanceof HttpResponse) {
            return $response;
        }

        return str_starts_with($url, 'https://oauth2.googleapis.com/token')
            ? new HttpResponse(200, (string) json_encode(['access_token' => 'test-token', 'expires_in' => 3600]))
            : new HttpResponse(200, '{}');
    }

    /**
     * The requests other than the token exchange.
     *
     * @return list<array{method: string, url: string, headers: array<string, string>, body: ?string}>
     */
    public function apiRequests(): array
    {
        return array_values(array_filter($this->requests, static fn (array $request): bool => ! str_starts_with($request['url'], 'https://oauth2.googleapis.com/token')));
    }

    /**
     * The API requests that write: anything but a GET.
     *
     * @return list<array{method: string, url: string, headers: array<string, string>, body: ?string}>
     */
    public function writes(): array
    {
        return array_values(array_filter($this->apiRequests(), static fn (array $request): bool => $request['method'] !== 'GET'));
    }

    /**
     * A JSON response.
     *
     * @param  array<array-key, mixed>  $data  The body.
     * @param  int  $status  The status.
     */
    public static function json(array $data, int $status = 200): HttpResponse
    {
        return new HttpResponse($status, (string) json_encode($data));
    }
}
