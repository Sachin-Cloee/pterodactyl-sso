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
 * - configurable policy gates (all default on): HTTPS-only requests, no root
 *   administrators, and no two-factor accounts — so a billing session can
 *   never produce an administrative panel session or act as an alternative
 *   path into a protected account
 * - when the two-factor block is disabled, completion still goes through the
 *   panel's own login service, so those accounts receive the normal checkpoint
 *   instead of a bypass
 * - successful logins are audited by core (DirectLogin => auth:success);
 *   rejected links are logged here with the reason and IP
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

    private const ERROR_INSECURE = 'insecure';

    private const ERROR_ADMIN_BLOCKED = 'admin-blocked';

    private const ERROR_TWO_FACTOR_BLOCKED = 'two-factor-blocked';

    public function __construct(private readonly ExtensionSettings $settings) {}

    public function __invoke(Request $request, CompletesLogins $logins): RedirectResponse
    {
        if ($this->setting('require_https', true) && !$request->isSecure()) {
            return $this->failed($request, self::ERROR_INSECURE);
        }

        $verification = $this->verify($request);

        if ($verification['error'] !== null) {
            return $this->failed($request, $verification['error'], $verification['attempted_user_id']);
        }

        /** @var User $user */
        $user = $verification['user'];
        $server = $verification['server'];

        // The signed link is already consumed at this point: a policy outcome
        // is deterministic for the account, so there is nothing to replay.
        $blockedReason = $this->blockedReason($user);

        if ($blockedReason !== null) {
            return $this->failed($request, $blockedReason, $user->id);
        }

        $result = $logins->complete($user);

        if (!$result->complete) {
            // Only reachable when the two-factor block is disabled; those
            // accounts continue through the normal checkpoint.
            return redirect()->to('/auth/login?sso=checkpoint');
        }

        return redirect()->to($server !== null ? '/server/' . $server : $result->intended);
    }

    /**
     * Policy gates applied to an otherwise valid link.
     */
    private function blockedReason(User $user): ?string
    {
        if ($this->setting('block_root_admins', true) && (bool) $user->root_admin) {
            return self::ERROR_ADMIN_BLOCKED;
        }

        if ($this->setting('block_two_factor_users', true) && (bool) $user->use_totp) {
            return self::ERROR_TWO_FACTOR_BLOCKED;
        }

        return null;
    }

    /**
     * @return array{user: ?User, server: ?string, error: ?string, attempted_user_id: ?int}
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
            return $this->failure('unknown-user', (int) $userId);
        }

        return ['user' => $user, 'server' => $server, 'error' => null, 'attempted_user_id' => (int) $userId];
    }

    /**
     * @return array{user: null, server: null, error: string, attempted_user_id: ?int}
     */
    private function failure(string $reason, ?int $attemptedUserId = null): array
    {
        return ['user' => null, 'server' => null, 'error' => $reason, 'attempted_user_id' => $attemptedUserId];
    }

    private function failed(Request $request, string $reason, ?int $userId = null): RedirectResponse
    {
        Log::warning('paymenter-sso: rejected login link', [
            'reason' => $reason,
            'user_id' => $userId,
            'ip' => $request->ip(),
        ]);

        return redirect()->to('/auth/login?' . http_build_query(['sso_error' => $reason]));
    }

    private function setting(string $key, bool $default): bool
    {
        $value = $this->settings->get($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
