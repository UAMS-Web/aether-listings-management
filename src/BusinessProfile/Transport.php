<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\People\ListingsManagement\BusinessProfile;

/**
 * The one way the Business Profile client reaches the network.
 *
 * The client builds every request and reads every response, but sends nothing
 * itself: a host binds a transport backed by its HTTP client, and a test passes
 * a fake that answers from a script, so no test needs the network.
 */
interface Transport
{
    /**
     * Send one HTTP request.
     *
     * A request that could not be made at all (no connection, a refused
     * certificate) is reported as status 0 with the reason as the body, never
     * thrown, so the client can name it in the failure it raises.
     *
     * @param  string  $method  The HTTP method, upper case.
     * @param  string  $url  The full URL, query string included.
     * @param  array<string, string>  $headers  Header name => value.
     * @param  ?string  $body  The request body, or null for none.
     * @return HttpResponse The status and the raw body.
     */
    public function send(string $method, string $url, array $headers, ?string $body): HttpResponse;
}
