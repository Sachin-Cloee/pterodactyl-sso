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
