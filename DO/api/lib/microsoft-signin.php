<?php
declare(strict_types=1);

/**
 * Delegated Microsoft sign-in for Pivot, shared by every feature that calls
 * Graph as the signed-in person (the scorecard sync, the org chart calendar).
 *
 * It uses the device code flow with the public client api/teams.php already
 * uses, so there is no client secret and no redirect URI to register. Tokens
 * live only in the PHP session. The refresh token is redeemed per feature, so
 * each feature gets an access token for just its own scopes.
 */

final class NotSignedIn extends RuntimeException
{
}

interface TokenProvider
{
    /** @return array{upn: string, name: string, oid: string}|null */
    public function user(): ?array;

    /** @throws NotSignedIn */
    public function accessToken(string $feature): string;
}

final class MicrosoftSignIn implements TokenProvider
{
    public const CLOUDS = [
        'commercial' => ['login' => 'https://login.microsoftonline.com', 'graph' => 'https://graph.microsoft.com'],
        'usgov-gcc' => ['login' => 'https://login.microsoftonline.com', 'graph' => 'https://graph.microsoft.com'],
        'usgov-high' => ['login' => 'https://login.microsoftonline.us', 'graph' => 'https://graph.microsoft.us'],
        'usgov-dod' => ['login' => 'https://login.microsoftonline.us', 'graph' => 'https://dod-graph.microsoft.us'],
    ];

    private const KEY = 'microsoft';

    private array $hosts;
    private Closure $post;
    private Closure $now;

    /**
     * @param array $config the 'microsoft' section of api/lib/microsoft-config.php
     * @param array $session usually $_SESSION, by reference
     */
    public function __construct(private array $config, private array &$session, ?callable $post = null, ?callable $now = null)
    {
        $cloud = (string) ($config['cloud'] ?? '');
        if (!isset(self::CLOUDS[$cloud])) {
            throw new InvalidArgumentException('Unknown Microsoft cloud "' . $cloud . '".');
        }
        if (!preg_match('/^[0-9a-f-]{36}$/i', (string) ($config['tenant'] ?? '')) || !preg_match('/^[0-9a-f-]{36}$/i', (string) ($config['client'] ?? ''))) {
            throw new InvalidArgumentException('Microsoft tenant and client must be GUIDs.');
        }
        $this->hosts = self::CLOUDS[$cloud];
        $this->post = $post !== null ? Closure::fromCallable($post) : Closure::fromCallable([self::class, 'formPost']);
        $this->now = $now !== null ? Closure::fromCallable($now) : static fn (): int => time();
    }

    public function authority(): string
    {
        return $this->hosts['login'];
    }

    public function graphBase(): string
    {
        return $this->hosts['graph'] . '/v1.0';
    }

    public function scopesFor(string $feature): string
    {
        $names = $this->config['scopes'][$feature] ?? null;
        if (!is_array($names) || $names === []) {
            throw new InvalidArgumentException('No Microsoft scopes are configured for "' . $feature . '".');
        }
        return implode(' ', array_map(fn (string $name): string => $this->hosts['graph'] . '/' . $name, $names)) . ' offline_access';
    }

    public function loginScopes(): string
    {
        $scopes = ['openid', 'profile', 'offline_access', $this->hosts['graph'] . '/User.Read'];
        foreach ($this->config['scopes'] ?? [] as $names) {
            foreach ((array) $names as $name) {
                $scopes[] = $this->hosts['graph'] . '/' . $name;
            }
        }
        return implode(' ', array_values(array_unique($scopes)));
    }

    /** @return array{user_code: string, url: string, complete: string, interval: int} */
    public function startLogin(): array
    {
        $res = ($this->post)($this->endpoint('devicecode'), [
            'client_id' => $this->config['client'],
            'scope' => $this->loginScopes(),
        ]);
        $data = $res['data'] ?? [];
        $userCode = (string) ($data['user_code'] ?? '');
        $deviceCode = (string) ($data['device_code'] ?? '');
        if (($res['status'] ?? 0) !== 200 || !preg_match('/^[A-Z0-9]{4,16}$/', $userCode) || $deviceCode === '') {
            $reason = (string) ($data['error_description'] ?? $data['error'] ?? '');
            throw new RuntimeException($reason !== '' ? 'Microsoft sign-in did not start. ' . strtok($reason, "\r\n") : 'Microsoft sign-in did not start. Try again.');
        }
        $now = ($this->now)();
        $interval = max(5, (int) ($data['interval'] ?? 5));
        $this->session[self::KEY . '_device'] = [
            'code' => $deviceCode,
            'expires' => $now + (int) ($data['expires_in'] ?? 900),
            'next' => $now + $interval,
            'interval' => $interval,
        ];
        $url = (string) ($data['verification_uri'] ?? $this->hosts['login'] . '/common/oauth2/deviceauth');
        if (!str_starts_with($url, $this->hosts['login'] . '/') && !str_starts_with($url, 'https://microsoft.com/') && !str_starts_with($url, 'https://login.microsoft.com/')) {
            $url = $this->hosts['login'] . '/common/oauth2/deviceauth';
        }
        $complete = (string) ($data['verification_uri_complete'] ?? '');
        if ($complete === '' || !str_starts_with($complete, $this->hosts['login'] . '/')) {
            $complete = $url . (str_contains($url, '?') ? '&' : '?') . 'user_code=' . rawurlencode($userCode);
        }
        return ['user_code' => $userCode, 'url' => $url, 'complete' => $complete, 'interval' => $interval];
    }

