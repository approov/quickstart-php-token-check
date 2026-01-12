<?php declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

Dotenv::createImmutable(__DIR__)->safeLoad();

final class Text
{
    public static function hasText(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        return trim($value) !== '';
    }
}

final class Base64Url
{
    public static function decode(string $value): string
    {
        $normalized = strtr($value, '-_', '+/');
        $padding = strlen($normalized) % 4;
        if ($padding > 0) {
            $normalized .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode($normalized, true);
        if ($decoded === false) {
            throw new RuntimeException('Invalid base64url value.');
        }
        return $decoded;
    }
}

final class ApproovSecret
{
    public static function fromEnv(string $name): string
    {
        $value = getenv($name);
        if (!Text::hasText($value)) {
            $value = $_ENV[$name] ?? $_SERVER[$name] ?? null;
        }
        if (!Text::hasText($value)) {
            throw new RuntimeException("Missing environment variable: {$name}");
        }
        return Base64Url::decode(trim((string) $value));
    }
}

final class Request
{
    private string $method;
    private string $path;
    /** @var array<string, string> */
    private array $headers;

    /** @param array<string, string> $headers */
    public function __construct(string $method, string $path, array $headers)
    {
        $this->method = strtoupper($method);
        $this->path = $path;
        $this->headers = $headers;
    }

    public static function fromGlobals(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = '/';
        }

        return new self(
            $method,
            $path,
            self::collectHeaders()
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function header(string $name): ?string
    {
        $key = strtolower($name);
        $value = $this->headers[$key] ?? null;
        if (!Text::hasText($value)) {
            return null;
        }
        return trim((string) $value);
    }

    /** @return array<string, string> */
    private static function collectHeaders(): array
    {
        $headers = [];

        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                $headers[strtolower($name)] = trim((string) $value);
            }
            return $headers;
        }

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $header = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$header] = trim((string) $value);
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $header = strtolower(str_replace('_', '-', $key));
                $headers[$header] = trim((string) $value);
            }
        }

        return $headers;
    }
}

final class Response
{
    private int $status;
    /** @var array<string, string> */
    private array $headers;
    private string $body;

    /** @param array<string, string> $headers */
    public function __construct(int $status, array $headers, string $body)
    {
        $this->status = $status;
        $this->headers = $headers;
        $this->body = $body;
    }

    /** @param array<string, mixed> $payload */
    public static function json(array $payload, int $status = 200): self
    {
        $bodyPayload = empty($payload) ? new stdClass() : $payload;
        $body = json_encode($bodyPayload, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new RuntimeException('Failed to encode JSON response.');
        }

        $headers = [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-cache',
            'Content-Length' => (string) strlen($body),
        ];

        return new self($status, $headers, $body);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}

final class Protection
{
    public const NONE = 0;
    public const TOKEN = 1;
    public const TOKEN_BINDING = 2;
    public const TOKEN_DOUBLE_BINDING = 3;

    public static function requiresToken(int $protection): bool
    {
        return $protection !== self::NONE;
    }

    public static function requiresBinding(int $protection): bool
    {
        return $protection === self::TOKEN_BINDING || $protection === self::TOKEN_DOUBLE_BINDING;
    }
}

final class Route
{
    private string $method;
    private string $path;
    /** @var callable */
    private $handler;
    private int $protection;

    public function __construct(string $method, string $path, callable $handler, int $protection)
    {
        $this->method = strtoupper($method);
        $this->path = $path;
        $this->handler = $handler;
        $this->protection = $protection;
    }

    public function matches(Request $request): bool
    {
        return $this->method === $request->method() && $this->path === $request->path();
    }

    public function handler(): callable
    {
        return $this->handler;
    }

    public function protection(): int
    {
        return $this->protection;
    }
}

final class Router
{
    /** @var Route[] */
    private array $routes = [];

    public function add(string $method, string $path, callable $handler, int $protection = Protection::NONE): void
    {
        $this->routes[] = new Route($method, $path, $handler, $protection);
    }

    public function match(Request $request): ?Route
    {
        foreach ($this->routes as $route) {
            if ($route->matches($request)) {
                return $route;
            }
        }

        return null;
    }
}

final class ApproovState
{
    private bool $approovEnabled;
    private bool $tokenBindingEnabled;

    public function __construct(bool $approovEnabled, bool $tokenBindingEnabled)
    {
        $this->approovEnabled = $approovEnabled;
        $this->tokenBindingEnabled = $tokenBindingEnabled;
    }

    public function approovEnabled(): bool
    {
        return $this->approovEnabled;
    }

    public function tokenBindingEnabled(): bool
    {
        return $this->tokenBindingEnabled;
    }

    public function withApproovEnabled(bool $enabled): self
    {
        return new self($enabled, $this->tokenBindingEnabled);
    }

    public function withTokenBindingEnabled(bool $enabled): self
    {
        return new self($this->approovEnabled, $enabled);
    }

