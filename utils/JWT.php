<?php

/**
 * JSON Web Token (JWT)
 * @author   Jitendra Adhikari <jiten.adhikary@gmail.com>
 * @license  MIT
 *
 * @link     https://github.com/adhocore/jwt
 *
 * 示例1：
 * $appId = '17';
 * $appSecret = 'xxxx1111@@@@@@@@BBBBBBBBB';
 * $expireSecond = 3600;
 * $algo = 'HS256';
 * $leeway = 10;
 * $scopes = ['api/account/lists'];
 * $payload = ['iss' => 'account_center', 'exp' => time() + $expireSecond, 'appId' => $appId, 'scopes' => $scopes];
 * $jwt = new \JWT($appSecret, $algo, $expireSecond, $leeway);
 * $header = [];
 * $encode = $jwt->encode($payload, $header);
 * $decode = $jwt->decode($encode);
 * print_r(['encode' => $encode, 'decode' => $decode]);
 * 示例2：
 * $key = 'jwt/priv.key';
 * $algo = 'RS256';
 * $payload = ['iss' => 'account_center', 'exp' => time() + $expireSecond, 'appId' => $appId, 'scopes' => $scopes];
 * $jwt = new \JWT($key, $algo, $expireSecond, $leeway);
 * $encode = $jwt->encode($payload, $header);
 * $decode = $jwt->decode($encode);
 * print_r(['encode' => $encode, 'decode' => $decode]);
 *
 * $payload 提供设置：
 * iss：发行人
 * exp：到期时间
 * sub：主题
 * aud：用户
 * nbf：在此之前不可用
 * iat：发布时间
 * jti：JWT ID用于标识该JWT
 *
 */

class JWT
{

    const ERROR_KEY_EMPTY = 10;
    const ERROR_KEY_INVALID = 12;
    const ERROR_ALGO_UNSUPPORTED = 20;
    const ERROR_ALGO_MISSING = 22;
    const ERROR_INVALID_MAXAGE = 30;
    const ERROR_INVALID_LEEWAY = 32;
    const ERROR_JSON_FAILED = 40;
    const ERROR_TOKEN_INVALID = 50;
    const ERROR_TOKEN_EXPIRED = 52;
    const ERROR_TOKEN_NOT_NOW = 54;
    const ERROR_SIGNATURE_FAILED = 60;
    const ERROR_KID_UNKNOWN = 70;

    /** @var array Supported Signing algorithms. */
    protected $algos = [
        'HS256' => 'sha256',
        'HS384' => 'sha384',
        'HS512' => 'sha512',
        'RS256' => \OPENSSL_ALGO_SHA256,
        'RS384' => \OPENSSL_ALGO_SHA384,
        'RS512' => \OPENSSL_ALGO_SHA512,
    ];

    /** @var string|resource The signature key. */
    protected $key;

    /** @var array The list of supported keys with id. */
    protected $keys = [];

    /** @var int|null Use setTestTimestamp() to set custom value for time(). Useful for testability. */
    protected $timestamp = null;

    /** @var string The JWT signing algorithm. Defaults to HS256. */
    protected $algo = 'HS256';

    /** @var int The JWT TTL in seconds. Defaults to 1 hour. */
    protected $maxAge = 3600;

    /** @var int Grace period in seconds to allow for clock skew. Defaults to 0 seconds. */
    protected $leeway = 0;

    /** @var string|null The passphrase for RSA signing (optional). */
    protected $passphrase;

    /**
     * Constructor.
     *
     * @param string|resource $key The signature key. For RS* it should be file path or resource of private key.
     * @param string $algo The algorithm to sign/verify the token.
     * @param int $maxAge The TTL of token to be used to determine expiry if `iat` claim is present.
     *                                This is also used to provide default `exp` claim in case it is missing.
     * @param int $leeway Leeway for clock skew. Shouldnot be more than 2 minutes (120s). JWT过期后，还能使用时长
     * @param string $pass The passphrase (only for RS* algos).
     */
    public function __construct($key, string $algo = 'HS256', int $maxAge = 3600, int $leeway = 0, string $pass = null)
    {
        $this->validateConfig($key, $algo, $maxAge, $leeway);

        if (\is_array($key)) {
            $this->registerKeys($key);
            $key = \reset($key); // use first one!
        }

        $this->key = $key;
        $this->algo = $algo;
        $this->maxAge = $maxAge;
        $this->leeway = $leeway;
        $this->passphrase = $pass;
    }

