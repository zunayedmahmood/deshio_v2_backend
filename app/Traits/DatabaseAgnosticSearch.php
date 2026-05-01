<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Database Agnostic Search Trait
 * 
 * Provides search methods that work across MySQL, PostgreSQL, SQLite, etc.
 * MySQL: LIKE is case-insensitive by default
 * PostgreSQL: LIKE is case-sensitive, ILIKE is case-insensitive
 * SQLite: LIKE is case-insensitive by default
 */
trait DatabaseAgnosticSearch
{
    /**
     * Get the appropriate LIKE operator for the current database driver
     * 
     * @param bool $caseSensitive
     * @return string
     */
    protected function getLikeOperator(bool $caseSensitive = false): string
    {
        $driver = DB::connection()->getDriverName();
        
        if ($driver === 'pgsql') {
            return $caseSensitive ? 'LIKE' : 'ILIKE';
        }
        
        // MySQL, SQLite, SQL Server use LIKE (case-insensitive by default)
        return 'LIKE';
    }
    
    /**
     * Add a case-insensitive LIKE condition to the query
     * 
     * @param Builder $query
     * @param string $column
     * @param string $value
     * @param string $position 'both', 'start', 'end'
     * @return Builder
     */
    protected function whereLike(Builder $query, string $column, string $value, string $position = 'both'): Builder
    {
        $operator = $this->getLikeOperator(false);
        $pattern = $this->buildLikePattern($value, $position);
        
        return $query->where($column, $operator, $pattern);
    }
    
    /**
     * Add a case-insensitive OR LIKE condition to the query
     * 
     * @param Builder $query
     * @param string $column
     * @param string $value
     * @param string $position 'both', 'start', 'end'
     * @return Builder
     */
    protected function orWhereLike(Builder $query, string $column, string $value, string $position = 'both'): Builder
    {
        $operator = $this->getLikeOperator(false);
        $pattern = $this->buildLikePattern($value, $position);
        
        return $query->orWhere($column, $operator, $pattern);
    }
    
    /**
     * Search multiple columns with case-insensitive LIKE
     * 
     * @param Builder $query
     * @param array $columns
     * @param string $value
     * @param string $position 'both', 'start', 'end'
     * @return Builder
     */
    protected function whereAnyLike(Builder $query, array $columns, string $value, string $position = 'both'): Builder
    {
        $operator = $this->getLikeOperator(false);
        $pattern = $this->buildLikePattern($value, $position);
        
        return $query->where(function ($q) use ($columns, $operator, $pattern) {
            foreach ($columns as $index => $column) {
                if ($index === 0) {
                    $q->where($column, $operator, $pattern);
                } else {
                    $q->orWhere($column, $operator, $pattern);
                }
            }
        });
    }
    
    /**
     * Build LIKE pattern with wildcards
     * 
     * @param string $value
     * @param string $position 'both', 'start', 'end'
     * @return string
     */
    protected function buildLikePattern(string $value, string $position = 'both'): string
    {
        // Escape special LIKE characters
        $value = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
        
        switch ($position) {
            case 'start':
                return "{$value}%";
            case 'end':
                return "%{$value}";
            case 'both':
            default:
                return "%{$value}%";
        }
    }
    
