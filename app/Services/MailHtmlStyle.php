<?php

namespace App\Services;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/** Limited presentation only: no URLs, functions, escapes, selectors or positioning. */
final class MailHtmlStyle implements AttributeSanitizerInterface
{
    public function getSupportedElements(): ?array
    {
        return null;
    }

    public function getSupportedAttributes(): ?array
    {
        return ['style'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        $safe = [];
        $size = '(?:0|[0-9]{1,4}(?:\.[0-9]{1,2})?(?:px|em|rem|%))';
        $color = '(?:#[a-f0-9]{3,8}|[a-z]{1,25}|rgba?\([0-9.,% ]{1,50}\))';
        foreach (array_slice(explode(';', substr($value, 0, 8000)), 0, 80) as $declaration) {
            $parts = explode(':', $declaration, 2);
            if (count($parts) !== 2) {
                continue;
            }
            [$property, $content] = array_map(fn ($part) => strtolower(trim($part)), $parts);
            $pattern = match (true) {
                in_array($property, ['color', 'background', 'background-color', 'border-color']) => $color,
                in_array($property, ['width', 'max-width', 'min-width', 'height', 'max-height', 'min-height', 'font-size', 'letter-spacing']) => '(?:'.$size.'|auto)',
                preg_match('/^(?:padding|margin)(?:-(?:top|right|bottom|left))?$/D', $property) === 1 => '(?:'.$size.'|auto)(?: +(?:'.$size.'|auto)){0,3}',
                preg_match('/^border(?:-(?:top|right|bottom|left))?$/D', $property) === 1 => '(?:0|none|'.$size.' +(solid|dashed|dotted) +'.$color.')',
                $property === 'border-radius' => $size.'(?: +'.$size.'){0,3}',
                $property === 'font-family' => '[a-z0-9 ,\'"-]{1,180}',
                $property === 'font-weight' => '(?:normal|bold|[1-9]00)',
                $property === 'font-style' => '(?:normal|italic)',
                $property === 'line-height' => '(?:normal|[0-9](?:\.[0-9]{1,2})?|'.$size.')',
                $property === 'text-align' => '(?:left|right|center|justify)',
                $property === 'text-decoration' => '(?:none|underline|line-through)',
                $property === 'display' => '(?:block|inline|inline-block|table|table-cell|table-row)',
                $property === 'vertical-align' => '(?:top|middle|bottom|baseline)',
                $property === 'border-collapse' => '(?:collapse|separate)',
                $property === 'border-spacing' => $size.'(?: +'.$size.')?',
                $property === 'word-break' => '(?:normal|break-all|break-word)',
                $property === 'overflow-wrap' => '(?:normal|break-word|anywhere)',
                default => null,
            };
            if ($pattern && preg_match('/^'.$pattern.'$/iD', $content)) {
                $safe[] = $property.':'.$content;
            }
        }

        return $safe ? implode(';', $safe) : null;
    }
}