    /**
     * Register keys for `kid` support.
     *
     * @param array $keys Use format: ['<kid>' => '<key data>', '<kid2>' => '<key data2>']
     *
     * @return self
     */
    public function registerKeys(array $keys): self
    {
        $this->keys = \array_merge($this->keys, $keys);

        return $this;
    }

    /**
     *
     * Encode payload as JWT token.
     *
     * @param array $payload
     * @param array $header Extra header (if any) to append.
     *
     * @return string URL safe JWT token.
     */
    public function encode(array $payload, array $header = []): string
    {
        $header = ['typ' => 'JWT', 'alg' => $this->algo] + $header;

        $this->validateKid($header);

        if (!isset($payload['iat']) && !isset($payload['exp'])) {
            $payload['exp'] = ($this->timestamp ?: \time()) + $this->maxAge;
        }

        $header = $this->urlSafeEncode($header);
        $payload = $this->urlSafeEncode($payload);
        $signature = $this->urlSafeEncode($this->sign($header . '.' . $payload));

        return $header . '.' . $payload . '.' . $signature;
    }

    /**
     * Decode JWT token and return original payload.
     *
     * @param string $token
     * @param bool $verify
     *
     * @return array
     * @throws \InvalidArgumentException
     *
     */
    public function decode(string $token, bool $verify = true): array
    {
        if (\substr_count($token, '.') < 2) {
            throw new \InvalidArgumentException('Invalid token: Incomplete segments', static::ERROR_TOKEN_INVALID);
        }

        $token = \explode('.', $token, 3);
        if (!$verify) {
            return (array)$this->urlSafeDecode($token[1]);
        }

        $this->validateHeader((array)$this->urlSafeDecode($token[0]));

        // Validate signature.
        if (!$this->verify($token[0] . '.' . $token[1], $token[2])) {
            throw new \InvalidArgumentException('Invalid token: Signature failed', static::ERROR_SIGNATURE_FAILED);
        }

        $payload = (array)$this->urlSafeDecode($token[1]);

        $this->validateTimestamps($payload);

        return $payload;
    }

    /**
     * Spoof current timestamp for testing.
     *
     * @param int|null $timestamp
     */
    public function setTestTimestamp(int $timestamp = null): self
    {
        $this->timestamp = $timestamp;

        return $this;
    }

    /**
     * Sign the input with configured key and return the signature.
     *
     * @param string $input
     *
     * @return string
     */
    protected function sign(string $input): string
    {
        // HMAC SHA.
        if (\substr($this->algo, 0, 2) === 'HS') {
            return \hash_hmac($this->algos[$this->algo], $input, $this->key, true);
        }

        $this->validateKey();

        \openssl_sign($input, $signature, $this->key, $this->algos[$this->algo]);

        return $signature;
    }

    /**
     * Verify the signature of given input.
     *
     * @param string $input
     * @param string $signature
     *
     * @return bool
     * @throws \InvalidArgumentException When key is invalid.
     *
     */
    protected function verify(string $input, string $signature): bool
    {
        $algo = $this->algos[$this->algo];

        // HMAC SHA.
        if (\substr($this->algo, 0, 2) === 'HS') {
            return \hash_equals($this->urlSafeEncode(\hash_hmac($algo, $input, $this->key, true)), $signature);
        }

        $this->validateKey();

        $pubKey = \openssl_pkey_get_details($this->key)['key'];

        return \openssl_verify($input, $this->urlSafeDecode($signature, false), $pubKey, $algo) === 1;
    }

