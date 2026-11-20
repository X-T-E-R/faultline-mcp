<?php

declare(strict_types=1);

namespace Faultline\Http;

/**
 * Assembles the modular HTML/CSS/JS oscilloscope files into a self-contained `text/html;profile=mcp-app`
 * document when requested over `ui://faultline/oscilloscope` inside sandboxed MCP Apps host iframes.
 */
final class UiAppBundleBuilder
{
    /**
     * @param array<string, mixed>|null $embeddedBootstrapData
     */
    public static function buildSelfContainedMcpApp(
        string $projectRoot,
        ?array $embeddedBootstrapData = null,
    ): string {
        $htmlPath = $projectRoot.'/templates/oscilloscope.html';
        $cssPath = $projectRoot.'/public/assets/oscilloscope.css';
        $jsPath = $projectRoot.'/public/assets/oscilloscope.js';

        $html = is_file($htmlPath) ? (string) file_get_contents($htmlPath) : '';
        $css = is_file($cssPath) ? (string) file_get_contents($cssPath) : '';
        $js = is_file($jsPath) ? (string) file_get_contents($jsPath) : '';

        if ('' === $html) {
            return '<!doctype html><html lang="en"><meta charset="utf-8"><title>Faultline MCP</title><body>Template missing</body></html>';
        }

        $bootstrapScript = '';
        if (null !== $embeddedBootstrapData) {
            $json = json_encode($embeddedBootstrapData, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            if (false !== $json) {
                $bootstrapScript = '<script>window.__FAULTLINE_BOOTSTRAP__ = '.$json.';</script>';
            }
        }

        $html = str_replace(
            '<link rel="stylesheet" href="/assets/oscilloscope.css">',
            '<style>'.$css.'</style>',
            $html,
        );

        return str_replace(
            '<script src="/assets/oscilloscope.js" defer></script>',
            $bootstrapScript."\n<script>".$js.'</script>',
            $html,
        );
    }
}
