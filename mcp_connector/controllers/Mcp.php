<?php

defined('BASEPATH') or exit('No direct script access allowed');

use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use PerfexMcp\Auth\AuthException;
use PerfexMcp\Auth\KeyService;
use PerfexMcp\Http\PsrRequestFactory;
use PerfexMcp\Http\ResponseEmitter;
use PerfexMcp\McpServerFactory;

/**
 * Public MCP endpoint: /mcp_connector/mcp
 *
 * Streamable HTTP transport (POST = JSON-RPC, GET = 405, DELETE = session end).
 * Extends App_Controller for a fully-booted Perfex environment without the
 * client-area theme. Authentication is bearer-token; there is no session cookie.
 */
class Mcp extends App_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->library('app_modules');
    }

    public function index()
    {
        // Go dark when the module is deactivated or globally disabled: the MX
        // router still resolves module controllers regardless of active state,
        // so this check is what actually turns the endpoint off.
        if ($this->app_modules->is_inactive('mcp_connector') || ! get_option('mcp_connector_enabled')) {
            show_404();
        }

        if (! function_exists('mcp_connector_prerequisites_met') || ! mcp_connector_prerequisites_met()) {
            $this->failJsonRpc(503, 'MCP Connector is not correctly installed (requires PHP 8.1+ and the bundled vendor/ directory).');
        }

        $keyService = new KeyService();

        try {
            $key = $keyService->authenticate();
        } catch (AuthException $e) {
            $this->failJsonRpc($e->httpStatus(), $e->getMessage());

            return;
        }

        $keyService->impersonate($key);

        // Load the impersonated staff member's admin language so any strings
        // emitted by models (emails, activity log) resolve correctly.
        load_admin_language((int) $key->user_id);

        $psr17   = new Psr17Factory();
        $request = PsrRequestFactory::fromGlobals();
        $host    = strtolower((string) (parse_url(site_url(), PHP_URL_HOST) ?: ($_SERVER['HTTP_HOST'] ?? 'localhost')));

        $server    = McpServerFactory::create();
        $transport = new StreamableHttpTransport(
            $request,
            $psr17,
            $psr17,
            null,
            McpServerFactory::middleware($host)
        );

        $response = $server->run($transport);

        ResponseEmitter::emit($response);
        exit;
    }

    /**
     * Emit a JSON-RPC-shaped error and stop. Used for transport-level failures
     * (auth, rate limit, misconfiguration) that occur before the SDK runs.
     */
    private function failJsonRpc(int $httpStatus, string $message)
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code($httpStatus);
        if ($httpStatus === 401) {
            header('WWW-Authenticate: Bearer realm="mcp"');
        }
        header('Content-Type: application/json');

        echo json_encode([
            'jsonrpc' => '2.0',
            'error'   => [
                'code'    => $httpStatus === 429 ? -32029 : -32001,
                'message' => $message,
            ],
            'id' => null,
        ]);
        exit;
    }
}
