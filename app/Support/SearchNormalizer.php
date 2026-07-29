<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Recherche insensible aux accents, par mots (ex. "epice p" → "Épices Poisson").
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

    /** @return list<string> */
    public static function tokenize(string $term): array
    {
        $normalized = self::normalize($term);
        if ($normalized === '') {
            return [];
        }

        $normalized = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalized) ?? $normalized;
        $parts = preg_split('/\s+/u', trim($normalized), -1, PREG_SPLIT_NO_EMPTY);

        return $parts ?: [];
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
     * Chaque mot de la requête doit correspondre quelque part (nom, description, variantes, catégorie).
     */
    public static function applyProductSearch(Builder $query, string $term): Builder
    {
        $tokens = self::tokenize($term);
        if ($tokens === []) {
            return $query;
        }

        foreach ($tokens as $token) {
            $query->where(function (Builder $q) use ($token) {
                self::applyLike($q, 'name', $token);
                self::applyLike($q, 'description', $token, 'or');
                $q->orWhereHas('variants', function (Builder $variantQuery) use ($token) {
                    self::applyLike($variantQuery, 'name', $token);
                    self::applyLike($variantQuery, 'sku', $token, 'or');
                });
                $q->orWhereHas('category', function (Builder $categoryQuery) use ($token) {
                    self::applyLike($categoryQuery, 'name', $token);
                });
            });
        }

        return $query;
    }

    /**
     * Tri par pertinence : tous les mots dans le nom d'abord, puis nom > description.
     */
    public static function applyRelevanceOrder(Builder $query, string $term, string $table = 'products'): Builder
    {
        $tokens = self::tokenize($term);
        if ($tokens === []) {
            return $query;
        }

        $nameExpr = self::normalizedColumnExpression("{$table}.name");
        $descExpr = self::normalizedColumnExpression("{$table}.description");

        $nameCountParts = [];
        $nameCountBindings = [];
        $scoreParts = [];
        $scoreBindings = [];
        $allInNameParts = [];
        $allInNameBindings = [];

        foreach ($tokens as $token) {
            $like = '%' . $token . '%';
            $prefix = $token . '%';

            $nameCountParts[] = "CASE WHEN {$nameExpr} LIKE ? THEN 1 ELSE 0 END";
            $nameCountBindings[] = $like;

            $allInNameParts[] = "{$nameExpr} LIKE ?";
            $allInNameBindings[] = $like;

            $scoreParts[] = "CASE WHEN {$nameExpr} LIKE ? THEN 60 ELSE 0 END";
            $scoreBindings[] = $prefix;
            $scoreParts[] = "CASE WHEN {$nameExpr} LIKE ? THEN 40 ELSE 0 END";
            $scoreBindings[] = $like;
            $scoreParts[] = "CASE WHEN {$descExpr} LIKE ? THEN 4 ELSE 0 END";
            $scoreBindings[] = $like;
        }

        $allInNameSql = implode(' AND ', $allInNameParts);
        $scoreParts[] = "CASE WHEN ({$allInNameSql}) THEN 800 ELSE 0 END";
        $scoreBindings = array_merge($scoreBindings, $allInNameBindings);

        $nameCountExpr = '(' . implode(' + ', $nameCountParts) . ')';
        $scoreExpr = '(' . implode(' + ', $scoreParts) . ')';

        return $query
            ->orderByRaw("{$nameCountExpr} DESC", $nameCountBindings)
            ->orderByRaw("{$scoreExpr} DESC", $scoreBindings)
            ->orderBy("{$table}.name", 'asc');
    }

    /**
     * Applique la recherche par mots sur un builder (variante POS, produit simple, etc.).
     *
     * @param  callable(Builder, string, string): void  $applyToken
     */
    public static function applyTokenSearch(Builder $query, string $term, callable $applyToken): Builder
    {
        $tokens = self::tokenize($term);
        if ($tokens === []) {
            return $query;
        }

        foreach ($tokens as $token) {
            $query->where(function (Builder $tokenQuery) use ($token, $applyToken) {
                $applyToken($tokenQuery, $token, 'and');
            });
        }

        return $query;
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
