<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Plain-PHP templates in views/. A view may call $this->layout('layouts/main')
 * to be wrapped; the layout prints $content. Always print user data with e().
 */
final class View
{
    private ?string $layout = null;
    private array $layoutData = [];

    public static function render(string $template, array $data = []): string
    {
        return (new self())->renderTemplate($template, $data);
    }

    public function layout(string $template, array $data = []): void
    {
        $this->layout = $template;
        $this->layoutData = $data;
    }

    private function renderTemplate(string $template, array $data): string
    {
        $file = APP_ROOT . '/views/' . $template . '.php';
        if (!preg_match('#^[a-z0-9_/-]+$#i', $template) || !is_file($file)) {
            throw new \RuntimeException("View not found: {$template}");
        }
        $content = (function () use ($file, $data): string {
            extract($data, EXTR_SKIP);
            ob_start();
            require $file;
            return (string) ob_get_clean();
        })->call($this);

        if ($this->layout !== null) {
            $layout = $this->layout;
            $this->layout = null;
            return $this->renderTemplate($layout, [...$data, ...$this->layoutData, 'content' => $content]);
        }
        return $content;
    }
}
