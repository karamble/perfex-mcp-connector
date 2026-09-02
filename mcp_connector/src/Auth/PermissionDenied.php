<?php

namespace PerfexMcp\Auth;

/**
 * Thrown by the tool guard when the impersonated staff member lacks the
 * capability required for a tool, or when a write/destructive kill-switch
 * blocks the operation. Caught by AbstractTools::guard(), which records a
 * "denied" audit row and surfaces the message to the client as a tool error.
 */
final class PermissionDenied extends \RuntimeException
{
}
