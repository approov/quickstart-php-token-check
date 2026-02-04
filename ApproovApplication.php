<?php declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

Dotenv::createImmutable(__DIR__)->safeLoad();

final class Text
{
    public static function hasText(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}

final class Base64Url
{
    public static function decode(string $value): string
    {
        $normalized = strtr($value, '-_', '+/');
        $padding = strlen($normalized) % 4;
        if ($padding !== 0) {
            $normalized .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($normalized, true);
        if ($decoded === false) {
            throw new UnexpectedValueException('Invalid base64url data.');
        }

        return $decoded;
    }
}

final class ApproovSecret
{
    private ?string $secret = null;
    private ?string $error = null;
    private bool $loaded = false;

    public function __construct(private string $envName)
    {
    }

    public function secret(): string
    {
        $this->load();
        if ($this->secret === null) {
            throw new RuntimeException($this->error ?? 'Approov secret is missing.');
        }
        return $this->secret;
    }

    public function hasSecret(): bool
    {
        $this->load();
        return $this->secret !== null;
    }

    public function error(): ?string
    {
        $this->load();
        return $this->error;
    }

    public function logIfMissing(ApproovLogger $logger): void
    {
        if ($this->hasSecret()) {
            return;
        }

        $context = ['env' => $this->envName];
        if ($this->error !== null) {
            $context['error'] = $this->error;
        }
        $logger->warning('Approov secret missing.', $context);
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;

        $value = $this->readEnv();
        if ($value === null) {
            $this->error = "Missing environment variable: {$this->envName}";
            return;
        }

        try {
            $this->secret = Base64Url::decode(trim($value));
        } catch (Throwable $exception) {
            $this->error = $exception->getMessage();
        }
    }

    private function readEnv(): ?string
    {
        $value = getenv($this->envName);
        if (!Text::hasText($value)) {
            $value = $_ENV[$this->envName] ?? $_SERVER[$this->envName] ?? null;
        }

        return Text::hasText($value) ? (string) $value : null;
    }
}

final class Request
{
    private string $method;
    private string $path;
    /** @var array<string, string> */
    private array $headers;
    /** @var array<string, mixed> */
    private array $attributes = [];

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

        return new self($method, $path, self::collectHeaders());
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
        $value = $this->headers[strtolower($name)] ?? null;
        return Text::hasText($value) ? trim((string) $value) : null;
    }

    public function setAttribute(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    public function getAttribute(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    public function requestId(string $headerName, string $attributeName): ?string
    {
        $value = $this->getAttribute($attributeName);
        if (is_string($value) && $value !== '') {
            return $value;
        }

        $fromHeader = $this->header($headerName);
        if (!is_string($fromHeader)) {
            return null;
        }

        $trimmed = trim($fromHeader);
        return $trimmed === '' ? null : $trimmed;
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
                continue;
            }

            if (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $header = strtolower(str_replace('_', '-', $key));
                $headers[$header] = trim((string) $value);
            }
        }

        return $headers;
    }
}

final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        private int $status,
        private array $headers,
        private string $body
    ) {
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

    public function status(): int
    {
        return $this->status;
    }
}

