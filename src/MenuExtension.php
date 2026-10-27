<?php

declare(strict_types=1);

namespace Telegram\Plugin;

use Engelsystem\Helpers\Authenticator;
use Engelsystem\Http\UrlGenerator;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Adds the plugin's admin pages to the "Admin" dropdown
 *
 * The core admin menu is a fixed list, so this replaces the navbar's menu() function with one
 * that extends the stock menu. Registered after the core Twig extensions, so it takes precedence.
 */
class MenuExtension extends AbstractExtension
{
    /** @var array<string, array{string, string}> translation key => [path, permission] */
    protected const ADMIN_PAGES = [
        'telegram.dashboard.title' => ['/admin/telegram/dashboard', 'admin_user'],
        'telegram.admin.title'     => ['/admin/telegram', 'admin_user'],
    ];

    public function __construct(
        protected Authenticator $auth,
        protected UrlGenerator $url
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('menu', [$this, 'menu'], ['is_safe' => ['html']]),
        ];
    }

    public function menu(): array
    {
        $menu = make_navigation();

        foreach (self::ADMIN_PAGES as $name => [$path, $permission]) {
            if (!$this->auth->can($permission)) {
                continue;
            }

            $menu['Admin'][0][$name] = [$this->url->to($path), $permission];
            $menu['Admin'][1] = null;
        }

        return $menu;
    }
}
