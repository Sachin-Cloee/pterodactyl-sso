<?php

declare(strict_types=1);

namespace PaymenterSso\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Contracts\Users\CompletesLogins;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Extensions\ExtensionSettings;

/**
 * Verifies a signed login link from Paymenter and completes the panel login.
 *
 * Protocol (built by Paymenter's PanelLoginLink):
 *
 *   payload   = "paymenter-sso|{user}|{expires}|{nonce}|{server}"
 *   signature = hex(hmac_sha256(payload, shared secret))
 *   URL       = /extensions/paymenter-sso/login?user=…&expires=…&nonce=…&server=…&signature=…
 *
 * Security properties:
 * - the signature covers every request parameter, so nothing can be altered
 * - links expire quickly and are bounded in the future (clock-skew tolerant)
 * - each nonce is accepted exactly once (atomic cache add), so a captured URL
 *   cannot be replayed
 * - the panel user is loaded server-side; no caller-supplied identity is trusted
 * - completion goes through the panel's own login service, so two-factor
 *   accounts receive the normal checkpoint instead of a bypass
 */
final class LoginController
{
    public const PAYLOAD_PREFIX = 'paymenter-sso';

    private const SIGNATURE_ALGORITHM = 'sha256';

    /** Links may not be more than this many seconds in the future. */
    private const MAX_LINK_TTL_SECONDS = 300;

    /** Tolerated clock difference between the billing host and the panel. */
    private const CLOCK_SKEW_SECONDS = 30;

    /** Nonces are kept longer than any link can live, so replay is impossible. */
    private const NONCE_TTL_SECONDS = 600;

    private const NONCE_PATTERN = '/^[a-f0-9]{32}$/';

    private const SIGNATURE_PATTERN = '/^[a-f0-9]{64}$/';

    private const SERVER_IDENTIFIER_PATTERN = '/^[a-z0-9]{1,64}$/i';

    public function __construct(private readonly ExtensionSettings $settings) {}

    public function __invoke(Request $request, CompletesLogins $logins): RedirectResponse
    {
        $verification = $this->verify($request);

        if ($verification['error'] !== null) {
            return $this->failed($request, $verification['error']);
        }

        /** @var User $user */
        $user = $verification['user'];
        $server = $verification['server'];

        $result = $logins->complete($user);

        if (!$result->complete) {
            // Two-factor accounts continue through the normal checkpoint.
            return redirect()->to('/auth/login?sso=checkpoint');
        }

        return redirect()->to($server !== null ? '/server/' . $server : $result->intended);
    }

    /**
     * @return array{user: ?User, server: ?string, error: ?string}
     */
    private function verify(Request $request): array
    {
        $secret = trim((string) ($this->settings->get('secret') ?? ''));

        if ($secret === '') {
            return $this->failure('unavailable');
        }

        $userId = $request->query('user');
        $expires = $request->query('expires');
        $nonce = $request->query('nonce');
        $server = $request->query('server');
        $signature = $request->query('signature');

        if (!is_string($userId) || !ctype_digit($userId)) {
            return $this->failure('invalid');
        }

        if (!is_string($expires) || !ctype_digit($expires)) {
            return $this->failure('invalid');
        }

        if (!is_string($nonce) || !preg_match(self::NONCE_PATTERN, $nonce)) {
            return $this->failure('invalid');
        }

        if (!is_string($signature) || !preg_match(self::SIGNATURE_PATTERN, $signature)) {
            return $this->failure('invalid');
        }

        if ($server !== null && $server !== '' && (!is_string($server) || !preg_match(self::SERVER_IDENTIFIER_PATTERN, $server))) {
            return $this->failure('invalid');
        }

        $server = (is_string($server) && $server !== '') ? $server : null;

        $now = time();
        $expiresAt = (int) $expires;

        if ($expiresAt < $now - self::CLOCK_SKEW_SECONDS || $expiresAt > $now + self::MAX_LINK_TTL_SECONDS) {
            return $this->failure('expired');
        }

        $expected = hash_hmac(
            self::SIGNATURE_ALGORITHM,
            implode('|', [self::PAYLOAD_PREFIX, $userId, (string) $expiresAt, $nonce, $server ?? '']),
            $secret
        );

        if (!hash_equals($expected, strtolower($signature))) {
            return $this->failure('invalid');
        }

        // Atomic single-use check: add() only succeeds if the nonce is new.
        if (!Cache::add('paymenter-sso:login:' . $nonce, true, self::NONCE_TTL_SECONDS)) {
            return $this->failure('used');
        }

        $user = User::query()->find((int) $userId);

        if (!$user instanceof User) {
            return $this->failure('unknown-user');
        }

        return ['user' => $user, 'server' => $server, 'error' => null];
    }

    /**
     * @return array{user: null, server: null, error: string}
     */
    private function failure(string $reason): array
    {
        return ['user' => null, 'server' => null, 'error' => $reason];
    }

    private function failed(Request $request, string $reason): RedirectResponse
    {
        Log::warning('paymenter-sso: rejected login link', [
            'reason' => $reason,
            'ip' => $request->ip(),
        ]);

        return redirect()->to('/auth/login?' . http_build_query(['sso_error' => $reason]));
    }
}