final class ApproovLogger
{
    public function __construct(private string $channel = 'approov')
    {
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    /** @param array<string, mixed> $context */
    private function write(string $level, string $message, array $context): void
    {
        $line = $message;

        if ($level !== '') {
            $context = array_merge(['level' => $level], $context);
        }

        if (!empty($context)) {
            $payload = json_encode($context, JSON_UNESCAPED_SLASHES);
            if ($payload !== false) {
                $line .= ' ' . $payload;
            }
        }

        error_log($line);
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

    /** @param string[] $bindingHeaders */
    public function __construct(
        string $method,
        private string $path,
        /** @var callable */
        private $handler,
        private int $protection,
        private array $bindingHeaders = []
    ) {
        $this->method = strtoupper($method);
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

    /** @return string[] */
    public function bindingHeaders(): array
    {
        return $this->bindingHeaders;
    }
}

final class Router
{
    /** @var Route[] */
    private array $routes = [];

    /** @param string[] $bindingHeaders */
    public function add(
        string $method,
        string $path,
        callable $handler,
        int $protection = Protection::NONE,
        array $bindingHeaders = []
    ): void {
        $this->routes[] = new Route($method, $path, $handler, $protection, $bindingHeaders);
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
    public function __construct(private bool $approovEnabled, private bool $tokenBindingEnabled)
    {
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
    public function __construct(private string $path)
    {
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
            return [$this->defaultState(), true];
        }

        $data = json_decode($contents, true);
        if (!is_array($data)) {
            return [$this->defaultState(), true];
        }

        return [ApproovState::fromArray($data), false];
    }

    private function defaultState(): ApproovState
    {
        return new ApproovState(true, true);
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

final class ApproovTokenVerifier
{
    public function __construct(private ApproovSecret $secret)
    {
    }

    /** @return array<string, mixed> */
    public function verifyApproovToken(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new UnexpectedValueException('Invalid JWT format.');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $header = $this->decodeJwtPart($encodedHeader);
        $payload = $this->decodeJwtPart($encodedPayload);

        $algorithm = $header['alg'] ?? null;
        $hashAlgorithm = $this->mapJwtAlgorithm(is_string($algorithm) ? $algorithm : null);

        $signature = Base64Url::decode($encodedSignature);
        $signingInput = $encodedHeader . '.' . $encodedPayload;
        $expected = hash_hmac($hashAlgorithm, $signingInput, $this->secret->secret(), true);

        if (!hash_equals($expected, $signature)) {
            throw new UnexpectedValueException('Invalid JWT signature.');
        }

        $this->validateExpiration($payload);
        return $payload;
    }

    /** @param array<string, mixed> $claims */
    public function isBindingValid(string $bindingValue, array $claims): bool
    {
        $expected = $claims['pay'] ?? null;
        if (!Text::hasText($expected)) {
            return false;
        }

        $computed = $this->hashBase64($bindingValue);
        return hash_equals(trim((string) $expected), $computed);
    }

    /** @param array<string, mixed> $claims */
    private function validateExpiration(array $claims): void
    {
        if (!array_key_exists('exp', $claims)) {
            throw new UnexpectedValueException('Approov token missing expiration.');
        }

        $expiration = (int) $claims['exp'];
        if ($expiration <= time()) {
            throw new UnexpectedValueException('Approov token expired.');
        }
    }

    /** @return array<string, mixed> */
    private function decodeJwtPart(string $value): array
    {
        $decoded = Base64Url::decode($value);
        $json = json_decode($decoded, true);
        if (!is_array($json)) {
            throw new UnexpectedValueException('Invalid JWT payload.');
        }
        return $json;
    }

    private function hashBase64(string $value): string
    {
        return base64_encode(hash('sha256', $value, true));
    }

    private function mapJwtAlgorithm(?string $algorithm): string
    {
        return match ($algorithm) {
            'HS256' => 'sha256',
            default => throw new UnexpectedValueException('Unsupported JWT algorithm.'),
        };
    }
}

final class ApproovTokenMiddleware
{
    private const APPROOV_HEADER = 'Approov-Token';
    private const REQUEST_ID_HEADER = 'X-Request-Id';
    private const REQUEST_ID_ATTRIBUTE = 'request_id';
    private const APPROOV_REQUIRED_HEADERS_ATTRIBUTE = 'approov_required_headers';
    private const APPROOV_FAILURE_ATTRIBUTE = 'approov_failure';

    public function __construct(
        private ApproovStateStore $stateStore,
        private ApproovTokenVerifier $validator,
        private ApproovSecret $secret,
        private ApproovLogger $logger
    ) {
    }

    /** @param callable(Request): Response $next */
    public function handle(Request $request, Route $route, callable $next): Response
    {
        if (!Protection::requiresToken($route->protection())) {
            return $next($request);
        }

        $bindingHeaders = $this->normalizeBindingHeaders($route->bindingHeaders());
        $state = $this->stateStore->getState();

        if ($state->approovEnabled()) {
            $this->secret->logIfMissing($this->logger);
            $request->setAttribute(
                self::APPROOV_REQUIRED_HEADERS_ATTRIBUTE,
                $this->requiredHeaders($state, $bindingHeaders)
            );
        } else {
            $request->setAttribute('approov_auth', $this->disabledAuthentication());
            return $next($request);
        }

        $rawToken = $request->header(self::APPROOV_HEADER);
        if (!Text::hasText($rawToken)) {
            return $this->unauthorized($request, $state, 'missing_approov_token', $bindingHeaders);
        }

        try {
            $claims = $this->validator->verifyApproovToken(trim($rawToken));

            if ($state->tokenBindingEnabled() && $bindingHeaders !== []) {
                $bindingValue = $this->extractBindingValue($request, $bindingHeaders);
                if (!Text::hasText($bindingValue)) {
                    return $this->unauthorized($request, $state, 'missing_binding_header', $bindingHeaders);
                }
                if (!$this->validator->isBindingValid($bindingValue, $claims)) {
                    return $this->unauthorized($request, $state, 'binding_mismatch', $bindingHeaders);
                }
            }

            $request->setAttribute('approov_auth', ['principal' => 'approov-token']);
            return $next($request);
        } catch (Throwable $exception) {
            return $this->unauthorized($request, $state, 'token_verification_failed', $bindingHeaders, [
                'error' => $exception->getMessage(),
                'exception' => get_class($exception),
            ]);
        }
    }

    /** @param string[] $bindingHeaders
     *  @param array<string, mixed> $context
     */
    private function unauthorized(
        Request $request,
        ApproovState $state,
        string $reason,
        array $bindingHeaders = [],
        array $context = []
    ): Response {
        $context = array_merge($this->baseLogContext($request, $state, $reason, $bindingHeaders), $context);
        $request->setAttribute(self::APPROOV_FAILURE_ATTRIBUTE, $context);

        return Response::json(['message' => 'Approov authentication failed.'], 401);
    }

    /** @param string[] $bindingHeaders
     *  @return array<string, mixed>
     */
    private function baseLogContext(Request $request, ApproovState $state, string $reason, array $bindingHeaders): array
    {
        $context = [
            'reason' => $reason,
            'method' => $request->method(),
            'path' => $request->path(),
            'approov' => [
                'enabled' => $state->approovEnabled(),
                'binding_enabled' => $state->tokenBindingEnabled(),
                'headers' => $this->approovHeaderFlags($request, $state, $bindingHeaders),
            ],
        ];

        $requestId = $request->requestId(self::REQUEST_ID_HEADER, self::REQUEST_ID_ATTRIBUTE);
        if ($requestId !== null) {
            $context['request_id'] = $requestId;
        }

        return $context;
    }

    /** @param string[] $bindingHeaders
     *  @return array<string, mixed>
     */
    private function approovHeaderFlags(Request $request, ApproovState $state, array $bindingHeaders): array
    {
        $flags = [
            'approov_token' => Text::hasText($request->header(self::APPROOV_HEADER)),
        ];

        if ($state->tokenBindingEnabled() && $bindingHeaders !== []) {
            $bindingFlags = [];
            foreach ($bindingHeaders as $header) {
                $bindingFlags[$header] = Text::hasText($request->header($header));
            }
            $flags['binding_headers'] = $bindingFlags;
        }

        return $flags;
    }

    /** @param string[] $bindingHeaders
     *  @return string[]
     */
    private function requiredHeaders(ApproovState $state, array $bindingHeaders): array
    {
        $headers = [self::APPROOV_HEADER];

        if (!$state->tokenBindingEnabled() || $bindingHeaders === []) {
            return $headers;
        }

        return array_merge($headers, $bindingHeaders);
    }

    /** @param string[] $bindingHeaders */
    private function extractBindingValue(Request $request, array $bindingHeaders): ?string
    {
        $values = [];
        foreach ($bindingHeaders as $header) {
            $value = $this->trimOrNull($request->header($header));
            if (!Text::hasText($value)) {
                return null;
            }
            $values[] = $value;
        }

        return implode('', $values);
    }

    private function disabledAuthentication(): array
    {
        return ['principal' => 'approov-disabled'];
    }

    private function trimOrNull(?string $value): ?string
    {
        return $value === null ? null : trim($value);
    }

    /** @param string[] $headers
     *  @return string[]
     */
    private function normalizeBindingHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $header) {
            if (!is_string($header)) {
                continue;
            }
            $trimmed = trim($header);
            if ($trimmed === '') {
                continue;
            }
            $normalized[] = $trimmed;
        }

        return $normalized;
    }
}

final class RequestCompletionLogger
{
    private const REQUEST_ID_HEADER = 'X-Request-Id';
    private const REQUEST_ID_ATTRIBUTE = 'request_id';
    private const APPROOV_REQUIRED_HEADERS_ATTRIBUTE = 'approov_required_headers';
    private const APPROOV_FAILURE_ATTRIBUTE = 'approov_failure';

    public function __construct(private ApproovLogger $logger, private ApproovStateStore $stateStore)
    {
    }

    public function log(Request $request, Response $response): void
    {
        $status = $response->status();
        if ($status !== 200 && $status !== 401) {
            return;
        }

        $state = $this->stateStore->getState();

        $context = [
            'summary' => $this->summary($request, $status),
            'method' => $request->method(),
            'path' => $request->path(),
            'status' => $status,
            'ip' => $this->clientIp(),
            'port' => $this->serverPort(),
            'approovEnabled' => $state->approovEnabled(),
            'tokenBindingEnabled' => $state->tokenBindingEnabled(),
        ];

        $requiredHeaders = $request->getAttribute(self::APPROOV_REQUIRED_HEADERS_ATTRIBUTE);
        if (is_array($requiredHeaders) && $requiredHeaders !== []) {
            $context['required_headers'] = array_values($requiredHeaders);
        }

        $requestId = $request->requestId(self::REQUEST_ID_HEADER, self::REQUEST_ID_ATTRIBUTE);
        if ($requestId !== null) {
            $context['request_id'] = $requestId;
        }

        $this->logger->info('http.request.completed', $context);
    }

    private function summary(Request $request, int $status): string
    {
        if ($status === 401) {
            $failure = $request->getAttribute(self::APPROOV_FAILURE_ATTRIBUTE);
            if (is_array($failure)) {
                $reason = $failure['reason'] ?? null;
                if (Text::hasText($reason)) {
                    return 'approov_failed:' . $reason;
                }
            }
            return 'approov_failed:unauthorized';
        }

        $auth = $request->getAttribute('approov_auth');
        if (is_array($auth)) {
            $principal = $auth['principal'] ?? null;
            if ($principal === 'approov-token') {
                return 'approov_ok';
            }
            if ($principal === 'approov-disabled') {
                return 'approov_disabled';
            }
        }

        return 'ok';
    }

    private function clientIp(): ?string
    {
        $value = $_SERVER['REMOTE_ADDR'] ?? null;
        return Text::hasText($value) ? (string) $value : null;
    }

    private function serverPort(): ?int
    {
        $value = $_SERVER['SERVER_PORT'] ?? $_SERVER['HTTP_PORT'] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        return null;
    }
}

final class ApproovController
{
    public function __construct(private ApproovStateStore $stateStore)
    {
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
        $payload['sessionIdHeaderPresent'] = Text::hasText($request->header('SessionId'));
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

$secret = new ApproovSecret('APPROOV_BASE64URL_SECRET');
$logger = new ApproovLogger();
$stateStore = new ApproovStateStore(__DIR__ . '/var/approov_state.json');
$validator = new ApproovTokenVerifier($secret);
$middleware = new ApproovTokenMiddleware($stateStore, $validator, $secret, $logger);
$controller = new ApproovController($stateStore);
$requestLogger = new RequestCompletionLogger($logger, $stateStore);

$router = new Router();
$router->add('GET', '/', [$controller, 'home']);
$router->add('GET', '/approov-state', [$controller, 'approovState']);
$router->add('POST', '/approov/enable', [$controller, 'enableApproov']);
$router->add('POST', '/approov/disable', [$controller, 'disableApproov']);
$router->add('POST', '/token-binding/enable', [$controller, 'enableTokenBinding']);
$router->add('POST', '/token-binding/disable', [$controller, 'disableTokenBinding']);
$router->add('GET', '/unprotected', [$controller, 'unprotected']);
$router->add('GET', '/token-check', [$controller, 'tokenCheck'], Protection::TOKEN);
$router->add('GET', '/token-binding', [$controller, 'tokenBinding'], Protection::TOKEN_BINDING, ['Authorization']);
$router->add('GET', '/token-double-binding', [$controller, 'tokenDoubleBinding'], Protection::TOKEN_DOUBLE_BINDING, ['Authorization', 'SessionId']);

$request = Request::fromGlobals();

$route = $router->match($request);

if ($route === null) {
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

$requestLogger->log($request, $response);
$response->send();
