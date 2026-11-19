<?php

declare(strict_types=1);

// Dependencies first — class_exists() is already true once this file is compiled,
// so an early return above these requires would skip them forever.
require_once __DIR__ . '/MailAllowlist.php';
require_once __DIR__ . '/MailGateway.php';

/**
 * Microsoft Graph / M365 integration surface used by the MVC models.
 *
 * Local demos run in "outbox" mode (no live tenant). Set graph.mode=live and
 * supply AAD app credentials to call Graph for mail, calendar, and files.
 * Service-account (app-only) tokens cover SharePoint / CRM automation.
 */
final class GraphGateway
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly string $root,
        private readonly array $config,
    ) {
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $graph = is_array($this->config['graph'] ?? null) ? $this->config['graph'] : [];
        $auth = $this->authConfig();
        $user = $this->currentUser();
        $live = $user !== null && (($user['auth'] ?? '') === 'oidc' || ($user['access_token'] ?? '') !== '');

        return [
            'ok' => true,
            'mode' => $live ? 'live' : (string) ($graph['mode'] ?? 'outbox'),
            'tenant' => (string) ($auth['tenant'] ?? $graph['tenant'] ?? ''),
            'client_id' => (string) ($auth['client_id'] ?? ''),
            'auth_mode' => (string) ($auth['mode'] ?? (($auth['client_id'] ?? '') !== '' ? 'live' : 'demo')),
            'scopes' => is_array($graph['scopes'] ?? null)
                ? array_values($graph['scopes'])
                : ['User.Read', 'Mail.Send', 'Calendars.ReadWrite', 'Files.ReadWrite.All', 'Sites.ReadWrite.All'],
            'service_account' => [
                'enabled' => (bool) ($graph['service_account']['enabled'] ?? true),
                'mode' => (string) ($graph['service_account']['mode'] ?? 'app_only'),
            ],
            'signed_in' => $user !== null,
            'user' => $user,
        ];
    }

    /** @return array<string, mixed>|null */
    public function currentUser(): ?array
    {
        $path = $this->root . '/data/session.json';
        if (!is_file($path)) {
            return null;
        }
        $raw = json_decode((string) file_get_contents($path), true);

        return is_array($raw) && isset($raw['user']) && is_array($raw['user']) ? $raw['user'] : null;
    }

    /**
     * Start Azure AD authorize redirect (authorization code + PKCE).
     *
     * @return array<string, mixed>
     */
    public function beginOidcLogin(string $redirectUri): array
    {
        $auth = $this->authConfig();
        $clientId = trim((string) ($auth['client_id'] ?? ''));
        $tenant = trim((string) ($auth['tenant'] ?? 'common')) ?: 'common';
        if ($clientId === '') {
            return ['ok' => false, 'error' => 'auth.client_id is not configured'];
        }
        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $state = bin2hex(random_bytes(16));
        $this->writeOauthState([
            'state' => $state,
            'verifier' => $verifier,
            'redirect_uri' => $redirectUri,
            'at' => gmdate('c'),
        ]);
        $scope = rawurlencode('openid profile email offline_access User.Read Mail.Send');
        $url = "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/authorize"
            . '?client_id=' . rawurlencode($clientId)
            . '&response_type=code'
            . '&redirect_uri=' . rawurlencode($redirectUri)
            . '&response_mode=query'
            . '&scope=' . $scope
            . '&state=' . rawurlencode($state)
            . '&code_challenge=' . rawurlencode($challenge)
            . '&code_challenge_method=S256'
            . '&prompt=select_account';

        return ['ok' => true, 'url' => $url];
    }

    /**
     * Exchange ?code= for tokens and load Graph /me.
     *
     * @return array<string, mixed>
     */
    public function completeOidcLogin(string $code, string $state, string $redirectUri): array
    {
        if ($code === '' || $state === '') {
            return ['ok' => false, 'error' => 'missing code or state'];
        }
        $pending = $this->readOauthState();
        if ($pending === null || (string) ($pending['state'] ?? '') !== $state) {
            return ['ok' => false, 'error' => 'invalid or expired OAuth state'];
        }
        $this->clearOauthState();
        $auth = $this->authConfig();
        $clientId = trim((string) ($auth['client_id'] ?? ''));
        $tenant = trim((string) ($auth['tenant'] ?? 'common')) ?: 'common';
        $verifier = (string) ($pending['verifier'] ?? '');
        $redir = (string) ($pending['redirect_uri'] ?? $redirectUri);
        $tokenUrl = "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token";
        $body = http_build_query([
            'client_id' => $clientId,
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redir,
            'code_verifier' => $verifier,
            'scope' => 'openid profile email offline_access User.Read Mail.Send',
        ]);
        $token = $this->httpJson('POST', $tokenUrl, $body, ['Content-Type: application/x-www-form-urlencoded']);
        if (empty($token['ok'])) {
            return ['ok' => false, 'error' => (string) ($token['error'] ?? 'token request failed')];
        }
        $payload = is_array($token['json'] ?? null) ? $token['json'] : [];
        $access = (string) ($payload['access_token'] ?? '');
        if ($access === '') {
            return ['ok' => false, 'error' => (string) ($payload['error_description'] ?? $payload['error'] ?? 'no access_token')];
        }
        $me = $this->httpJson('GET', 'https://graph.microsoft.com/v1.0/me', null, [
            'Authorization: Bearer ' . $access,
        ]);
        $profile = is_array($me['json'] ?? null) ? $me['json'] : [];
        $mail = (string) ($profile['mail'] ?? $profile['userPrincipalName'] ?? '');
        $name = (string) ($profile['displayName'] ?? $mail);
        $oid = (string) ($profile['id'] ?? '');

        return $this->signIn([
            'oid' => $oid !== '' ? $oid : 'aad-user',
            'name' => $name !== '' ? $name : 'Microsoft user',
            'preferred_username' => $mail,
            'tid' => $tenant,
            'auth' => 'oidc',
            'access_token' => $access,
            'refresh_token' => (string) ($payload['refresh_token'] ?? ''),
            'id_token' => (string) ($payload['id_token'] ?? ''),
        ]);
    }

    /**
     * Write an M365 user session (demo or live OIDC).
     *
     * @param array<string, mixed> $claims
     * @return array<string, mixed>
     */
    public function signIn(array $claims = []): array
    {
        $user = [
            'id' => (string) ($claims['oid'] ?? 'demo-user'),
            'displayName' => (string) ($claims['name'] ?? 'Demo M365 User'),
            'mail' => (string) ($claims['preferred_username'] ?? $claims['mail'] ?? 'demo.user@forces.gc.ca'),
            'tenant' => (string) ($claims['tid'] ?? ($this->authConfig()['tenant'] ?? 'common')),
            'auth' => (string) ($claims['auth'] ?? 'oidc_demo'),
        ];
        if (!empty($claims['access_token'])) {
            $user['access_token'] = (string) $claims['access_token'];
        }
        if (!empty($claims['refresh_token'])) {
            $user['refresh_token'] = (string) $claims['refresh_token'];
        }
        $dir = $this->root . '/data';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents(
            $dir . '/session.json',
            json_encode(['user' => $user, 'at' => gmdate('c')], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );

        return ['ok' => true, 'user' => $user];
    }

    public function signOut(): array
    {
        $path = $this->root . '/data/session.json';
        if (is_file($path)) {
            unlink($path);
        }
        $this->clearOauthState();

        return ['ok' => true];
    }

    /** @return array<string, mixed> */
    private function authConfig(): array
    {
        $auth = is_array($this->config['auth'] ?? null) ? $this->config['auth'] : [];
        $local = $this->root . '/data/aad_config.json';
        if (is_file($local)) {
            $overlay = json_decode((string) file_get_contents($local), true);
            if (is_array($overlay)) {
                $auth = array_merge($auth, $overlay);
            }
        }
        if (empty($auth['client_id']) && getenv('AAD_CLIENT_ID')) {
            $auth['client_id'] = (string) getenv('AAD_CLIENT_ID');
            $auth['mode'] = $auth['mode'] ?? 'live';
        }
        if (empty($auth['tenant']) && getenv('AAD_TENANT')) {
            $auth['tenant'] = (string) getenv('AAD_TENANT');
        }

        return $auth;
    }

    /** @param array<string, mixed> $state */
    private function writeOauthState(array $state): void
    {
        $dir = $this->root . '/data';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($dir . '/oauth_state.json', json_encode($state, JSON_PRETTY_PRINT) . "\n");
    }

    /** @return array<string, mixed>|null */
    private function readOauthState(): ?array
    {
        $path = $this->root . '/data/oauth_state.json';
        if (!is_file($path)) {
            return null;
        }
        $raw = json_decode((string) file_get_contents($path), true);

        return is_array($raw) ? $raw : null;
    }

    private function clearOauthState(): void
    {
        $path = $this->root . '/data/oauth_state.json';
        if (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * @param list<string> $headers
     * @return array{ok:bool,json?:array<string,mixed>,error?:string,status?:int}
     */
    private function httpJson(string $method, string $url, ?string $body, array $headers): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'curl extension required for Microsoft login'];
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'error' => 'curl_init failed'];
        }
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if (!is_string($raw)) {
            return ['ok' => false, 'error' => $err !== '' ? $err : 'empty response', 'status' => $code];
        }
        $json = json_decode($raw, true);
        if ($code >= 200 && $code < 300) {
            return ['ok' => true, 'json' => is_array($json) ? $json : [], 'status' => $code];
        }

        return [
            'ok' => false,
            'status' => $code,
            'error' => is_array($json)
                ? (string) ($json['error_description'] ?? $json['error']['message'] ?? $json['error'] ?? $raw)
                : ($err !== '' ? $err : $raw),
            'json' => is_array($json) ? $json : [],
        ];
    }

    /**
     * Send mail through Graph (live) or the allowlisted outbox (demo).
     *
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    public function sendMail(array $message): array
    {
        $mode = strtolower((string) (($this->config['graph']['mode'] ?? null) ?: 'outbox'));
        $message['connector'] = (string) ($message['connector'] ?? 'Microsoft.Graph');
        $message['operation'] = (string) ($message['operation'] ?? 'sendMail');
        $user = $this->currentUser();
        $hasLiveToken = is_array($user) && !empty($user['access_token']);
        if ($mode === 'live' || $hasLiveToken) {
            return $this->liveSendMail($message);
        }
        $gateway = new MailGateway($this->root, $this->config, MailAllowlist::fromConfig($this->config));

        return $gateway->send($message);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function listCalendar(array $query = []): array
    {
        return [
            'ok' => true,
            'source' => 'graph.calendar',
            'mode' => (string) (($this->config['graph']['mode'] ?? null) ?: 'outbox'),
            'events' => [],
            'note' => 'Wire graph.mode=live + Calendars.ReadWrite to list tenant events.',
            'query' => $query,
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function listFiles(array $query = []): array
    {
        return [
            'ok' => true,
            'source' => 'graph.drive',
            'mode' => (string) (($this->config['graph']['mode'] ?? null) ?: 'outbox'),
            'files' => [],
            'note' => 'Wire graph.mode=live + Files.ReadWrite.All to list OneDrive/SharePoint files.',
            'query' => $query,
        ];
    }

    /**
     * App-only service account path for SharePoint / CRM automation.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function serviceAccount(array $body): array
    {
        $sa = is_array($this->config['graph']['service_account'] ?? null)
            ? $this->config['graph']['service_account']
            : [];
        $action = (string) ($body['action'] ?? 'token');

        return [
            'ok' => true,
            'enabled' => (bool) ($sa['enabled'] ?? true),
            'mode' => (string) ($sa['mode'] ?? 'app_only'),
            'action' => $action,
            'resource' => (string) ($body['resource'] ?? $sa['resource'] ?? 'https://graph.microsoft.com'),
            'note' => 'Non-interactive client-credentials token for SharePoint list / CRM sync. Demo mode does not call AAD.',
        ];
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    private function liveSendMail(array $message): array
    {
        $user = $this->currentUser();
        $token = (string) (
            ($user['access_token'] ?? null)
            ?: ($this->config['graph']['access_token'] ?? null)
            ?: getenv('GRAPH_ACCESS_TOKEN')
            ?: ''
        );
        if ($token === '') {
            return [
                'ok' => false,
                'error' => 'Sign in with Microsoft (or set GRAPH_ACCESS_TOKEN) to send live Graph mail',
            ];
        }
        $payload = json_encode([
            'message' => [
                'subject' => (string) ($message['subject'] ?? ''),
                'body' => [
                    'contentType' => 'HTML',
                    'content' => (string) ($message['body'] ?? ''),
                ],
                'toRecipients' => $this->recipients((string) ($message['to'] ?? '')),
            ],
            'saveToSentItems' => true,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $ch = curl_init('https://graph.microsoft.com/v1.0/me/sendMail');
        if ($ch === false) {
            return ['ok' => false, 'error' => 'curl unavailable'];
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($code >= 200 && $code < 300) {
            return ['ok' => true, 'delivery' => 'graph', 'status' => $code];
        }

        return [
            'ok' => false,
            'delivery' => 'graph',
            'status' => $code,
            'error' => $err !== '' ? $err : (is_string($raw) ? $raw : 'graph sendMail failed'),
        ];
    }

    /** @return list<array{emailAddress:array{address:string}}> */
    private function recipients(string $raw): array
    {
        $out = [];
        foreach (MailAllowlist::parseAddresses($raw) as $addr) {
            $out[] = ['emailAddress' => ['address' => $addr]];
        }

        return $out;
    }
}
