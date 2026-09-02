<?php

namespace PerfexMcp\Support;

/**
 * Request-scoped context for the current MCP call.
 *
 * A single HTTP request handles one authenticated key acting as one staff
 * member, so a static holder is sufficient (and avoids threading the key
 * through the SDK, which instantiates tool classes with no constructor args).
 * Set once by the endpoint controller after authentication.
 */
final class McpContext
{
    private static ?int $keyId = null;

    private static ?int $staffId = null;

    private static ?string $ip = null;

    private static bool $allowDestructive = false;

    public static function set(int $keyId, int $staffId, ?string $ip, bool $allowDestructive = false): void
    {
        self::$keyId            = $keyId;
        self::$staffId          = $staffId;
        self::$ip               = $ip;
        self::$allowDestructive = $allowDestructive;
    }

    public static function allowDestructive(): bool
    {
        return self::$allowDestructive;
    }

    public static function keyId(): ?int
    {
        return self::$keyId;
    }

    public static function staffId(): ?int
    {
        return self::$staffId;
    }

    public static function ip(): ?string
    {
        return self::$ip;
    }

    public static function reset(): void
    {
        self::$keyId            = null;
        self::$staffId          = null;
        self::$ip               = null;
        self::$allowDestructive = false;
    }
}
