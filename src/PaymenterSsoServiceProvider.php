<?php

declare(strict_types=1);

namespace PaymenterSso;

use PaymenterSso\Http\Controllers\LoginController;
use Pterodactyl\Extensions\ExtensionProvider;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Pterodactyl\Services\Extensions\ExtensionSettings;
use Pterodactyl\Services\Extensions\ExtensionSettingsDefinition;

/**
 * Pterodactyl 2.x extension that accepts signed login links from a Paymenter
 * installation and starts a panel session for the linked user.
 */
final class PaymenterSsoServiceProvider extends ExtensionProvider
{
    /**
     * @inheritdoc
     */
    public function register(): void
    {
        // The controller needs the extension's settings store; bind it
        // contextually so the controller never has to know its own id.
        $this->app->when(LoginController::class)
            ->needs(ExtensionSettings::class)
            ->give(fn () => $this->settings());

        $this->registerSettings(new ExtensionSettingsDefinition($this->settings(), [
            ExtensionSettingDefinition::make('secret', 'secret', '', ['nullable', 'string', 'max:255'])
                ->label('Shared secret')
                ->help('Must match the SSO Secret Key configured on the Paymenter server row for this panel. Links signed with a different secret are rejected.')
                ->secret(),
            ExtensionSettingDefinition::make('require_https', 'require_https', true, ['boolean'])
                ->label('Require HTTPS')
                ->help('Reject auto-login requests that did not arrive over HTTPS, so a signed link can never be intercepted on the wire. Turn off only for local development panels.')
                ->field('toggle')
                ->normalizeUsing(fn (mixed $value): bool => $value === true),
            ExtensionSettingDefinition::make('block_root_admins', 'block_root_admins', true, ['boolean'])
                ->label('Block root administrators')
                ->help('Refuse auto-login for panel accounts with root administrator rights. A billing session must never produce an administrative panel session; admins sign in with their own credentials.')
                ->field('toggle')
                ->normalizeUsing(fn (mixed $value): bool => $value === true),
            ExtensionSettingDefinition::make('block_two_factor_users', 'block_two_factor_users', true, ['boolean'])
                ->label('Block two-factor accounts')
                ->help('Refuse auto-login for accounts with two-factor authentication enabled, so SSO cannot be used as an alternative path into a protected account. When disabled, those users continue through the normal two-factor checkpoint.')
                ->field('toggle')
                ->normalizeUsing(fn (mixed $value): bool => $value === true),
        ]));
    }

    /**
     * @inheritdoc
     */
    public function boot(): void
    {
        $this->registerWebRoutes($this->extensionPath('routes', 'web.php'));
    }
}
