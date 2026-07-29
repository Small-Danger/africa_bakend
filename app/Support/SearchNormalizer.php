<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Recherche insensible aux accents (ex. "ep" → "épice").
 */
class SearchNormalizer
{
    private const ACCENT_FROM = 'àáâãäåèéêëìíîïòóôõöùúûüýÿçñÀÁÂÃÄÅÈÉÊËÌÍÎÏÒÓÔÕÖÙÚÛÜÝŸÇÑ';

    private const ACCENT_TO = 'aaaaaaeeeeiiiioooooouuuuyycnAAAAAAEEEEIIIIOOOOOOUUUUYYCN';

    public static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        if ($text === '') {
            return '';
        }

        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_D);
            if ($decomposed !== false) {
                $stripped = preg_replace('/\p{M}/u', '', $decomposed);
                if (is_string($stripped) && $stripped !== '') {
                    $text = $stripped;
                }
            }
        }

        return strtr($text, self::accentMap());
    }

    public static function normalizedColumnExpression(string $column): string
    {
        if (self::isPgsql()) {
            return "lower(translate({$column}, '" . self::ACCENT_FROM . "', '" . self::ACCENT_TO . "'))";
        }

        return "lower({$column})";
    }

    public static function applyLike(Builder $query, string $column, string $term, string $boolean = 'and'): Builder
    {
        $normalized = self::normalize($term);
        if ($normalized === '') {
            return $query;
        }

        $expression = self::normalizedColumnExpression($column);
        $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';

        return $query->{$method}("{$expression} LIKE ?", ['%' . $normalized . '%']);
    }

    /**
     * Recherche produit : nom, description, variantes (nom, sku), catégorie.
     */
    public static function applyProductSearch(Builder $query, string $term): Builder
    {
        $normalized = self::normalize($term);
        if ($normalized === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            self::applyLike($q, 'name', $term);
            self::applyLike($q, 'description', $term, 'or');
            $q->orWhereHas('variants', function (Builder $variantQuery) use ($term) {
                self::applyLike($variantQuery, 'name', $term);
                self::applyLike($variantQuery, 'sku', $term, 'or');
            });
            $q->orWhereHas('category', function (Builder $categoryQuery) use ($term) {
                self::applyLike($categoryQuery, 'name', $term);
            });
        });
    }

    private static function isPgsql(): bool
    {
        $connection = config('database.default');
        return config("database.connections.{$connection}.driver") === 'pgsql';
    }

    /** @return array<string, string> */
    private static function accentMap(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }

        $from = preg_split('//u', self::ACCENT_FROM, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $to = preg_split('//u', self::ACCENT_TO, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $map = [];
        foreach ($from as $index => $char) {
            if (isset($to[$index])) {
                $map[$char] = $to[$index];
            }
        }

        return $map;
    }
}
