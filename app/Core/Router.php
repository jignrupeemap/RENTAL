<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Minimal router. Patterns use {name} for a path segment and {name:regex}
 * for a custom match, e.g. '/{purpose:rent|pg|lease}/{slug:[a-z0-9-]+}-p{id:\d+}'.
 * Middleware are class names with a handle(Request): ?Response method that
 * returns a Response to stop the request (redirect, 403...) or null to continue.
 */
final class Router
{
    /** @var list<array{method:string, regex:string, handler:array{0:class-string,1:string}, middleware:list<class-string>}> */
    private array $routes = [];
    /** @var list<class-string> */
    private array $groupMiddleware = [];
    private string $groupPrefix = '';
    /** @var list<class-string> run before every matched route (e.g. CSRF) */
    private array $globalMiddleware = [];

    /** @param list<class-string> $middleware */
    public function globalMiddleware(array $middleware): void
    {
        $this->globalMiddleware = $middleware;
    }

    public function get(string $pattern, array $handler, array $middleware = []): void
    {
        $this->add('GET', $pattern, $handler, $middleware);
    }

    public function post(string $pattern, array $handler, array $middleware = []): void
    {
        $this->add('POST', $pattern, $handler, $middleware);
    }

    public function group(string $prefix, array $middleware, callable $define): void
    {
        [$prevPrefix, $prevMw] = [$this->groupPrefix, $this->groupMiddleware];
        $this->groupPrefix = $prevPrefix . $prefix;
        $this->groupMiddleware = [...$prevMw, ...$middleware];
        $define($this);
        [$this->groupPrefix, $this->groupMiddleware] = [$prevPrefix, $prevMw];
    }

    private function add(string $method, string $pattern, array $handler, array $middleware): void
    {
        $full = rtrim($this->groupPrefix . $pattern, '/') ?: '/';
        $regex = preg_replace_callback('/\{(\w+)(?::([^}]+))?\}/', static function (array $m): string {
            return '(?P<' . $m[1] . '>' . ($m[2] ?? '[^/]+') . ')';
        }, $full);
        $this->routes[] = [
            'method' => $method,
            'regex' => '#^' . $regex . '$#i',
            'handler' => $handler,
            'middleware' => [...$this->groupMiddleware, ...$middleware],
        ];
    }

    public function dispatch(Request $request): Response
    {
        $methodMismatch = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $m)) {
                continue;
            }
            if ($route['method'] !== $request->method && !($route['method'] === 'GET' && $request->method === 'HEAD')) {
                $methodMismatch = true;
                continue;
            }
            $request->params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);

            foreach ([...$this->globalMiddleware, ...$route['middleware']] as $mw) {
                $stop = (new $mw())->handle($request);
                if ($stop instanceof Response) {
                    return $stop;
                }
            }
            [$class, $action] = $route['handler'];
            $result = (new $class())->{$action}($request);
            return $result instanceof Response ? $result : Response::html((string) $result);
        }

        return $methodMismatch
            ? Response::html(View::render('errors/404', ['title' => 'Not allowed']), 405)
            : Response::html(View::render('errors/404', ['title' => 'Page not found']), 404);
    }
}
