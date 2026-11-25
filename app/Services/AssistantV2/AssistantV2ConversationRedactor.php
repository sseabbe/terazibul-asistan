<?php

declare(strict_types=1);

namespace App\Services\AssistantV2;

/**
 * Analytics metninden kişisel veriyi düşürür. Ham konuşma saklanmaz.
 */
final class AssistantV2ConversationRedactor
{
    public static function text(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace(
            '/([?&](?:token|key|api_key|apikey|access_token|refresh_token|secret|password|auth|sig|session)=)[^&\s#]+/iu',
            '$1[TOKEN]',
            $value
        ) ?? $value;

        $value = preg_replace(
            '/(?<![\w.+-])[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}(?![\w.-])/iu',
            '[EMAIL]',
            $value
        ) ?? $value;

        $value = preg_replace(
            '/(?<!\d)(?:\+90|0090|0)[\s.\-]?5\d{2}[\s.\-]?\d{3}[\s.\-]?\d{2}[\s.\-]?\d{2}(?!\d)/u',
            '[PHONE]',
            $value
        ) ?? $value;

        $value = preg_replace(
            '/(?<!\d)0\d{3}[\s.\-]?\d{3}[\s.\-]?\d{2}[\s.\-]?\d{2}(?!\d)/u',
            '[PHONE]',
            $value
        ) ?? $value;

        $value = preg_replace(
            '/(?<!\d)[1-9]\d{10}(?!\d)/u',
            '[ID]',
            $value
        ) ?? $value;

        $value = preg_replace(
            '/((?:sipari[sş]|m[uü]steri)\s*(?:no|numaras[ıi]|numarasi|kodu)?\s*[:#\-]?\s*)([A-Za-z0-9][A-Za-z0-9\-]{2,})/iu',
            '$1[ORDER]',
            $value
        ) ?? $value;

        $value = preg_replace(
            '/\b[\p{L}0-9][\p{L}0-9 .\'-]{0,40}\s+(?:mahallesi|mah\.|sokağı|sokaği|sokak|sok\.|caddesi|cadde|cad\.|bulvarı|bulvari|bulvar|blv\.?)\b[^,;\n]{0,60}/iu',
            '[ADDRESS]',
            $value
        ) ?? $value;

        $value = preg_replace(
            '/\b(?:adres(?:im|imiz)?)\s*[:\-]\s*\S[^.\n]{4,120}/iu',
            '[ADDRESS]',
            $value
        ) ?? $value;

        if (mb_strlen($value) > 1500) {
            $value = mb_substr($value, 0, 1500);
        }

        return $value;
    }
}
