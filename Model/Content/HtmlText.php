<?php

declare(strict_types=1);

namespace Byte8\Horizon\Model\Content;

use Magento\Cms\Model\Template\FilterProvider;
use Psr\Log\LoggerInterface;

/**
 * HTML → plain text for AI consumption, plus the small text helpers the
 * content providers share (search terms, excerpts, scoring).
 */
class HtmlText
{
    /** Words too common to carry meaning in a search; both English and German. */
    private const STOP_WORDS = [
        'a', 'an', 'the', 'and', 'or', 'of', 'to', 'in', 'on', 'for', 'is', 'are', 'do', 'does', 'you', 'your',
        'how', 'what', 'which', 'with', 'about', 'i', 'my', 'me', 'can', 'any', 'have', 'has', 'there',
        'der', 'die', 'das', 'und', 'oder', 'ein', 'eine', 'einen', 'einem', 'ist', 'sind', 'mit', 'für',
        'von', 'zu', 'im', 'in', 'am', 'auf', 'wie', 'was', 'ich', 'ihr', 'habt', 'gibt', 'es', 'bei',
    ];

    public function __construct(
        private readonly FilterProvider $filterProvider,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Render CMS directives ({{widget}}, {{media url}}) and strip the markup.
     */
    public function toPlainText(string $html): string
    {
        try {
            $html = $this->filterProvider->getPageFilter()->filter($html);
        } catch (\Throwable $e) {
            // Directive rendering is best-effort; strip the raw markup instead.
            $this->logger->debug('Horizon content: CMS filter failed: ' . $e->getMessage());
        }

        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $html = preg_replace('#</(p|div|li|h[1-6]|tr|section|article|blockquote)>|<br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#<li\b[^>]*>#i', '- ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{A0}", ' ', $text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\s*\n\s*/', "\n", $text) ?? $text;
        $text = preg_replace('/\n{2,}/', "\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Lower-cased search words, stop words and one-letter tokens dropped.
     *
     * @return string[]
     */
    public function terms(string $query): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(trim($query))) ?: [];
        $terms = [];
        foreach ($words as $word) {
            if (mb_strlen($word) < 2 || in_array($word, self::STOP_WORDS, true)) {
                continue;
            }
            $terms[$word] = true;
        }
        return array_keys($terms);
    }

    /**
     * Title hits weigh three times a body hit; every term counts once per field.
     *
     * @param string[] $terms
     */
    public function score(array $terms, string $title, string $body): int
    {
        $title = mb_strtolower($title);
        $body = mb_strtolower($body);
        $score = 0;
        foreach ($terms as $term) {
            if (mb_strpos($title, $term) !== false) {
                $score += 3;
            }
            if (mb_strpos($body, $term) !== false) {
                $score += 1;
            }
        }
        return $score;
    }

    /**
     * A window of plain text around the first matching term, or the opening
     * of the text when nothing matches, cut on word boundaries.
     *
     * @param string[] $terms
     */
    public function excerpt(string $text, array $terms, int $length = 300): string
    {
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        if (mb_strlen($text) <= $length) {
            return $text;
        }

        $start = 0;
        $lower = mb_strtolower($text);
        foreach ($terms as $term) {
            $pos = mb_strpos($lower, $term);
            if ($pos !== false) {
                $start = max(0, $pos - (int) ($length / 3));
                break;
            }
        }

        $slice = mb_substr($text, $start, $length);
        if ($start > 0) {
            $slice = preg_replace('/^\S*\s/', '', $slice) ?? $slice;
            $slice = '…' . $slice;
        }
        if ($start + $length < mb_strlen($text)) {
            $slice = preg_replace('/\s\S*$/', '', $slice) ?? $slice;
            $slice .= '…';
        }
        return $slice;
    }
}