    /**
     * Get database-agnostic case-insensitive search with relevance ordering
     * 
     * @param Builder $query
     * @param array $columns Columns to search
     * @param string $searchTerm Search term
     * @param string $primaryColumn Primary column for relevance ordering
     * @return Builder
     */
    protected function searchWithRelevance(Builder $query, array $columns, string $searchTerm, string $primaryColumn = null): Builder
    {
        $operator = $this->getLikeOperator(false);
        
        // Add search conditions
        $query->where(function ($q) use ($columns, $operator, $searchTerm) {
            foreach ($columns as $index => $column) {
                $pattern = "%{$searchTerm}%";
                if ($index === 0) {
                    $q->where($column, $operator, $pattern);
                } else {
                    $q->orWhere($column, $operator, $pattern);
                }
            }
        });
        
        // Add relevance ordering if primary column specified
        if ($primaryColumn) {
            $driver = DB::connection()->getDriverName();
            
            if ($driver === 'pgsql') {
                // PostgreSQL version with ILIKE
                $query->orderByRaw("CASE 
                    WHEN {$primaryColumn} ILIKE ? THEN 1
                    WHEN {$primaryColumn} ILIKE ? THEN 2
                    ELSE 3
                END", ["{$searchTerm}%", "%{$searchTerm}%"]);
            } else {
                // MySQL version with LIKE
                $query->orderByRaw("CASE 
                    WHEN {$primaryColumn} LIKE ? THEN 1
                    WHEN {$primaryColumn} LIKE ? THEN 2
                    ELSE 3
                END", ["{$searchTerm}%", "%{$searchTerm}%"]);
            }
        }
        
        return $query;
    }
    
    /**
     * Get database-agnostic date formatting for grouping
     * Returns SQL expression for formatting date column
     * 
     * @param string $column The date column name
     * @param string $format Format: 'year', 'month', 'day', 'week'
     * @return string Raw SQL expression
     */
    protected function getDateFormatSql(string $column, string $format = 'month'): string
    {
        $driver = DB::connection()->getDriverName();
        
        if ($driver === 'pgsql') {
            // PostgreSQL date formatting
            return match($format) {
                'year' => "TO_CHAR({$column}, 'YYYY')",
                'month' => "TO_CHAR({$column}, 'YYYY-MM')",
                'day' => "TO_CHAR({$column}, 'YYYY-MM-DD')",
                'week' => "TO_CHAR({$column}, 'IYYY-IW')",
                default => "TO_CHAR({$column}, 'YYYY-MM')",
            };
        } elseif ($driver === 'sqlite') {
            // SQLite date formatting
            return match($format) {
                'year' => "strftime('%Y', {$column})",
                'month' => "strftime('%Y-%m', {$column})",
                'day' => "strftime('%Y-%m-%d', {$column})",
                'week' => "strftime('%Y-%W', {$column})",
                default => "strftime('%Y-%m', {$column})",
            };
        } else {
            // MySQL date formatting
            $mysqlFormat = match($format) {
                'year' => '%Y',
                'month' => '%Y-%m',
                'day' => '%Y-%m-%d',
                'week' => '%Y-%u',
                default => '%Y-%m',
            };
            return "DATE_FORMAT({$column}, '{$mysqlFormat}')";
        }
    }
    
    /**
     * Get database-agnostic string aggregation function
     * Concatenates multiple rows into a single string
     * 
     * @param string $column The column to concatenate
     * @param string $separator The separator character
     * @return string Raw SQL expression
     */
    protected function getStringAggregateSql(string $column, string $separator = ','): string
    {
        $driver = DB::connection()->getDriverName();
        
        if ($driver === 'pgsql') {
            return "STRING_AGG({$column}::text, '{$separator}')";
        } elseif ($driver === 'sqlite') {
            return "GROUP_CONCAT({$column}, '{$separator}')";
        } else {
            // MySQL
            return "GROUP_CONCAT({$column} SEPARATOR '{$separator}')";
        }
    }
    
    /**
     * Get database-agnostic date difference in days
     * Returns SQL expression for calculating days between dates
     * 
     * @param string $startDate The start date (column or expression)
     * @param string $endDate The end date (column or expression), default 'CURRENT_DATE'
     * @return string Raw SQL expression
     */
    protected function getDateDiffDaysSql(string $startDate, string $endDate = null): string
    {
        $driver = DB::connection()->getDriverName();
        $endDate = $endDate ?? $this->getCurrentDateSql();
        
        if ($driver === 'pgsql') {
            return "EXTRACT(DAY FROM {$endDate}::date - {$startDate}::date)";
        } elseif ($driver === 'sqlite') {
            return "CAST((julianday({$endDate}) - julianday({$startDate})) AS INTEGER)";
        } else {
            // MySQL
            return "DATEDIFF({$endDate}, {$startDate})";
        }
    }
    
    /**
     * Get database-agnostic current date SQL
     * Returns SQL expression for current date
     * 
     * @return string Raw SQL expression
     */
    protected function getCurrentDateSql(): string
    {
        $driver = DB::connection()->getDriverName();
        
        if ($driver === 'pgsql') {
            return 'CURRENT_DATE';
        } elseif ($driver === 'sqlite') {
            return "date('now')";
        } else {
            // MySQL
            return 'CURDATE()';
        }
    }
    
    /**
     * Get database-agnostic DATE() cast for extracting date from datetime
     * Returns SQL expression for converting datetime to date
     * 
     * @param string $column The datetime column name
     * @return string Raw SQL expression
     */
    protected function getDateCastSql(string $column): string
    {
        $driver = DB::connection()->getDriverName();
        
        if ($driver === 'pgsql') {
            return "{$column}::date";
        } elseif ($driver === 'sqlite') {
            return "date({$column})";
        } else {
            // MySQL
            return "DATE({$column})";
        }
    }
}
