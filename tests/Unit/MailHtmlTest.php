<?php

namespace Tests\Unit;

use App\Services\MailHtml;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MailHtmlTest extends TestCase
{
    public function test_email_layout_and_button_survive_without_active_content(): void
    {
        $input = '<!doctype html><html><head><style>body{background:url(https://tracker.test)}</style></head><body><table width="100%" style="background-color:#f4f5f7"><tr><td><h1>Bem-vindo!</h1><p>Crie sua senha.</p><a href="https://example.test/accept?token=SYNTHETIC&amp;email=person%40example.test" class="button" style="background-color:#3869d4;color:#fff;padding:12px 24px;border-radius:4px;display:inline-block;text-decoration:none">Definir senha</a><img src="https://tracker.test/pixel"><script>attack()</script></td></tr></table></body></html>';
        $safe = (new MailHtml)->sanitize($input);
        $this->assertStringContainsString('<h1>Bem-vindo!</h1>', $safe);
        $this->assertStringContainsString('<table', $safe);
        $this->assertStringContainsString('background-color:#3869d4', $safe);
        $this->assertStringContainsString('padding:12px 24px', $safe);
        $this->assertStringContainsString('Definir senha</a>', $safe);
        $this->assertStringContainsString('token=SYNTHETIC&email=person%40example.test', html_entity_decode($safe, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $this->assertStringContainsString('target="_blank"', $safe);
        $this->assertStringContainsString('rel="noopener noreferrer nofollow"', $safe);
        $this->assertStringNotContainsString('tracker.test', $safe);
        $this->assertStringNotContainsString('attack', $safe);
        $this->assertSame($safe, (new MailHtml)->sanitize($safe));
    }

    #[DataProvider('unsafeMarkup')]
    public function test_unsafe_markup_is_dropped(string $input, string $forbidden): void
    {
        $safe = (new MailHtml)->sanitize('<p>Mensagem segura</p>'.$input);
        $this->assertStringContainsString('Mensagem segura', $safe);
        $this->assertStringNotContainsString($forbidden, $safe);
    }

    public static function unsafeMarkup(): array
    {
        return [
            ['<script src="https://attack.test"></script>', 'attack.test'],
            ['<a href="javascript:alert(1)" onclick="attack()">Link</a>', 'javascript'],
            ['<a href="java&#x0a;script:alert(1)">Link</a>', 'alert'],
            ['<a href="data:text/html,attack">Link</a>', 'data:'],
            ['<a href="/logout" ping="https://attack.test">Link</a>', '/logout'],
            ['<iframe srcdoc="<script>attack()</script>"></iframe>', 'iframe'],
            ['<svg><a xlink:href="javascript:attack()">X</a></svg>', 'svg'],
            ['<math><mtext><table><mglyph><style><!--</style><img title="--><img src=1 onerror=attack()>">', 'onerror'],
            ['<form action="https://attack.test"><input name="password" autofocus></form>', 'form'],
            ['<meta http-equiv="refresh" content="0;url=https://attack.test"><base href="https://attack.test">', 'attack.test'],
            ['<video src="https://attack.test"><source src="https://attack.test"></video>', 'attack.test'],
            ['<a href="https://example.test" id="main" name="location" ping="https://attack.test" download="x" onclick="attack()">Link</a>', 'attack'],
        ];
    }

    public function test_css_cannot_load_urls_or_escape_the_document(): void
    {
        $safe = (new MailHtml)->sanitize('<p style="background:url(https://attack.test);background-image:url(https://attack.test);position:fixed;inset:0;z-index:9999;behavior:url(a);font-family:var(--x);color:expression(attack());padding:12px;color:#123456">Hello</p><div style="background:u\\72l(https://attack.test);color:/**/red">World</div>');
        foreach (['url', 'attack', 'position', 'inset', 'z-index', 'behavior', 'var(', 'expression', '\\72', '/**/'] as $bad) {
            $this->assertStringNotContainsString($bad, $safe);
        }
        $this->assertStringContainsString('padding:12px;color:#123456', $safe);
    }

    public function test_missing_empty_or_oversized_html_uses_text_fallback(): void
    {
        foreach ([null, '', '<img src="https://example.test/x">', str_repeat('x', MailHtml::MAX_BYTES + 1)] as $html) {
            $this->assertNull((new MailHtml)->sanitize($html));
        }
    }
}