    /**
     * @return array{pending: true}|array{signedIn: true, user: array}
     * @throws NotSignedIn when the code expired, was declined, or came back for another tenant
     */
    public function pollLogin(): array
    {
        $device = $this->session[self::KEY . '_device'] ?? null;
        if (!is_array($device)) {
            $user = $this->user();
            if ($user !== null) {
                return ['signedIn' => true, 'user' => $user];
            }
            throw new NotSignedIn('Microsoft sign-in has not started.');
        }
        $now = ($this->now)();
        if ($now > (int) $device['expires']) {
            unset($this->session[self::KEY . '_device']);
            throw new NotSignedIn('That Microsoft sign-in expired. Start it again.');
        }
        if ($now < (int) $device['next']) {
            return ['pending' => true];
        }
        $this->session[self::KEY . '_device']['next'] = $now + (int) $device['interval'];
        $res = ($this->post)($this->endpoint('token'), [
            'client_id' => $this->config['client'],
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            'device_code' => $device['code'],
        ]);
        $data = $res['data'] ?? [];
        if (($res['status'] ?? 0) === 200 && !empty($data['access_token'])) {
            unset($this->session[self::KEY . '_device']);
            $user = $this->userFromIdToken((string) ($data['id_token'] ?? ''));
            if ($user === null) {
                throw new NotSignedIn('That Microsoft account is not in the Defence tenant.');
            }
            $tokens = [];
            foreach (array_keys($this->config['scopes'] ?? []) as $feature) {
                $tokens[$feature] = ['access' => (string) $data['access_token'], 'expires' => $now + (int) ($data['expires_in'] ?? 3600)];
            }
            $this->session[self::KEY] = [
                'refresh' => (string) ($data['refresh_token'] ?? ''),
                'user' => $user,
                'tokens' => $tokens,
            ];
            return ['signedIn' => true, 'user' => $user];
        }
        $error = (string) ($data['error'] ?? '');
        if ($error === 'authorization_pending') {
            return ['pending' => true];
        }
        if ($error === 'slow_down') {
            $this->session[self::KEY . '_device']['interval'] = (int) $device['interval'] + 5;
            $this->session[self::KEY . '_device']['next'] = $now + (int) $device['interval'] + 5;
            return ['pending' => true];
        }
        unset($this->session[self::KEY . '_device']);
        if ($error === 'access_denied' || $error === 'authorization_declined') {
            throw new NotSignedIn('The Microsoft sign-in was declined, or an administrator has to allow these permissions first.');
        }
        throw new NotSignedIn('Microsoft did not accept this sign-in. Start it again.');
    }

    public function user(): ?array
    {
        $user = $this->session[self::KEY]['user'] ?? null;
        return is_array($user) && ($user['upn'] ?? '') !== '' ? $user : null;
    }

    public function accessToken(string $feature): string
    {
        $state = $this->session[self::KEY] ?? null;
        if (!is_array($state) || $this->user() === null) {
            throw new NotSignedIn('Sign in with Microsoft first.');
        }
        $now = ($this->now)();
        $token = $state['tokens'][$feature] ?? null;
        if (is_array($token) && ($token['access'] ?? '') !== '' && $now < (int) $token['expires'] - 60) {
            return (string) $token['access'];
        }
        if (($state['refresh'] ?? '') === '') {
            $this->forget();
            throw new NotSignedIn('Sign in with Microsoft again.');
        }
        $res = ($this->post)($this->endpoint('token'), [
            'client_id' => $this->config['client'],
            'grant_type' => 'refresh_token',
            'refresh_token' => $state['refresh'],
            'scope' => $this->scopesFor($feature),
        ]);
        $data = $res['data'] ?? [];
        if (($res['status'] ?? 0) === 200 && !empty($data['access_token'])) {
            $this->session[self::KEY]['tokens'][$feature] = ['access' => (string) $data['access_token'], 'expires' => $now + (int) ($data['expires_in'] ?? 3600)];
            if (!empty($data['refresh_token'])) {
                $this->session[self::KEY]['refresh'] = (string) $data['refresh_token'];
            }
            return (string) $data['access_token'];
        }
        $error = (string) ($data['error'] ?? '');
        if ($error === 'invalid_grant' || $error === 'interaction_required') {
            $this->forget();
            throw new NotSignedIn('Sign in with Microsoft again.');
        }
        if ($error === 'consent_required' || $error === 'invalid_scope') {
            throw new NotSignedIn('An administrator has to allow ' . implode(', ', (array) ($this->config['scopes'][$feature] ?? [])) . ' for this sign-in.');
        }
        throw new RuntimeException('Microsoft sign-in could not be refreshed. Try again.');
    }

    public function forget(): void
    {
        unset($this->session[self::KEY], $this->session[self::KEY . '_device']);
    }

    private function endpoint(string $name): string
    {
        return $this->hosts['login'] . '/' . $this->config['tenant'] . '/oauth2/v2.0/' . $name;
    }

    /**
     * The id token comes straight from the token endpoint over TLS, so its claims
     * are read without checking the signature; the tenant claim must still match.
     */
    private function userFromIdToken(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) < 2) {
            return null;
        }
        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);
        if (!is_array($claims) || strtolower((string) ($claims['tid'] ?? '')) !== strtolower((string) $this->config['tenant'])) {
            return null;
        }
        $upn = strtolower(trim((string) ($claims['preferred_username'] ?? $claims['upn'] ?? $claims['email'] ?? '')));
        if ($upn === '') {
            return null;
        }
        return ['upn' => $upn, 'name' => trim((string) ($claims['name'] ?? '')), 'oid' => (string) ($claims['oid'] ?? '')];
    }

    private static function formPost(string $url, array $fields): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode((string) $raw, true);
        return ['status' => $status, 'data' => is_array($data) ? $data : []];
    }
}
