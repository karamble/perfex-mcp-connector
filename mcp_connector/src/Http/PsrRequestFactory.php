<?php

namespace PerfexMcp\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Builds a PSR-7 ServerRequest from PHP superglobals for the MCP transport.
 * ServerRequestCreator reads method, URI, headers, cookies, query, parsed body,
 * uploaded files and the raw body (php://input) in a host-portable way.
 */
final class PsrRequestFactory
{
    public static function fromGlobals(): ServerRequestInterface
    {
        $psr17   = new Psr17Factory();
        $creator = new ServerRequestCreator($psr17, $psr17, $psr17, $psr17);

        $request = $creator->fromGlobals();

        // On some Apache/FastCGI setups fromGlobals() emits a duplicated Host
        // header ("host, host"), which breaks the SDK's exact-match host guard.
        // Collapse it to the single authoritative URI host.
        $uriHost = $request->getUri()->getHost();
        if ($uriHost !== '') {
            $request = $request->withHeader('Host', $uriHost);
        }

        return $request;
    }
}