    /** @return array<string, bool> */
    public function toArray(): array
    {
        return [
            'approovEnabled' => $this->approovEnabled,
            'tokenBindingEnabled' => $this->tokenBindingEnabled,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $approovEnabled = array_key_exists('approovEnabled', $data)
            ? (bool) $data['approovEnabled']
            : true;
        $tokenBindingEnabled = array_key_exists('tokenBindingEnabled', $data)
            ? (bool) $data['tokenBindingEnabled']
            : true;

        return new self($approovEnabled, $tokenBindingEnabled);
    }
}

final class ApproovStateStore
{
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }

    public function getState(): ApproovState
    {
        return $this->withLock(function ($handle): ApproovState {
            [$state, $needsPersist] = $this->readStateFromHandle($handle);
            if ($needsPersist) {
                $this->persistState($handle, $state);
            }
            return $state;
        });
    }

    public function enableApproov(): ApproovState
    {
        return $this->updateState(static fn (ApproovState $state): ApproovState => new ApproovState(true, true));
    }

    public function disableApproov(): ApproovState
    {
        return $this->updateState(static fn (ApproovState $state): ApproovState => new ApproovState(false, false));
    }

    public function enableTokenBinding(): ApproovState
    {
        return $this->updateState(static fn (ApproovState $state): ApproovState => $state->withTokenBindingEnabled(true));
    }

    public function disableTokenBinding(): ApproovState
    {
        return $this->updateState(static fn (ApproovState $state): ApproovState => $state->withTokenBindingEnabled(false));
    }

    /** @param callable(ApproovState): ApproovState $updater */
    private function updateState(callable $updater): ApproovState
    {
        return $this->withLock(function ($handle) use ($updater): ApproovState {
            [$state] = $this->readStateFromHandle($handle);
            $updated = $updater($state);
            $this->persistState($handle, $updated);
            return $updated;
        });
    }

    /** @return array{0: ApproovState, 1: bool} */
    private function readStateFromHandle($handle): array
    {
        rewind($handle);
        $contents = stream_get_contents($handle);

        if (!is_string($contents) || trim($contents) === '') {
            return [new ApproovState(true, true), true];
        }

        $data = json_decode($contents, true);
        if (!is_array($data)) {
            return [new ApproovState(true, true), true];
        }

        return [ApproovState::fromArray($data), false];
    }

    private function persistState($handle, ApproovState $state): void
    {
        $payload = json_encode($state->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new RuntimeException('Failed to write approov state.');
        }

        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, $payload);
        fflush($handle);
    }

