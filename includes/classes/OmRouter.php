<?php
/**
 * SUPERBORA - Router System
 * Sistema de rotas simples para API REST
 */

class OmRouter {
    private array $routes = [];
    private array $middlewares = [];
    private string $prefix = '';

    /**
     * Adicionar rota GET
     */
    public function get(string $path, callable|array $handler): self {
        return $this->addRoute('GET', $path, $handler);
    }

    /**
     * Adicionar rota POST
     */
    public function post(string $path, callable|array $handler): self {
        return $this->addRoute('POST', $path, $handler);
    }

    /**
     * Adicionar rota PUT
     */
    public function put(string $path, callable|array $handler): self {
        return $this->addRoute('PUT', $path, $handler);
    }

    /**
     * Adicionar rota DELETE
     */
    public function delete(string $path, callable|array $handler): self {
        return $this->addRoute('DELETE', $path, $handler);
    }

    /**
     * Adicionar rota PATCH
     */
    public function patch(string $path, callable|array $handler): self {
        return $this->addRoute('PATCH', $path, $handler);
    }

    /**
     * Grupo de rotas com prefixo
     */
    public function group(string $prefix, callable $callback): self {
        $previousPrefix = $this->prefix;
        $this->prefix = $previousPrefix . $prefix;
        $callback($this);
        $this->prefix = $previousPrefix;
        return $this;
    }

    /**
     * Adicionar middleware
     */
    public function middleware(callable $middleware): self {
        $this->middlewares[] = $middleware;
        return $this;
    }

    /**
     * Processar requisicao
     */
    public function dispatch(): void {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        // Remover trailing slash
        $uri = rtrim($uri, '/') ?: '/';

        // CORS headers
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

        // Preflight request
        if ($method === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        // Procurar rota
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            $params = $this->matchRoute($route['path'], $uri);

            if ($params !== false) {
                // Executar middlewares
                foreach ($this->middlewares as $middleware) {
                    $result = $middleware();
                    if ($result === false) {
                        return;
                    }
                }

                // Executar handler
                $handler = $route['handler'];

                if (is_array($handler)) {
                    [$class, $method] = $handler;
                    $instance = new $class();
                    $response = $instance->$method($params);
                } else {
                    $response = $handler($params);
                }

                // Enviar resposta
                if (is_array($response)) {
                    $this->json($response);
                }

                return;
            }
        }

        // 404 Not Found
        $this->json(['error' => 'Not Found', 'path' => $uri], 404);
    }

    /**
     * Resposta JSON
     */
    public function json(array $data, int $status = 200): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Obter body da requisicao como JSON
     */
    public static function getJsonBody(): array {
        $body = file_get_contents('php://input');
        return json_decode($body, true) ?? [];
    }

    /**
     * Obter parametro da query string
     */
    public static function query(string $key, mixed $default = null): mixed {
        return $_GET[$key] ?? $default;
    }

    private function addRoute(string $method, string $path, callable|array $handler): self {
        $this->routes[] = [
            'method' => $method,
            'path' => $this->prefix . $path,
            'handler' => $handler
        ];
        return $this;
    }

    private function matchRoute(string $pattern, string $uri): array|false {
        // Converter {param} para regex
        $regex = preg_replace('/\{([a-zA-Z_]+)\}/', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';

        if (preg_match($regex, $uri, $matches)) {
            // Filtrar apenas params nomeados
            return array_filter($matches, fn($key) => !is_int($key), ARRAY_FILTER_USE_KEY);
        }

        return false;
    }
}
