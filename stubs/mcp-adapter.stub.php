<?php
/**
 * Static-analysis surface for the optional WordPress/mcp-adapter plugin (v0.5.0).
 *
 * The Adapter is never a Composer dependency of this plugin and is never
 * bundled with it; ADR 0025 only lets the plugin hand its own registered
 * abilities to an Adapter the site installed itself. These are the class names
 * `McpServerProvider` passes to the Adapter as strings, declared here so static
 * analysis can resolve them.
 *
 * `McpAdapter` itself is deliberately absent: the provider types the instance
 * it receives as `object` and guards with `method_exists()`, because the class
 * does not exist on installs without the Adapter. Its stable v0.5.0
 * `create_server()` signature is:
 *
 *     create_server(
 *         string $server_id,
 *         string $server_route_namespace,
 *         string $server_route,
 *         string $server_name,
 *         string $server_description,
 *         string $server_version,
 *         array $mcp_transports,
 *         string $error_handler,
 *         string $observability_handler,
 *         array $abilities,
 *         array $resources,
 *         array $prompts
 *     ): void
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace WP\MCP\Transport;

final class HttpTransport {}

namespace WP\MCP\Infrastructure\ErrorHandling;

final class ErrorLogMcpErrorHandler {}

namespace WP\MCP\Infrastructure\Observability;

final class NullMcpObservabilityHandler {}