    /**
     * URL safe base64 encode.
     *
     * First serialized the payload as json if it is an array.
     *
     * @param array|string $data
     *
     * @return string
     * @throws \InvalidArgumentException When JSON encode fails.
     *
     */
    protected function urlSafeEncode($data): string
    {
        if (\is_array($data)) {
            $data = \json_encode($data, \JSON_UNESCAPED_SLASHES);
            $this->validateLastJson();
        }
        // -_替换为+/
        return \rtrim(\strtr(\base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * URL safe base64 decode.
     *
     * @param array|string $data
     * @param bool $asJson Whether to parse as JSON (defaults to true).
     *
     * @return array|\stdClass|string
     * @throws \InvalidArgumentException When JSON encode fails.
     *
     */
    protected function urlSafeDecode($data, bool $asJson = true)
    {
        if (!$asJson) {
            return \base64_decode(\strtr($data, '-_', '+/'));
        }

        $data = \json_decode(\base64_decode(\strtr($data, '-_', '+/')));
        $this->validateLastJson();

        return $data;
    }


    /**
     * Throw up if input parameters invalid.
     *
     * @codeCoverageIgnore
     */
    protected function validateConfig($key, string $algo, int $maxAge, int $leeway)
    {
        if (empty($key)) {
            throw new \InvalidArgumentException('Signing key cannot be empty', static::ERROR_KEY_EMPTY);
        }

        if (!isset($this->algos[$algo])) {
            throw new \InvalidArgumentException('Unsupported algo ' . $algo, static::ERROR_ALGO_UNSUPPORTED);
        }

        if ($maxAge < 1) {
            throw new \InvalidArgumentException('Invalid maxAge: Should be greater than 0', static::ERROR_INVALID_MAXAGE);
        }

        if ($leeway < 0 || $leeway > 120) {
            throw new \InvalidArgumentException('Invalid leeway: Should be between 0-120', static::ERROR_INVALID_LEEWAY);
        }
    }

    /**
     * Throw up if header invalid.
     */
    protected function validateHeader(array $header)
    {
        if (empty($header['alg'])) {
            throw new \InvalidArgumentException('Invalid token: Missing header algo', static::ERROR_ALGO_MISSING);
        }
        if (empty($this->algos[$header['alg']])) {
            throw new \InvalidArgumentException('Invalid token: Unsupported header algo', static::ERROR_ALGO_UNSUPPORTED);
        }

        $this->validateKid($header);
    }

    /**
     * Throw up if kid exists and invalid.
     */
    protected function validateKid(array $header)
    {
        if (!isset($header['kid'])) {
            return;
        }
        if (empty($this->keys[$header['kid']])) {
            throw new \InvalidArgumentException('Invalid token: Unknown key ID', static::ERROR_KID_UNKNOWN);
        }

        $this->key = $this->keys[$header['kid']];
    }

    /**
     * Throw up if timestamp claims like iat, exp, nbf are invalid.
     */
    protected function validateTimestamps(array $payload)
    {
        $timestamp = $this->timestamp ?: \time();
        $checks = [
            ['exp', $this->leeway /*          */, static::ERROR_TOKEN_EXPIRED, 'Expired'],
            ['iat', $this->maxAge - $this->leeway, static::ERROR_TOKEN_EXPIRED, 'Expired'],
            ['nbf', -$this->leeway, static::ERROR_TOKEN_NOT_NOW, 'Not now'],
        ];

        foreach ($checks as list($key, $offset, $code, $error)) {
            if (isset($payload[$key])) {
                $offset += $payload[$key];
                $fail = $key === 'nbf' ? $timestamp <= $offset : $timestamp >= $offset;

                if ($fail) {
                    throw new \InvalidArgumentException('Invalid token: ' . $error, $code);
                }
            }
        }
    }

    /**
     * Throw up if key is not resource or file path to private key.
     */
    protected function validateKey()
    {
        if (\is_string($key = $this->key)) {
            if (\substr($key, 0, 7) !== 'file://') {
                $key = 'file://' . $key;
            }

            $this->key = \openssl_get_privatekey($key, $this->passphrase ?: '');
        }

        if (\PHP_VERSION_ID < 80000 && !\is_resource($this->key)) {
            throw new \InvalidArgumentException('Invalid key: Should be resource of private key', static::ERROR_KEY_INVALID);
        }

        if (\PHP_VERSION_ID > 80000 && !(
                $this->key instanceof \OpenSSLAsymmetricKey
                || $this->key instanceof \OpenSSLCertificate
                || $this->key instanceof \OpenSSLCertificateSigningRequest
            )) {
            throw new \InvalidArgumentException('Invalid key: Should be resource of private key', static::ERROR_KEY_INVALID);
        }
    }

    /**
     * Throw up if last json_encode/decode was a failure.
     */
    protected function validateLastJson()
    {
        if (\JSON_ERROR_NONE === \json_last_error()) {
            return;
        }

        throw new \InvalidArgumentException('JSON failed: ' . \json_last_error_msg(), static::ERROR_JSON_FAILED);
    }

}