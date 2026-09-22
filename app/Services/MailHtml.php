<?php

namespace App\Services;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final class MailHtml
{
    public const MAX_BYTES = 524288;

    public const SANDBOX = 'allow-popups allow-popups-to-escape-sandbox';

    public const CSP = "default-src 'none'; script-src 'none'; style-src 'unsafe-inline'; img-src 'none'; connect-src 'none'; font-src 'none'; media-src 'none'; frame-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'; sandbox ".self::SANDBOX;

    public function sanitize(?string $html): ?string
    {
        if ($html === null || trim($html) === '' || strlen($html) > self::MAX_BYTES) {
            return null;
        }
        $config = (new HtmlSanitizerConfig)->withMaxInputLength(self::MAX_BYTES)
            ->allowLinkSchemes(['https', 'http', 'mailto'])->allowRelativeLinks(false)
            ->blockElement('html')->blockElement('body')
            ->withAttributeSanitizer(new MailHtmlStyle);
        foreach (['p', 'div', 'span', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'b', 'em', 'i', 'u', 's', 'small', 'br', 'hr', 'blockquote', 'pre', 'code', 'ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption', 'center'] as $tag) {
            $config = $config->allowElement($tag, ['style', 'align', 'dir', 'lang']);
        }
        foreach (['td', 'th'] as $tag) {
            $config = $config->allowElement($tag, ['style', 'align', 'dir', 'lang', 'colspan', 'rowspan']);
        }
        $config = $config->allowElement('table', ['style', 'align', 'width', 'cellpadding', 'cellspacing', 'border'])
            ->allowElement('a', ['href', 'title', 'style'])
            ->forceAttribute('a', 'target', '_blank')->forceAttribute('a', 'rel', 'noopener noreferrer nofollow');
        // Unknown elements (including head/style/script/img/form/svg/iframe) and their children are dropped.
        $previous = libxml_use_internal_errors(true);
        try {
            $safe = (new HtmlSanitizer($config))->sanitize($html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return trim(strip_tags($safe)) === '' ? null : $safe;
    }

    public function document(string $safe): string
    {
        // Only called with sanitized content. CSP is also sent as an HTTP header.
        return '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><title>Conteúdo do e-mail</title><style>html{color-scheme:light}body{margin:0;padding:16px;background:#fff;color:#202938;font:16px/1.6 Arial,sans-serif;overflow-wrap:anywhere}table{max-width:100%!important}td,th{overflow-wrap:anywhere}a{color:#175cd3}pre{white-space:pre-wrap}blockquote{margin-left:12px;padding-left:12px;border-left:3px solid #ddd}</style></head><body>'.$safe.'</body></html>';
    }
}
