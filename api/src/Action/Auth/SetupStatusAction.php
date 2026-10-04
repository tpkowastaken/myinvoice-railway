<?php

declare(strict_types=1);

namespace MyInvoice\Action\Auth;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\FirstRunLockMiddleware;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\PasskeyService;
use MyInvoice\Service\Invoice\OverduePolicy;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Always-available endpoint. Frontend ho zavolá při startu, aby věděl, jestli
 * spustit setup wizard, a získal public Turnstile site_key.
 */
final class SetupStatusAction
{
    public function __construct(
        private readonly FirstRunLockMiddleware $lockProbe,
        private readonly Config $config,
        private readonly PasskeyService $passkeys,
        private readonly MfaPolicyService $mfaPolicy,
        private readonly OverduePolicy $overduePolicy,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        return Json::ok($response, [
            'needs_setup' => $this->lockProbe->needsSetup(),
            'version'     => '0.1.0',
            'overdue_includes_today' => $this->overduePolicy->includesToday(),
            'timezone' => (string) $this->config->get('app.timezone', 'Europe/Prague'),
            'passwordless_login_enabled' =>
                (bool) $this->config->get('auth.passwordless_login.enabled', false)
                && $this->passkeys->isAvailable()
                && $this->mfaPolicy->isMethodAllowed('passkey'),
            'captcha'     => [
                'provider'   => $this->config->get('captcha.provider', 'none'),
                'site_key'   => $this->config->get('captcha.site_key', ''),
                'script_url' => $this->config->get('captcha.script_url', ''),
            ],
        ]);
    }
}
