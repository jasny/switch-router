<?php

declare(strict_types=1);

namespace Jasny\SwitchRoute\Generator;

use Jasny\SwitchRoute\Endpoint;
use Jasny\SwitchRoute\InvalidRouteException;

/**
 * Generate a route middleware that sets server request attributes.
 */
class GenerateRouteMiddleware extends AbstractGenerate
{
    /**
     * Invoke code generation.
     *
     * @param string $name      Class name
     * @param array  $routes    Ignored
     * @param array  $structure
     * @return string
     */
    public function __invoke(string $name, array $routes, array $structure): string
    {
        $default = $structure["\e"] ?? null;
        unset($structure["\e"]);

        $applyRoutingCode = self::indent($this->generateSwitch($structure), 8) . "\n\n"
            . self::indent($this->generateDefault($default), 8);

        [$namespace, $class] = $this->generateNs($name);

        return <<<CODE
<?php

declare(strict_types=1);
{$namespace}
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 compatible middleware that add route attributes to the server request.
 *
 * This file is generated by SwitchRoute.
 * Do not modify it manually. Any changes will be overwritten.
 */
class {$class} implements MiddlewareInterface
{
    /**
     * Add routing attributes to the server request
     */
    protected function applyRouting(ServerRequestInterface \$request): ServerRequestInterface
    {
        \$method = \$request->getMethod();
        \$path = \$request->getUri()->getPath();
        \$segments = \$path === "/" ? [] : explode("/", trim(\$path, "/"));

{$applyRoutingCode}
    }

    /**
     * Process an incoming server request.
     */
    public function process(ServerRequestInterface \$request, RequestHandlerInterface \$handler): ResponseInterface
    {
        return \$handler->handle(\$this->applyRouting(\$request));
    }
}
CODE;
    }

    /**
     * Generate code for an endpoint
     *
     * @param Endpoint $endpoint
     * @return string
     */
    protected function generateEndpoint(Endpoint $endpoint): string
    {
        $exportValue = function ($var): string {
            return var_export($var, true);
        };
        $methods = join(', ', array_map($exportValue, $endpoint->getAllowedMethods()));

        return "\$request = \$request->withAttribute('route:allowed_methods', [{$methods}]);\n"
            . parent::generateEndpoint($endpoint);
    }

    /**
     * Generate routing code for an endpoint.
     *
     * @param string $_
     * @param array  $route
     * @param array  $vars
     * @return string
     * @throws InvalidRouteException
     */
    protected function generateRoute(string $_, array $route, array $vars): string
    {
        $code = ['return $request'];

        foreach ($route as $key => $value) {
            $code[] = "    ->withAttribute('route:" . addslashes($key) . "', " . var_export($value, true) . ")";
        }

        foreach ($vars as $key => $index) {
            $code[] = "    ->withAttribute('route:{" . addslashes($key) . "}', \$segments[{$index}])";
        }

        $code[array_key_last($code)] .= ';';

        return join("\n", $code);
    }

    /**
     * Generate code for when no route matches.
     *
     * @param Endpoint|null $endpoint
     * @return string
     * @throws InvalidRouteException
     */
    protected function generateDefault(?Endpoint $endpoint): string
    {
        return $endpoint === null
            ? 'return $request;'
            : $this->generateRoute('default', $endpoint->getRoutes()[''], []);
    }
}
