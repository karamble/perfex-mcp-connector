<?php

namespace PerfexMcp;

use Mcp\Server;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use PerfexMcp\Support\CiLogger;
use PerfexMcp\Support\DbSessionStore;
use PerfexMcp\Tools\ToolRegistrar;

/**
 * Assembles the configured MCP Server and the HTTP middleware stack. All direct
 * contact with the (pre-1.0) SDK is isolated here so upstream API churn touches
 * exactly one class.
 */
final class McpServerFactory
{
    public static function create(): Server
    {
        $ttl = (int) get_option('mcp_connector_session_ttl');

        $builder = Server::builder()
            ->setServerInfo('Perfex CRM', MCP_CONNECTOR_VERSION)
            ->setInstructions(self::instructions())
            // Return the whole catalog in one tools/list page (the SDK defaults
            // to 50; the full read+write+destructive set exceeds that).
            ->setPaginationLimit(250)
            ->setSession(new DbSessionStore($ttl > 0 ? $ttl : 3600))
            ->setLogger(new CiLogger());

        foreach (ToolRegistrar::all() as $tool) {
            // Named arguments on purpose: addTool() has twice gained a new
            // parameter ahead of $inputSchema (0.5.0 and 0.6.0 both inserted
            // $title), and positional binding fails silently when that happens.
            $builder->addTool(
                handler: $tool['handler'],
                name: $tool['name'],
                title: $tool['title'] ?? null,
                description: $tool['description'] ?? null,
                annotations: $tool['annotations'] ?? null,
                inputSchema: $tool['inputSchema'] ?? null,
            );
        }

        return $builder->build();
    }

    /**
     * HTTP middleware for the streamable transport. Rebuilds the SDK's secure
     * defaults but allow-lists the CRM's own host in the DNS-rebinding guard,
     * whose default only permits localhost and would otherwise reject every
     * production request.
     *
     * @return array
     */
    public static function middleware(string $host): array
    {
        $allowedHosts = array_values(array_filter([
            $host,
            'localhost',
            '127.0.0.1',
            '[::1]',
        ]));

        // ProtocolVersionMiddleware is deliberately absent. Since SDK 0.8.0 the
        // transport applies it itself, to handshake-era traffic only, via its
        // own handshakeMiddleware(). Including it here would run it before the
        // modern (2026-07-28) era is classified, and that era's requests would
        // be rejected with "unsupported protocol version".
        return [
            new CorsMiddleware(),
            new DnsRebindingProtectionMiddleware($allowedHosts),
        ];
    }

    private static function instructions(): string
    {
        return 'This server exposes a Perfex CRM instance. Tools are grouped by entity '
            . '(customers, contacts, leads, proposals, estimates, invoices, payments, credit notes, '
            . 'contracts, subscriptions, expenses, items, tasks, tickets, projects, knowledge base, '
            . 'announcements, notes, staff, custom fields). Every call is executed as the staff '
            . 'member bound to the API key and is subject to that staff member\'s Perfex '
            . 'permissions; calls you are not permitted to make return an error. Every read is '
            . 'scoped exactly as Perfex scopes it for that staff member: own or assigned sales '
            . 'documents, customers they administer, projects they are a member of, tasks they '
            . 'are assigned to or follow, tickets in their departments; records outside that scope '
            . 'are not listed and fetching them by id is refused. Writes are gated by capability '
            . 'only, as in Perfex. Write and delete tools may be disabled by the administrator. '
            . 'Prefer *_list/*_get to inspect data before making changes, use custom_fields_list '
            . 'to discover an entity\'s custom fields, and pass confirm=true only when you intend '
            . 'a destructive action.';
    }
}
