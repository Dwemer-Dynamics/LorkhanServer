<?php

declare(strict_types=1);

namespace LORKHANserver\Http;

final class ManagementView
{
    private const MENU = [
        'home' => ['label' => 'Home', 'slug' => 'quickstart'],
        'roleplay' => ['label' => 'Roleplay', 'slug' => 'roleplay'],
        'configuration' => ['label' => 'Configuration', 'slug' => 'configuration'],
        'control' => ['label' => 'Control Panel', 'slug' => 'control-panel'],
    ];

    private const SECTIONS = [
        'quickstart' => 'home',
        'roleplay' => 'roleplay',
        'characters' => 'roleplay',
        'profiles' => 'roleplay',
        'memory' => 'roleplay',
        'relationships' => 'roleplay',
        'world' => 'roleplay',
        'knowledge' => 'roleplay',
        'narrative-autonomy' => 'roleplay',
        'configuration' => 'configuration',
        'providers' => 'configuration',
        'ai-voice' => 'configuration',
        'prompts-actions' => 'configuration',
        'control-panel' => 'control',
        'traces' => 'control',
        'playthroughs' => 'control',
        'jobs' => 'control',
        'backup-health' => 'control',
        'diagnostics' => 'control',
    ];

    public function __construct(private readonly string $basePath) {}

    /** Render an LORKHAN page in the shared Herika, Stobe, and Dialectic server frame. */
    public function page(string $title, string $slug, string $body): string
    {
        return $this->document(
            $title . ' · LORKHAN',
            '<body><a class="skip" href="#main">Skip to content</a>'
            . $this->navbar($slug)
            . '<main id="main" class="dwemer-page">'
            . '<div class="dashboard-meta"><span>Server: LORKHAN</span><span>Client: OpenMW 0.51</span></div>'
            . '<h1 class="dashboard-title">' . $this->e($title) . '</h1>'
            . $body
            . '</main></body>'
        );
    }

    /** Render request failures in the same server frame instead of a separate error layout. */
    public function error(string $error): string
    {
        return $this->document(
            'Management error · LORKHAN',
            '<body><a class="skip" href="#main">Skip to content</a>'
            . $this->navbar('control-panel')
            . '<main id="main" class="dwemer-page narrow-page">'
            . '<div class="dashboard-meta"><span>Server: LORKHAN</span><span>Client: OpenMW 0.51</span></div>'
            . '<h1 class="dashboard-title">Request could not be saved</h1>'
            . '<section class="widget"><div class="widget-content"><div role="alert"><p>' . $this->e($error) . '</p></div>'
            . '<a class="button-link" href="' . $this->e($this->basePath) . '/quickstart">Return to management</a>'
            . '</div></section></main></body>'
        );
    }

    private function document(string $title, string $body): string
    {
        $root = preg_replace('#/manage$#', '', $this->basePath) ?: '/LORKHANserver';
        return '<!doctype html><html lang="en" data-bs-theme="dark"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#111111">'
            . '<title>' . $this->e($title) . '</title>'
            . '<link rel="icon" type="image/x-icon" href="' . $this->e($root) . '/ui/images/favicon.ico">'
            . '<link rel="stylesheet" href="' . $this->e($root) . '/ui/lib/ui/bootstrap/bootstrap.min.css">'
            . '<link rel="stylesheet" href="' . $this->e($root) . '/ui/css/style_new.css">'
            . '<link rel="stylesheet" href="' . $this->e($root) . '/ui/css/lorkhan-theme.css">'
            . '<link rel="stylesheet" href="' . $this->e($root) . '/ui/css/navbar.css">'
            . '<link rel="stylesheet" href="' . $this->e($root) . '/ui/css/main.css">'
            . '<link rel="stylesheet" href="' . $this->e($root) . '/ui/css/lorkhan-pages.css">'
            . '<script src="' . $this->e($root) . '/ui/lib/ui/bootstrap/bootstrap.bundle.min.js" defer></script>'
            . '</head>' . $body . '</html>';
    }

    private function navbar(string $slug): string
    {
        $activeSection = self::SECTIONS[$slug] ?? '';
        $root = preg_replace('#/manage$#', '', $this->basePath) ?: '/LORKHANserver';
        $items = '';
        foreach (self::MENU as $section => $item) {
            $active = $section === $activeSection;
            $href = match ($section) {
                'home' => $root . '/ui/home.php',
                'roleplay' => $root . '/ui/events-memories.php',
                'configuration' => $root . '/ui/core/config_hub.php',
                'control' => $root . '/ui/control_panel.php',
            };
            $items .= '<li><a class="dropdown-item' . ($active ? ' active' : '') . '" href="'
                . $this->e($href) . '"'
                . ($active ? ' aria-current="page"' : '') . '>' . $this->e($item['label']) . '</a></li>';
        }
        $items .= '<li><a class="dropdown-item" href="/Dwemer-Dashboard/index.php">DwemerDistro Home</a></li>';

        return '<div class="lorkhan-navbar-wrapper"><nav class="navbar navbar-expand-lg lorkhan-navbar" aria-label="Product">'
            . '<div class="container-fluid mx-1"><div class="navbar-content-wrapper"><div class="navbar-center dropdown">'
            . '<button class="navbar-brand Title btn btn-link p-0 dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-auto-close="true" data-bs-display="static" aria-expanded="false" title="Open menu">'
            . '<img src="' . $this->e($root) . '/ui/images/DwemerDynamics.png" alt="Dwemer Dynamics">'
            . '<img class="brand-mark-img" src="' . $this->e($root) . '/ui/images/lorkhan-logo.png" width="512" height="512" alt="LORKHAN Server">'
            . '<img class="brand-wordmark-img" src="' . $this->e($root) . '/ui/images/serverlogo.png" width="103" height="42" alt="" aria-hidden="true"></button>'
            . '<ul class="dropdown-menu brand-menu">' . $items . '</ul></div></div></div></nav></div>';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
