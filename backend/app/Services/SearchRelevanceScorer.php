<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * Search Relevance Scorer
 * 
 * Calculates relevance scores for search results based on multiple factors:
 * - Match type (exact, prefix, contains, fuzzy)
 * - Field weight (name > sku > description)
 * - Position in text
 * - Freshness
 * - Completeness
 */
class SearchRelevanceScorer
{
    // Base scores by match type
    const SCORE_EXACT_MATCH = 100;
    const SCORE_PREFIX_MATCH = 80;
    const SCORE_WORD_BOUNDARY = 60;
    const SCORE_CONTAINS = 40;
    const SCORE_FUZZY_MATCH = 20;

    // Field weights
    const FIELD_WEIGHTS = [
        'name' => 1.0,
        'sku' => 0.8,
        'description' => 0.5,
        'category' => 0.9,
        'fields' => 0.7,
    ];

    // Bonus multipliers
    const POSITION_BONUS = 0.5;     // +50% if match in first 20% of text
    const FRESHNESS_BONUS = 0.1;    // +10% for products created in last 30 days
    const COMPLETENESS_BONUS = 0.05; // +5% for products with images
    const CATEGORY_BONUS = 0.25;    // +25% for exact category match

    /**
     * Calculate relevance score for a product based on search query
     */
    public function calculateScore($product, string $query, array $searchContext = []): float
    {
        $query = mb_strtolower(trim($query), 'UTF-8');
        $baseScore = 0;
        $maxFieldScore = 0;

        // Calculate scores for each field
        $fieldScores = [
            'name' => $this->scoreField($product->name ?? '', $query),
            'sku' => $this->scoreField($product->sku ?? '', $query),
            'description' => $this->scoreField($product->description ?? '', $query),
        ];

        // Use the highest scoring field
        foreach ($fieldScores as $field => $score) {
            $weightedScore = $score * self::FIELD_WEIGHTS[$field];
            if ($weightedScore > $maxFieldScore) {
                $maxFieldScore = $weightedScore;
                $baseScore = $score;
            }
        }

        // Apply multipliers
        $multiplier = 1.0;

        // Position bonus (if match is early in the name)
        if (isset($product->name) && $this->isEarlyMatch($product->name, $query)) {
            $multiplier += self::POSITION_BONUS;
        }

        // Freshness bonus
        if (isset($product->created_at) && $this->isRecent($product->created_at)) {
            $multiplier += self::FRESHNESS_BONUS;
        }

        // Completeness bonus (has primary image)
        if (isset($product->primary_image) || (isset($product->images) && $product->images->isNotEmpty())) {
            $multiplier += self::COMPLETENESS_BONUS;
        }

        // Category match bonus
        if (isset($searchContext['category_id']) && isset($product->category_id)) {
            if ($product->category_id == $searchContext['category_id']) {
                $multiplier += self::CATEGORY_BONUS;
            }
        }

        $finalScore = $maxFieldScore * $multiplier;

        return round($finalScore, 2);
    }

    /**
     * Score a single field against the query
     */
    protected function scoreField(string $fieldValue, string $query): float
    {
        $fieldValue = mb_strtolower(trim($fieldValue), 'UTF-8');
        
        if (empty($fieldValue) || empty($query)) {
            return 0;
        }

        // Exact match
        if ($fieldValue === $query) {
            return self::SCORE_EXACT_MATCH;
        }

        // Prefix match
        if (str_starts_with($fieldValue, $query)) {
            return self::SCORE_PREFIX_MATCH;
        }

        // Word boundary match (query matches start of a word)
        if (preg_match('/\b' . preg_quote($query, '/') . '/i', $fieldValue)) {
            return self::SCORE_WORD_BOUNDARY;
        }

        // Contains match
        if (str_contains($fieldValue, $query)) {
            return self::SCORE_CONTAINS;
        }

        // Fuzzy match (Levenshtein distance)
        $distance = levenshtein($query, $fieldValue);
        if ($distance <= 2 && $distance > 0) {
            return self::SCORE_FUZZY_MATCH;
        }

        // Similar text percentage
        similar_text($query, $fieldValue, $percent);
        if ($percent >= 70) {
            return self::SCORE_FUZZY_MATCH * ($percent / 100);
        }

        return 0;
    }

    /**
     * Check if match occurs in the first 20% of text
     */
    protected function isEarlyMatch(string $text, string $query): bool
    {
        $text = mb_strtolower($text, 'UTF-8');
        $query = mb_strtolower($query, 'UTF-8');
        
        $position = mb_strpos($text, $query);
        if ($position === false) {
            return false;
        }

        $textLength = mb_strlen($text);
        $threshold = $textLength * 0.2;

        return $position <= $threshold;
    }

    /**
     * Check if product was created in the last 30 days
     */
    protected function isRecent($createdAt): bool
    {
        if (is_string($createdAt)) {
            $createdAt = new \DateTime($createdAt);
        }

        $thirtyDaysAgo = new \DateTime('-30 days');
        return $createdAt >= $thirtyDaysAgo;
    }

    /**
     * Score and rank a collection of products
     */
    public function scoreAndRankResults(Collection $products, string $query, array $searchContext = []): Collection
    {
        return $products->map(function ($product) use ($query, $searchContext) {
            $score = $this->calculateScore($product, $query, $searchContext);
            $product->relevance_score = $score;
            return $product;
        })->sortByDesc('relevance_score')->values();
    }

    /**
     * Calculate similarity percentage between two strings
     */
    public function calculateSimilarity(string $str1, string $str2): float
    {
        $str1 = mb_strtolower(trim($str1), 'UTF-8');
        $str2 = mb_strtolower(trim($str2), 'UTF-8');

        similar_text($str1, $str2, $percent);
        return round($percent, 2);
    }

    /**
     * Determine match type for a given query and text
     */
    public function determineMatchType(string $text, string $query): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $query = mb_strtolower(trim($query), 'UTF-8');

        if ($text === $query) {
            return 'exact';
        }

        if (str_starts_with($text, $query)) {
            return 'prefix';
        }

        if (preg_match('/\b' . preg_quote($query, '/') . '/i', $text)) {
            return 'word_boundary';
        }

        if (str_contains($text, $query)) {
            return 'contains';
        }

        $distance = levenshtein($query, $text);
        if ($distance <= 2) {
            return 'fuzzy';
        }

        similar_text($query, $text, $percent);
        if ($percent >= 70) {
            return 'fuzzy';
        }

        return 'none';
    }
}
