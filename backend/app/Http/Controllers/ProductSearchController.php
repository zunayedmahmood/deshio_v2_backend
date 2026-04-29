<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductField;
use App\Models\Category;
use App\Traits\DatabaseAgnosticSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductSearchController extends Controller
{
    use DatabaseAgnosticSearch;
    // Bangla to English phonetic mapping
    private $banglaToRomanMap = [
        'আ' => 'a', 'অ' => 'o', 'ই' => 'i', 'ঈ' => 'ee', 'উ' => 'u', 'ঊ' => 'oo',
        'এ' => 'e', 'ঐ' => 'oi', 'ও' => 'o', 'ঔ' => 'ou',
        'ক' => 'k', 'খ' => 'kh', 'গ' => 'g', 'ঘ' => 'gh', 'ঙ' => 'ng',
        'চ' => 'ch', 'ছ' => 'chh', 'জ' => 'j', 'ঝ' => 'jh', 'ঞ' => 'ng',
        'ট' => 't', 'ঠ' => 'th', 'ড' => 'd', 'ঢ' => 'dh', 'ণ' => 'n',
        'ত' => 't', 'থ' => 'th', 'দ' => 'd', 'ধ' => 'dh', 'ন' => 'n',
        'প' => 'p', 'ফ' => 'f', 'ব' => 'b', 'ভ' => 'bh', 'ম' => 'm',
        'য' => 'y', 'র' => 'r', 'ল' => 'l', 'শ' => 'sh', 'ষ' => 'sh',
        'স' => 's', 'হ' => 'h', 'ড়' => 'r', 'ঢ়' => 'rh', 'য়' => 'y',
        'ৎ' => 't', 'ং' => 'ng', 'ঃ' => 'h', 'ঁ' => 'n',
        'া' => 'a', 'ি' => 'i', 'ী' => 'ee', 'ু' => 'u', 'ূ' => 'oo',
        'ৃ' => 'ri', 'ে' => 'e', 'ৈ' => 'oi', 'ো' => 'o', 'ৌ' => 'ou',
        '্' => '', 'ৗ' => 'ou', 'ঽ' => '',
        '০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4',
        '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9',
    ];

    // Common Romanized to Bangla reverse mapping
    private $romanToBanglaCommon = [
        // Textile & Fashion Terms (Client Specific)
        'saree' => 'শাড়ি', 'sari' => 'শাড়ি', 'sharee' => 'শাড়ি',
        'jamdani' => 'জামদানি', 'jomdani' => 'জামদানি',
        'katan' => 'কাতান', 'kataan' => 'কাতান',
        'batik' => 'বাটিক', 'batick' => 'বাটিক',
        'jama' => 'জামা', 'jamma' => 'জামা',
        'orna' => 'ওড়না', 'urna' => 'ওড়না', 'odna' => 'ওড়না',
        'salwar' => 'সালোয়ার', 'saloar' => 'সালোয়ার', 'saluar' => 'সালোয়ার',
        'kurti' => 'কুর্তি', 'kurty' => 'কুর্তি', 'kurta' => 'কুর্তা',
        'block' => 'ব্লক', 'blok' => 'ব্লক',
        'chunri' => 'চুনরি', 'chunari' => 'চুনারি',
        'chikonkari' => 'চিকনকারি', 'chikankaari' => 'চিকনকারি',
        'churi' => 'চুড়ি', 'chudi' => 'চুড়ি', 'bangle' => 'চুড়ি',
        'blouse' => 'ব্লাউজ', 'blaus' => 'ব্লাউজ',
        'silk' => 'সিল্ক', 'silkk' => 'সিল্ক', 'reshom' => 'রেশম',
        'cotton' => 'কটন', 'katun' => 'কটন', 'tula' => 'তুলা',
        'linen' => 'লিনেন', 'linin' => 'লিনেন',
        'halfsilk' => 'হাফসিল্ক', 'half silk' => 'হাফসিল্ক',
        'monipuri' => 'মণিপুরী', 'manipuri' => 'মণিপুরী', 'manupuri' => 'মণিপুরী',
        'madhurai' => 'মধুরাই', 'madhuray' => 'মধুরাই', 'madurai' => 'মধুরাই',
        'grameen' => 'গ্রামীণ', 'gramin' => 'গ্রামীণ',
        'tontuj' => 'তাঁতুজ', 'tantuj' => 'তাঁতুজ',
        'ambodray' => 'অম্বোদ্রায়', 'ambodroi' => 'অম্বোদ্রায়',
        'shotoronji' => 'সাতরঞ্জি', 'sataranji' => 'সাতরঞ্জি', 'shatranji' => 'সাতরঞ্জি',
        'shiburi' => 'শিবুরি', 'shibori' => 'শিবুরি',
        'tie dye' => 'টাই ডাই', 'tiedye' => 'টাই ডাই',
        'jute' => 'পাট', 'jut' => 'পাট', 'pat' => 'পাট',
        
        // Accessories & Home Items
        'mat' => 'মাদুর', 'madur' => 'মাদুর',
        'basket' => 'ঝুড়ি', 'jhuri' => 'ঝুড়ি',
        'bag' => 'ব্যাগ', 'byag' => 'ব্যাগ',
        'ornaments' => 'অলংকার', 'alangkar' => 'অলংকার',
        'wall runner' => 'ওয়াল রানার', 'runner' => 'রানার',
        'table mat' => 'টেবিল ম্যাট',
        
        // General Terms
        'piece' => 'পিস', 'pis' => 'পিস',
        'set' => 'সেট', 'sett' => 'সেট',
        'dress' => 'ড্রেস', 'dres' => 'ড্রেস',
        'coords' => 'কো-অর্ডস', 'co ords' => 'কো-অর্ডস',
        'offer' => 'অফার', 'ofar' => 'অফার',
        'exclusive' => 'এক্সক্লুসিভ', 'exlusive' => 'এক্সক্লুসিভ',
        'regular' => 'রেগুলার', 'reguler' => 'রেগুলার',
        'high range' => 'হাই রেঞ্জ', 'mid range' => 'মিড রেঞ্জ',
        'deshio' => 'দেশীও', 'deshiyo' => 'দেশীও',
        'production' => 'প্রোডাকশন', 'prodakshon' => 'প্রোডাকশন',
        
        // Fashion & Lifestyle Terms
        'sneakers' => 'স্নিকার্স', 'sneaker' => 'স্নিকার',
        'shoes' => 'জুতো', 'shoe' => 'জুতা',
        'slides' => 'স্লাইডস', 'slide' => 'স্লাইড',
        'sandals' => 'স্যান্ডেল', 'sandal' => 'স্যান্ডেল',
        'panjabi' => 'পাঞ্জাবি', 'punjabi' => 'পাঞ্জাবি',
        'thobes' => 'থোব', 'thobe' => 'থোব', 'jubba' => 'জুব্বা',
        'thob' => 'থোব',
        'sunglasses' => 'সানগ্লাস', 'sunglass' => 'সানগ্লাস', 'choshma' => 'চশমা',
        'jersey' => 'জার্সি', 'jersi' => 'জার্সি',
        'tshirt' => 'টি-শার্ট', 't-shirt' => 'টি-শার্ট',
        'polo' => 'পোলো',
        'watch' => 'ঘড়ি', 'ghori' => 'ঘড়ি',
        'wallet' => 'ওয়ালেট', 'manibag' => 'মানিব্যাগ',
        'belt' => 'বেল্ট',
        'perfume' => 'পারফিউম', 'ator' => 'আতর',
        
        // General Terms
        'piece' => 'পিস', 'pis' => 'পিস',
        'set' => 'সেট', 'sett' => 'সেট',
        'exclusive' => 'এক্সক্লুসিভ', 'exlusive' => 'এক্সক্লুসিভ',
        'offer' => 'অফার', 'ofar' => 'অফার',
        'premium' => 'প্রিমিয়াম', 'primiam' => 'প্রিমিয়াম',
        'original' => 'অরিজিনাল', 'orijinal' => 'অরিজিনাল',
    ];
    
    // Product-specific keyword aliases for better matching
    private $productKeywords = [
        // Jamdani variations (most searched)
        'jamdani' => ['jamdani', 'jomdani', 'jamadhani', 'jamadani', 'jamdany', 'jomdany', 
                      'jamdanny', 'jamdaani', 'jamdanee', 'jamdane', 'zamadani', 'zamadany',
                      'jamdini', 'jomdini', 'jamdoni', 'jamdanhi', 'jamdhani', 'jamadany'],
        
        // Saree variations (common typos)
        'saree' => ['saree', 'sari', 'sharee', 'shaari', 'shari', 'saari', 'sarree', 
                    'saary', 'sare', 'sarhi', 'sarei', 'shari', 'shadi', 'saadi'],
        
        // Silk variations
        'silk' => ['silk', 'silkk', 'reshom', 'resham', 'silc', 'cilk', 'silck', 
                   'resam', 'reshm', 'roshom', 'rasham', 'silky'],
        
        // Cotton variations
        'cotton' => ['cotton', 'katan', 'katun', 'suthi', 'tula', 'coton', 'cottan', 
                     'cotten', 'koton', 'kataan', 'katan', 'suti'],
        
        // Monipuri/Manipuri variations (highly misspelled)
        'monipuri' => ['monipuri', 'manipuri', 'manupuri', 'monipuri', 'monupuri', 
                       'monipori', 'manipori', 'monipuru', 'monipury', 'manopuri',
                       'moneepuri', 'manipury', 'manepuri', 'monipory', 'munipuri'],
        
        // Batik variations
        'batik' => ['batik', 'batick', 'batyk', 'battic', 'batiq', 'battik', 
                    'batick', 'batique', 'batic', 'batiek'],
        
        // Block variations
        'block' => ['block', 'blok', 'blck', 'bloc', 'bllock', 'bloq', 'bloak'],
        
        // Kurti variations
        'kurti' => ['kurti', 'kurty', 'kurthy', 'kurta', 'kurthi', 'kurthy', 
                    'kurty', 'kurtee', 'kurti', 'qurty', 'kurrti', 'kurtii'],
        
        // Orna variations
        'orna' => ['orna', 'urna', 'odna', 'odhna', 'orona', 'orana', 'urnaa', 
                   'odana', 'odona', 'urna', 'orrna'],
        
        // Jute variations
        'jute' => ['jute', 'jut', 'pat', 'jutee', 'joot', 'zhute', 'jutt', 
                   'jutte', 'paat', 'pate'],
        
        // Shotoronji variations (very commonly misspelled)
        'shotoronji' => ['shotoronji', 'sataranji', 'shataranji', 'shatranji', 
                         'sotoronji', 'shotoroni', 'shatronji', 'shotorongi',
                         'sotoronji', 'shotaronji', 'shatoronji', 'satoronji',
                         'shotaroni', 'shatarani', 'shotaronzi', 'shotoronzi'],
        
        // Linen variations
        'linen' => ['linen', 'linin', 'lynen', 'linen', 'linnen', 'linnin', 
                    'lynin', 'linin', 'linin'],
        
        // Chunri variations
        'chunri' => ['chunri', 'chunari', 'chonri', 'chunary', 'chunri', 'chunry',
                     'chonari', 'chunnri', 'chunrhi', 'chonri'],
        
        // Piece variations
        'piece' => ['piece', 'pis', 'pc', 'pcs', 'pice', 'peice', 'piec', 'pees',
                    'pise', 'piese', 'peece'],
        
        // Deshio variations (brand name)
        'deshio' => ['deshio', 'deshiyo', 'deshyo', 'deshyo', 'deshiyo', 'desheo',
                     'deshyo', 'desio', 'desheeo', 'deshiow'],
        
        // Madhurai variations
        'madhurai' => ['madhurai', 'madurai', 'madhuray', 'madhurei', 'madhuray',
                       'madurai', 'madhuri', 'madhorai', 'madhuri', 'maduray'],
        
        // Half silk variations
        'halfsilk' => ['halfsilk', 'half silk', 'half-silk', 'haf silk', 'halfsilc',
                       'half silc', 'haf silc', 'halfslik', 'haff silk', 'hafsilk'],
        
        // Tontuj variations
        'tontuj' => ['tontuj', 'tantuj', 'taantuj', 'tontuz', 'tontuj', 'tantoz',
                     'tantuj', 'tontoz', 'tantoz', 'tontug'],
        
        // Grameen variations
        'grameen' => ['grameen', 'gramin', 'grameen', 'gramiin', 'gramen', 'graamen',
                      'gramean', 'gramiin', 'grameeen'],
        
        // Chikonkari variations
        'chikonkari' => ['chikonkari', 'chikankaari', 'chikankaari', 'chikankari',
                         'chikonkary', 'chikankary', 'chikankary', 'chikonkary',
                         'chikankaari', 'chikankari'],
        
        // Ambodray variations (brand/style)
        'ambodray' => ['ambodray', 'ambodroi', 'ambodroy', 'ambodaray', 'ambodray',
                       'ambodraye', 'ambodrai', 'amboday'],
        
        // Churi (bangles) variations
        'churi' => ['churi', 'chudi', 'churi', 'chury', 'choori', 'choori', 'churhi',
                    'chudhi', 'churry'],
        
        // Salwar variations
        'salwar' => ['salwar', 'saloar', 'saluar', 'salwar', 'shalwar', 'salwaar',
                     'shalwar', 'saluar', 'salowar', 'salwar'],
        
        // Blouse variations
        'blouse' => ['blouse', 'blaus', 'blaouse', 'blouze', 'blous', 'blawse',
                     'blauz', 'blowse'],
        
        // Basket variations
        'basket' => ['basket', 'busket', 'baskit', 'bascket', 'baskett', 'bisket',
                     'baskat', 'buskit'],
        
        // Shiburi variations
        'shiburi' => ['shiburi', 'shibori', 'shibory', 'shiburi', 'shiboori', 'shebori',
                      'shiburi', 'shibury'],
        
        // Mat variations
        'mat' => ['mat', 'madur', 'matt', 'mate', 'madoor'],
        
        // Exclusive variations
        'exclusive' => ['exclusive', 'exlusive', 'exclusiv', 'exclucive', 'excluzive',
                        'exclussive', 'exklusive', 'excusive'],
        
        // Ornaments variations
        'ornaments' => ['ornaments', 'ornament', 'ornements', 'ornamnets', 'ornamants',
                        'ornaments', 'oornaments'],
    ];

    // Levenshtein distance threshold for fuzzy matching
    private $levenshteinThreshold = 3;
    
    // Similar text percentage threshold
    private $similarityThreshold = 60;

    /**
     * Advanced search with multi-language and fuzzy matching support
     * 
     * POST /api/products/advanced-search
     */
    public function advancedSearch(Request $request)
    {
        $validated = $request->validate([
            'query' => 'required|string|min:2',
            'category_id' => 'nullable|exists:categories,id',
            'vendor_id' => 'nullable|exists:vendors,id',
            'is_archived' => 'nullable|boolean',
            'enable_fuzzy' => 'nullable|boolean',
            'fuzzy_threshold' => 'nullable|integer|min:50|max:100',
            'search_fields' => 'nullable|array',
            'search_fields.*' => 'in:name,sku,category,custom_fields',
            'per_page' => 'nullable|integer|min:1|max:100',
            'stock_status' => 'nullable|string',
            'in_stock' => 'nullable|string',
            'group_by_sku' => 'nullable|boolean',
            'min_price' => 'nullable|numeric|min:0',
            'max_price' => 'nullable|numeric|min:0',
        ]);// Normalize stock status from both possible parameters
        $validated['stock_status'] = $validated['stock_status'] ?? $request->input('in_stock') ?? 'all';

        $query = $validated['query'];
        $enableFuzzy = $validated['enable_fuzzy'] ?? true;
        $fuzzyThreshold = $validated['fuzzy_threshold'] ?? $this->similarityThreshold;
        $searchFields = $validated['search_fields'] ?? ['name', 'sku', 'category', 'custom_fields'];

        // Step 1: Normalize and prepare search terms
        $searchTerms = $this->prepareSearchTerms($query);

        // Step 2: Build multi-stage search query
        $results = $this->executeMultiStageSearch($searchTerms, $searchFields, $validated);

        // Step 3: Apply fuzzy matching if enabled
        if ($enableFuzzy && count($results) < 10) {
            $fuzzyResults = $this->executeFuzzySearch($searchTerms, $searchFields, $validated, $fuzzyThreshold);
            $results = $this->mergeResults($results, $fuzzyResults);
        }

        // Step 4: Calculate relevance scores and sort
        $scoredResults = $this->scoreAndRankResults($results, $searchTerms);

        // Step 5: Paginate results
        $perPage = (int) ($validated['per_page'] ?? 15);
        $page = (int) $request->input('page', 1);
        
        $totalResults = count($scoredResults);
        $lastPage = (int) ceil($totalResults / $perPage);
        $paginatedItems = $scoredResults->slice(($page - 1) * $perPage, $perPage)->values();

        // Step 6: Load full relations only for the paginated results
        $finalResults = $this->loadFullRelations($paginatedItems);

        // Step 4.5: Group by SKU if requested
        if ($validated['group_by_sku'] ?? false) {
            $finalResults = $finalResults->groupBy('sku')->map(function($group) {
                // Pick the representative (highest score)
                $representative = $group->sortByDesc('relevance_score')->first();
                
                // Attach others as variants
                $representative->variants = $group->reject(function($p) use ($representative) {
                    return $p->id === $representative->id;
                })->values();
                
                $representative->has_variants = $representative->variants->isNotEmpty();
                $representative->variants_count = $representative->variants->count() + 1;
                
                return $representative;
            })->values();
            
            // Re-sort grouped results by the representative's score
            $finalResults = $finalResults->sortByDesc('relevance_score')->values();
        } else {
            // Ensure sequential keys for array serialization
            $finalResults = $finalResults->values();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $finalResults,
                'data' => $finalResults,
                'total' => $totalResults,
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'query' => $query,
                'search_terms' => $searchTerms,
                'search_metadata' => [
                    'fuzzy_enabled' => $enableFuzzy,
                    'fuzzy_threshold' => $fuzzyThreshold,
                    'search_fields' => $searchFields,
                    'grouped_by_sku' => $validated['group_by_sku'] ?? false,
                ],
            ]
        ]);
    }

    /**
     * Quick search with autocomplete support
     * 
     * GET /api/products/quick-search?q=query
     */
    public function quickSearch(Request $request)
    {
        $validated = $request->validate([
            'q' => 'required|string|min:1',
            'limit' => 'nullable|integer|min:1|max:20',
        ]);

        $query = $validated['q'];
        $limit = $validated['limit'] ?? 10;

        $searchTerms = $this->prepareSearchTerms($query);
        
        $results = Product::with(['category', 'vendor', 'images' => function($q) {
            $q->where('is_active', true)->where('is_primary', true);
        }])
            ->where('is_archived', false)
            ->where(function($q) use ($searchTerms) {
                foreach ($searchTerms as $term) {
                    $this->orWhereLike($q, 'name', $term)
                         ->orWhereLike($q, 'sku', $term);
                }
            })
            ->limit($limit)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $results->map(function($product) {
                $primaryImage = $product->images->first();
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'category' => $product->category->name ?? null,
                    'vendor' => $product->vendor->name ?? null,
                    'primary_image' => $primaryImage ? [
                        'id' => $primaryImage->id,
                        'url' => $primaryImage->image_url,
                        'alt_text' => $primaryImage->alt_text,
                    ] : null,
                ];
            }),
        ]);
    }

    /**
     * Search suggestions based on partial query
     * 
     * GET /api/products/search-suggestions?q=query
     */
    public function searchSuggestions(Request $request)
    {
        $validated = $request->validate([
            'q' => 'required|string|min:1',
            'limit' => 'nullable|integer|min:1|max:10',
        ]);

        $query = strtolower($validated['q']);
        $limit = $validated['limit'] ?? 5;

        $suggestions = [];

        // Get product name suggestions
        $productQuery = Product::query()->where('is_archived', false);
        $this->whereLike($productQuery, 'name', $query);
        $productNames = $productQuery->limit($limit)
            ->pluck('name')
            ->unique();

        foreach ($productNames as $name) {
            $suggestions[] = [
                'text' => $name,
                'type' => 'product',
                'relevance' => $this->calculateStringRelevance($query, strtolower($name)),
            ];
        }

        // Get category suggestions
        $categoryQuery = Category::query();
        $this->whereLike($categoryQuery, 'title', $query);
        $categories = $categoryQuery->limit(3)
            ->pluck('title');

        foreach ($categories as $category) {
            $suggestions[] = [
                'text' => $category,
                'type' => 'category',
                'relevance' => $this->calculateStringRelevance($query, strtolower($category)),
            ];
        }

        // Sort by relevance
        usort($suggestions, function($a, $b) {
            return $b['relevance'] <=> $a['relevance'];
        });

        return response()->json([
            'success' => true,
            'data' => array_slice($suggestions, 0, $limit),
        ]);
    }

    /**
     * Prepare search terms by normalizing and transliterating
     */
    private function prepareSearchTerms($query)
    {
        $terms = [];
        
        // Original query
        $terms[] = trim($query);
        
        // Lowercase version
        $lowercaseQuery = mb_strtolower($query, 'UTF-8');
        if ($lowercaseQuery !== $query) {
            $terms[] = $lowercaseQuery;
        }
        
        // Check for keyword aliases (e.g., "jamadhani" → add "jamdani" variations)
        foreach ($this->productKeywords as $baseKeyword => $aliases) {
            foreach ($aliases as $alias) {
                if (stripos($lowercaseQuery, $alias) !== false) {
                    // Add all aliases as search terms
                    $terms = array_merge($terms, $aliases);
                    break;
                }
            }
        }
        
        // Check if query contains Bangla characters
        if ($this->containsBangla($query)) {
            // Transliterate Bangla to Roman
            $romanized = $this->banglaToRoman($query);
            $terms[] = $romanized;
            $terms[] = mb_strtolower($romanized, 'UTF-8');
        } else {
            // Check if it's Romanized Bangla and convert to Bangla
            $banglaVersion = $this->romanToBangla($query);
            if ($banglaVersion !== $query) {
                $terms[] = $banglaVersion;
            }
            
            // Generate phonetic variations
            $phoneticVariations = $this->generatePhoneticVariations($query);
            $terms = array_merge($terms, $phoneticVariations);
        }

        // Seamless Improvement: Split query by spaces and add individual words (min 3 chars)
        $words = explode(' ', $lowercaseQuery);
        if (count($words) > 1) {
            foreach ($words as $word) {
                $word = trim($word);
                if (mb_strlen($word, 'UTF-8') >= 3) {
                    $terms[] = $word;
                }
            }
        }
        
        // Remove duplicates and empty strings
        $terms = array_unique(array_filter($terms));
        
        return $terms;
    }

    /**
     * Execute multi-stage search with exact and partial matching
     */
    private function executeMultiStageSearch($searchTerms, $searchFields, $filters)
    {
        if (empty($searchTerms)) return collect();

        $primaryTerm = $searchTerms[0];
        
        $dbQuery = Product::select('id', 'name', 'sku', 'category_id');
        $this->applyFilters($dbQuery, $filters);

        // Search matches across all terms and fields
        $dbQuery->where(function($q) use ($searchTerms, $searchFields) {
            foreach ($searchTerms as $term) {
                foreach ($searchFields as $field) {
                    $q->orWhere($field, 'LIKE', '%' . $term . '%');
                }
            }
        });

        // Optimized Stage Identification in SQL
        $dbQuery->selectRaw("CASE 
            WHEN name = ? OR sku = ? THEN 1
            WHEN name LIKE ? OR sku LIKE ? THEN 2
            ELSE 3
        END as search_stage", [
            $primaryTerm, $primaryTerm,
            $primaryTerm . '%', $primaryTerm . '%'
        ]);

        // Limit results to prevent memory issues before ranking
        return $dbQuery->limit(1000)->get();
    }

    /**
     * Execute fuzzy search for misspellings and variations
     */
    private function executeFuzzySearch($searchTerms, $searchFields, $filters, $threshold)
    {
        // For performance, we fetch only IDs for fuzzy matching, then hydrate later
        $query = Product::select('id', 'name', 'sku', 'category_id', 'vendor_id')
            ->where('is_archived', $filters['is_archived'] ?? false);
        
        if (isset($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }
        
        if (isset($filters['vendor_id'])) {
            $query->where('vendor_id', $filters['vendor_id']);
        }

        // Only fetch a limited set for fuzzy processing to avoid OOM
        $candidates = $query->limit(2000)->get();
        $allProducts = $query->select('id', 'name', 'sku', 'category_id')->limit(2000)->get();
        $fuzzyMatches = collect();
        
        foreach ($allProducts as $product) {
            $matchScore = 0;
            
            foreach ($searchTerms as $term) {
                // Check name fuzzy match
                if (in_array('name', $searchFields)) {
                    $nameScore = $this->calculateSimilarity($term, $product->name);
                    $matchScore = max($matchScore, $nameScore);
                }
                // Check SKU fuzzy match
                if (in_array('sku', $searchFields)) {
                    $skuScore = $this->calculateSimilarity($term, $product->sku);
                    $matchScore = max($matchScore, $skuScore);
                }
                
                // Check category fuzzy match
                if (in_array('category', $searchFields) && $product->category) {
                    $categoryScore = $this->calculateSimilarity($term, $product->category->title);
                    $matchScore = max($matchScore, $categoryScore * 0.8);
                }
            }
            
            if ($matchScore >= $threshold) {
                $product->search_stage = 'fuzzy';
                $product->base_score = $matchScore;
                $fuzzyMatches->push($product);
            }
        }
        
        return $fuzzyMatches;
    }

    /**
     * Apply common filters to a query
     */
    private function applyFilters($query, $filters)
    {
        $query->where('is_archived', $filters['is_archived'] ?? false);
        
        if (isset($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }
        
        if (isset($filters['vendor_id'])) {
            $query->where('vendor_id', $filters['vendor_id']);
        }

        if (isset($filters['min_price'])) {
            $query->whereRaw('(SELECT MIN(sell_price) FROM product_batches WHERE product_batches.product_id = products.id AND availability = 1 AND is_active = 1) >= ?', [(float) $filters['min_price']]);
        }

        if (isset($filters['max_price'])) {
            $query->whereRaw('(SELECT MIN(sell_price) FROM product_batches WHERE product_batches.product_id = products.id AND availability = 1 AND is_active = 1) <= ?', [(float) $filters['max_price']]);
        }

        // Stock status filter
        if (isset($filters['stock_status']) && $filters['stock_status'] !== 'all') {
            if ($filters['stock_status'] === 'in_stock') {
                $query->whereHas('batches', function($q) {
                    $q->where('is_active', true)
                      ->where('availability', true)
                      ->where('stock_qty', '>', 0);
                });
            } elseif ($filters['stock_status'] === 'not_in_stock') {
                $query->whereDoesntHave('batches', function($q) {
                    $q->where('is_active', true)
                      ->where('availability', true)
                      ->where('stock_qty', '>', 0);
                });
            }
        }
        
        return $query;
    }

    /**
     * Similar text percentage
     */
    private function calculateSimilarity($needle, $haystack)
    {
        if (empty($needle) || empty($haystack)) {
            return 0;
        }
        
        $needle = mb_strtolower($needle, 'UTF-8');
        $haystack = mb_strtolower($haystack, 'UTF-8');
        
        // Exact match
        if ($needle === $haystack) {
            return 100;
        }
        
        // Contains check
        if (strpos($haystack, $needle) !== false) {
            return 85;
        }
        
        // Similar text percentage
        similar_text($needle, $haystack, $percentage);
        
        // Levenshtein distance (only for shorter strings to avoid performance issues)
        if (strlen($needle) <= 255 && strlen($haystack) <= 255) {
            $distance = levenshtein($needle, $haystack);
            $maxLength = max(strlen($needle), strlen($haystack));
            $levenshteinScore = (1 - ($distance / $maxLength)) * 100;
            
            // Return the higher score
            return max($percentage, $levenshteinScore);
        }
        
        return $percentage;
    }

    /**
     * Search for exact matches
     */
    private function searchExact($searchTerms, $searchFields, $filters)
    {
        $query = Product::select('id', 'name', 'sku', 'category_id');

        $this->applyFilters($query, $filters);
        
        $query->where(function($q) use ($searchTerms, $searchFields) {
            foreach ($searchTerms as $term) {
                $q->orWhere(function($subQ) use ($term, $searchFields) {
                    if (in_array('name', $searchFields)) {
                        $subQ->orWhere('name', '=', $term);
                    }
                    if (in_array('sku', $searchFields)) {
                        $subQ->orWhere('sku', '=', $term);
                    }
                });
            }
        });
        
        return $query->get();
    }

    /**
     * Search for matches that start with the query
     */
    private function searchStartsWith($searchTerms, $searchFields, $filters)
    {
        $query = Product::select('id', 'name', 'sku', 'category_id');

        $this->applyFilters($query, $filters);
        
        $query->where(function($q) use ($searchTerms, $searchFields) {
            foreach ($searchTerms as $term) {
                $q->orWhere(function($subQ) use ($term, $searchFields) {
                    if (in_array('name', $searchFields)) {
                        $this->orWhereLike($subQ, 'name', $term, 'start');
                    }
                    if (in_array('sku', $searchFields)) {
                        $this->orWhereLike($subQ, 'sku', $term, 'start');
                    }
                });
            }
        });
        
        return $query->get();
    }

    /**
     * Search for matches that contain the query
     */
    private function searchContains($searchTerms, $searchFields, $filters)
    {
        $query = Product::select('id', 'name', 'sku', 'category_id');

        $this->applyFilters($query, $filters);
        
        $query->where(function($q) use ($searchTerms, $searchFields) {
            foreach ($searchTerms as $term) {
                $q->orWhere(function($subQ) use ($term, $searchFields) {
                    if (in_array('name', $searchFields)) {
                        $this->orWhereLike($subQ, 'name', $term);
                    }
                    if (in_array('sku', $searchFields)) {
                        $this->orWhereLike($subQ, 'sku', $term);
                    }
                    if (in_array('category', $searchFields)) {
                        $subQ->orWhereHas('category', function($catQ) use ($term) {
                            $this->whereLike($catQ, 'title', $term);
                        });
                    }
                    if (in_array('custom_fields', $searchFields)) {
                        $subQ->orWhereHas('productFields', function($fieldQ) use ($term) {
                            $this->whereLike($fieldQ, 'value', $term);
                        });
                    }
                });
            }
        });
        
        return $query->get();
    }

    /**
     * Score and rank results based on relevance
     */
    private function scoreAndRankResults($results, $searchTerms)
    {
        $scored = $results->map(function($product) use ($searchTerms) {
            $score = $product->base_score ?? 0;
            
            // Boost score based on match location
            foreach ($searchTerms as $index => $term) {
                $termLower = mb_strtolower($term, 'UTF-8');
                $nameLower = mb_strtolower($product->name, 'UTF-8');
                $skuLower = mb_strtolower($product->sku, 'UTF-8');
                
                // Weight: The first term (full query) gets much higher weight
                $weight = ($index === 0) ? 2.0 : 1.0;

                // Exact name match gets highest boost
                if ($nameLower === $termLower) {
                    $score += (50 * $weight);
                }
                
                // Exact SKU match
                if ($skuLower === $termLower) {
                    $score += (40 * $weight);
                }
                
                // Name starts with term
                if (strpos($nameLower, $termLower) === 0) {
                    $score += (30 * $weight);
                }
                
                // SKU starts with term
                if (strpos($skuLower, $termLower) === 0) {
                    $score += (25 * $weight);
                }
                
                // Name contains term
                if (strpos($nameLower, $termLower) !== false) {
                    $score += (15 * $weight);
                }

                // Category match
                if ($product->category && strpos(mb_strtolower($product->category->title, 'UTF-8'), $termLower) !== false) {
                    $score += (20 * $weight);
                }

                // Color/Size match (via custom fields)
                if ($product->productFields) {
                    foreach ($product->productFields as $pf) {
                        $fieldTitle = mb_strtolower($pf->field->title ?? '', 'UTF-8');
                        $valLower = mb_strtolower($pf->value ?? '', 'UTF-8');
                        
                        // Color boost
                        if (in_array($fieldTitle, ['color', 'colour']) && strpos($valLower, $termLower) !== false) {
                            $score += (15 * $weight);
                        }
                        
                        // Size boost
                        if ($fieldTitle === 'size' && $valLower === $termLower) {
                            $score += (10 * $weight);
                        }
                    }
                }
            }
            
            $product->relevance_score = $score;
            return $product;
        });
        
        // Sort by relevance score (descending)
        return $scored->sortByDesc('relevance_score')->values();
    }

    /**
     * Load full relations and format custom fields for final results
     */
    private function loadFullRelations($items)
    {
        if ($items->isEmpty()) return collect();

        $ids = $items->pluck('id')->toArray();
        $fullProducts = Product::with(['category', 'vendor', 'productFields.field', 'images' => function($q) {
                $q->where('is_active', true)->orderBy('is_primary', 'desc')->orderBy('sort_order');
            }])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        return $items->map(function($partial) use ($fullProducts) {
            $full = $fullProducts->get($partial->id);
            if ($full) {
                // Preserve the relevance score and search stage calculated during search
                $full->relevance_score = $partial->relevance_score;
                $full->search_stage = $partial->search_stage;
                $full->custom_fields = $this->formatCustomFields($full);
                return $full;
            }
            return $partial;
        })->filter();
    }

    /**
     * Format custom fields for display
     */
    private function formatCustomFields($product)
    {
        return $product->productFields->map(function ($pf) {
            return [
                'field_id' => $pf->field_id,
                'field_title' => $pf->field->title ?? 'Unknown',
                'field_type' => $pf->field->type ?? 'text',
                'value' => $pf->value,
                'raw_value' => $pf->value,
            ];
        });
    }

    /**
     * Merge two result sets, avoiding duplicates
     */
    private function mergeResults($results1, $results2)
    {
        $merged = collect($results1);
        
        foreach ($results2 as $item) {
            if (!$merged->contains('id', $item->id)) {
                $merged->push($item);
            }
        }
        
        return $merged;
    }

    /**
     * Paginate results manually
     */
    private function paginateResults($results, $perPage, $page)
    {
        $offset = ($page - 1) * $perPage;
        $items = $results->slice($offset, $perPage)->values();
        
        return [
            'items' => $items,
            'pagination' => [
                'total' => count($results),
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => ceil(count($results) / $perPage),
                'from' => $offset + 1,
                'to' => min($offset + $perPage, count($results)),
            ],
        ];
    }

    /**
     * Check if string contains Bangla characters
     */
    private function containsBangla($string)
    {
        return preg_match('/[\x{0980}-\x{09FF}]/u', $string) === 1;
    }

    /**
     * Transliterate Bangla to Roman characters
     */
    private function banglaToRoman($banglaText)
    {
        $roman = '';
        $length = mb_strlen($banglaText, 'UTF-8');
        
        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($banglaText, $i, 1, 'UTF-8');
            $roman .= $this->banglaToRomanMap[$char] ?? $char;
        }
        
        return $roman;
    }

    /**
     * Attempt to convert Romanized Bangla to Bangla script
     */
    private function romanToBangla($romanText)
    {
        $lowerRoman = mb_strtolower($romanText, 'UTF-8');
        
        // Check against common word mappings
        if (isset($this->romanToBanglaCommon[$lowerRoman])) {
            return $this->romanToBanglaCommon[$lowerRoman];
        }
        
        // Check for partial matches
        foreach ($this->romanToBanglaCommon as $roman => $bangla) {
            if (strpos($lowerRoman, $roman) !== false) {
                return $bangla;
            }
        }
        
        return $romanText;
    }

    /**
     * Generate phonetic variations of a string
     */
    private function generatePhoneticVariations($string)
    {
        $variations = [];
        
        // English phonetic variations
        $phoneticMap = [
            'ph' => 'f',
            'v' => 'bh',
            'w' => 'v',
            'c' => 'k',
            'z' => 's',
            'x' => 'ks',
            'sh' => 's',
            'ch' => 'c',
            'ck' => 'k',
            'qu' => 'kw',
        ];
        
        $lower = strtolower($string);
        
        foreach ($phoneticMap as $from => $to) {
            if (strpos($lower, $from) !== false) {
                $variations[] = str_replace($from, $to, $lower);
            }
            if (strpos($lower, $to) !== false) {
                $variations[] = str_replace($to, $from, $lower);
            }
        }
        
        // Common Bengali romanization variations
        $bengaliVariations = [
            'oo' => 'u',
            'u' => 'oo',
            'ee' => 'i',
            'i' => 'ee',
            'a' => 'aa',
            'aa' => 'a',
            'o' => 'ou',
            'ou' => 'o',
            'e' => 'a',
            'y' => 'i',
            'j' => 'z',
            'dh' => 'd',
            'th' => 't',
            'bh' => 'b',
        ];
        
        foreach ($bengaliVariations as $from => $to) {
            if (strpos($lower, $from) !== false) {
                $variations[] = str_replace($from, $to, $lower);
            }
        }
        
        // Keyboard proximity typos (QWERTY layout)
        $proximityMap = [
            'a' => ['s', 'q', 'z'],
            'e' => ['r', 'w', 'd'],
            'i' => ['o', 'u', 'k'],
            'o' => ['i', 'p', 'l'],
            'u' => ['i', 'y', 'j'],
            's' => ['a', 'd', 'w'],
            'd' => ['s', 'f', 'e'],
            'k' => ['j', 'l', 'i'],
            'l' => ['k', 'o'],
            'n' => ['b', 'm', 'h'],
            'm' => ['n', 'k'],
        ];
        
        // Generate single-character proximity variations (only for short strings)
        if (strlen($lower) <= 10) {
            for ($i = 0; $i < strlen($lower); $i++) {
                $char = $lower[$i];
                if (isset($proximityMap[$char])) {
                    foreach ($proximityMap[$char] as $replacement) {
                        $variation = substr_replace($lower, $replacement, $i, 1);
                        $variations[] = $variation;
                    }
                }
            }
        }
        
        // Double letter variations (common typos)
        $doubleLetterMap = [
            'l' => 'll',
            's' => 'ss',
            't' => 'tt',
            'n' => 'nn',
            'r' => 'rr',
            'm' => 'mm',
        ];
        
        foreach ($doubleLetterMap as $single => $double) {
            if (strpos($lower, $single) !== false && strpos($lower, $double) === false) {
                $variations[] = str_replace($single, $double, $lower);
            }
            if (strpos($lower, $double) !== false) {
                $variations[] = str_replace($double, $single, $lower);
            }
        }
        
        return array_unique($variations);
    }

    /**
     * Calculate string relevance for suggestions
     */
    private function calculateStringRelevance($query, $target)
    {
        if (empty($query) || empty($target)) {
            return 0;
        }
        
        // Exact match
        if ($query === $target) {
            return 100;
        }
        
        // Starts with
        if (strpos($target, $query) === 0) {
            return 90;
        }
        
        // Contains
        if (strpos($target, $query) !== false) {
            return 75;
        }
        
        // Similar text
        similar_text($query, $target, $percentage);
        return $percentage;
    }

    /**
     * Get search statistics and analytics
     * 
     * GET /api/products/search-stats
     */
    public function getSearchStats(Request $request)
    {
        // This would typically track search queries in a separate table
        // For now, return basic product statistics
        
        $stats = [
            'total_products' => Product::where('is_archived', false)->count(),
            'total_categories' => Category::where('is_active', true)->count(),
            'products_by_category' => Product::where('is_archived', false)
                ->select('category_id', DB::raw('count(*) as count'))
                ->groupBy('category_id')
                ->with('category:id,title')
                ->get(),
            'recent_products' => Product::where('is_archived', false)
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get(['id', 'name', 'sku', 'category_id']),
        ];
        
        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }
}