    /** @param callable(resource): ApproovState $callback */
    private function withLock(callable $callback): ApproovState
    {
        $handle = fopen($this->path, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Unable to open approov state file.');
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new RuntimeException('Unable to lock approov state file.');
        }

        try {
            return $callback($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}

final class TokenClaims
{
    /** @var array<string, mixed> */
    private array $claims;

    /** @param array<string, mixed> $claims */
    public function __construct(array $claims)
    {
        $this->claims = $claims;
    }

    public function pay(): ?string
    {
        $pay = $this->claims['pay'] ?? null;
        return is_string($pay) ? $pay : null;
    }

    public function exp(): ?int
    {
        $exp = $this->claims['exp'] ?? null;
        if (is_int($exp)) {
            return $exp;
        }
        if (is_numeric($exp)) {
            return (int) $exp;
        }
        return null;
    }
}

final class ApproovTokenVerifier
{
    private string $secret;

    public function __construct(string $secret)
    {
        $this->secret = $secret;
    }

    public function decode(string $token): ?TokenClaims
    {
        try {
            // Verify the JWT signature + expiration using the shared Approov secret.
            $decoded = JWT::decode($token, new Key($this->secret, 'HS256'));
            $claims = new TokenClaims(get_object_vars($decoded));
            $exp = $claims->exp();
            if ($exp === null || $exp < time()) {
                return null;
            }
            return $claims;
        } catch (Throwable $exception) {
            return null;
        }
    }

    public function isBindingValid(string $bindingValue, TokenClaims $claims): bool
    {
        $expected = $claims->pay();
        if (!Text::hasText($expected)) {
            return false;
        }

        $computed = $this->hashBinding($bindingValue);
        return hash_equals($expected, $computed);
    }

    private function hashBinding(string $bindingValue): string
    {
        $hash = hash('sha256', $bindingValue, true);
        return base64_encode($hash);
    }
}

final class ApproovTokenMiddleware
{
    private const APPROOV_HEADER = 'Approov-Token';
    private const AUTH_HEADER = 'Authorization';
    private const DIGEST_HEADER = 'Content-Digest';

    private ApproovStateStore $stateStore;
    private ApproovTokenVerifier $validator;

    public function __construct(ApproovStateStore $stateStore, ApproovTokenVerifier $validator)
    {
        $this->stateStore = $stateStore;
        $this->validator = $validator;
    }

    /** @param callable(Request): Response $next */
    public function handle(Request $request, Route $route, callable $next): Response
    {
        if (!Protection::requiresToken($route->protection())) {
            return $next($request);
        }

        $state = $this->stateStore->getState();
        if (!$state->approovEnabled()) {
            return $next($request);
        }

        $token = $request->header(self::APPROOV_HEADER);
        if (!Text::hasText($token)) {
            return Response::json([], 401);
        }

        $claims = $this->validator->decode((string) $token);
        if ($claims === null) {
            return Response::json([], 401);
        }

        if (Protection::requiresBinding($route->protection()) && $state->tokenBindingEnabled()) {
            // Token binding: hash header(s) and compare against the 'pay' claim.
            $bindingValue = $this->bindingValue($route, $request);
            if (!Text::hasText($bindingValue) || !$this->validator->isBindingValid($bindingValue, $claims)) {
                return Response::json([], 401);
            }
        }

        return $next($request);
    }

    private function bindingValue(Route $route, Request $request): ?string
    {
        if ($route->protection() === Protection::TOKEN_BINDING) {
            return $request->header(self::AUTH_HEADER);
        }

        $authorization = $request->header(self::AUTH_HEADER);
        $digest = $request->header(self::DIGEST_HEADER);
        if (!Text::hasText($authorization) || !Text::hasText($digest)) {
            return null;
        }

        return $authorization . $digest;
    }
}

final class ApproovController
{
    private ApproovStateStore $stateStore;

    public function __construct(ApproovStateStore $stateStore)
    {
        $this->stateStore = $stateStore;
    }

    public function home(Request $request): Response
    {
        $port = getenv('HTTP_PORT');
        if (!Text::hasText($port)) {
            $port = $_ENV['HTTP_PORT'] ?? $_SERVER['HTTP_PORT'] ?? $_SERVER['SERVER_PORT'] ?? '8080';
        }
        return Response::json($this->infoPayload("Approov demo API is running on port {$port}."));
    }

    public function approovState(Request $request): Response
    {
        return Response::json($this->statePayload());
    }

    public function enableApproov(Request $request): Response
    {
        $state = $this->stateStore->enableApproov();
        return Response::json($state->toArray());
    }

    public function disableApproov(Request $request): Response
    {
        $state = $this->stateStore->disableApproov();
        return Response::json($state->toArray());
    }

    public function enableTokenBinding(Request $request): Response
    {
        $state = $this->stateStore->enableTokenBinding();
        return Response::json($state->toArray());
    }

    public function disableTokenBinding(Request $request): Response
    {
        $state = $this->stateStore->disableTokenBinding();
        return Response::json($state->toArray());
    }

    public function unprotected(Request $request): Response
    {
        return Response::json($this->infoPayload("Unprotected endpoint '/unprotected'; no Approov checks performed."));
    }

    public function tokenCheck(Request $request): Response
    {
        return Response::json($this->infoPayload("Protected endpoint '/token-check'; Approov token verified."));
    }

    public function tokenBinding(Request $request): Response
    {
        $payload = $this->infoPayload("Protected endpoint '/token-binding'; Approov token binding enforced.");
        $payload['authorizationHeaderPresent'] = Text::hasText($request->header('Authorization'));
        return Response::json($payload);
    }

    public function tokenDoubleBinding(Request $request): Response
    {
        $payload = $this->infoPayload("Protected endpoint '/token-double-binding'; dual token binding enforced.");
        $payload['authorizationHeaderPresent'] = Text::hasText($request->header('Authorization'));
        $payload['contentDigestHeaderPresent'] = Text::hasText($request->header('Content-Digest'));
        return Response::json($payload);
    }

    /** @return array<string, mixed> */
    private function statePayload(): array
    {
        return $this->stateStore->getState()->toArray();
    }

    /** @return array<string, mixed> */
    private function infoPayload(string $details): array
    {
        $payload = $this->statePayload();
        $payload['details'] = $details;
        return $payload;
    }
}

$secret = ApproovSecret::fromEnv('APPROOV_BASE64URL_SECRET');
$stateStore = new ApproovStateStore(__DIR__ . '/var/approov_state.json');
$validator = new ApproovTokenVerifier($secret);
$middleware = new ApproovTokenMiddleware($stateStore, $validator);
$controller = new ApproovController($stateStore);

$router = new Router();
$router->add('GET', '/', [$controller, 'home']);
$router->add('GET', '/approov-state', [$controller, 'approovState']);
$router->add('POST', '/approov/enable', [$controller, 'enableApproov']);
$router->add('POST', '/approov/disable', [$controller, 'disableApproov']);
$router->add('POST', '/token-binding/enable', [$controller, 'enableTokenBinding']);
$router->add('POST', '/token-binding/disable', [$controller, 'disableTokenBinding']);
$router->add('GET', '/unprotected', [$controller, 'unprotected']);
$router->add('GET', '/token-check', [$controller, 'tokenCheck'], Protection::TOKEN);
$router->add('GET', '/token-binding', [$controller, 'tokenBinding'], Protection::TOKEN_BINDING);
$router->add('GET', '/token-double-binding', [$controller, 'tokenDoubleBinding'], Protection::TOKEN_DOUBLE_BINDING);

$request = Request::fromGlobals();

$route = $router->match($request);

if($route === null) {
    Response::json(['error' => 'Not Found'], 404)->send();
    exit;
}

$response = $middleware->handle(
    $request,
    $route,
    static function (Request $request) use ($route): Response {
        $handler = $route->handler();
        return $handler($request);
    }
);

$response->send();
