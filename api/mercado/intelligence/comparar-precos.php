<?php
/**
 * POST /api/mercado/intelligence/comparar-precos.php
 *
 * Price comparator: given a list of product names, search across all active partners
 * for matching products and return per-store totals with delivery info.
 *
 * Request body:
 *   { "items": ["arroz", "feijao", "leite"], "lat": -23.5, "lng": -46.6 }
 *
 * Response:
 *   { "stores": [...], "item_count": 3, "items_summary": [...] }
 */
require_once __DIR__ . "/../config/database.php";
setCorsHeaders();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    response(false, null, "Metodo nao permitido", 405);
}

$input = getInput();
$items = $input["items"] ?? [];

if (!is_array($items) || count($items) === 0) {
    response(false, null, "Informe pelo menos um item para comparar", 400);
}

// Sanitize and limit items
$items = array_slice(array_filter(array_map(function ($item) {
    return trim(strip_tags((string)$item));
}, $items)), 0, 50); // max 50 items

if (count($items) === 0) {
    response(false, null, "Nenhum item valido informado", 400);
}

// Optional customer lat/lng for distance-based sorting (future use)
$lat = isset($input["lat"]) ? floatval($input["lat"]) : null;
$lng = isset($input["lng"]) ? floatval($input["lng"]) : null;

try {
    $db = getDB();

    // ─── 1. Get all active partners (mercado/supermercado type preferred) ───
    $partnerStmt = dbQuery($db, "
        SELECT
            partner_id, name, trade_name, logo, rating,
            delivery_fee, taxa_entrega, delivery_time_min, delivery_time_max,
            tempo_preparo, free_delivery_above, min_order, min_order_value,
            store_type, categoria, is_open, busy_mode, city, state,
            latitude, longitude
        FROM om_market_partners
        WHERE status::text = '1'
          AND (is_test IS NULL OR is_test = 0)
          AND (pause_until IS NULL OR pause_until < NOW())
        ORDER BY rating DESC NULLS LAST
    ");
    $partners = $partnerStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($partners)) {
        response(true, ["stores" => [], "item_count" => count($items), "items_summary" => []]);
    }

    // Index partners by ID
    $partnerMap = [];
    foreach ($partners as $p) {
        $partnerMap[$p["partner_id"]] = $p;
    }
    $partnerIds = array_keys($partnerMap);

    // ─── 2. For each item, search products across all partners ───
    // Build a single query that searches all items at once using ILIKE
    // We use a scoring system: exact match > starts with > contains > word match
    $matchedItems = []; // itemName => [ { partner_id, product_id, name, price, price_promo, image } ]

    foreach ($items as $itemName) {
        $searchTerm = mb_strtolower(trim($itemName), 'UTF-8');
        if (empty($searchTerm)) continue;

        // Normalize common variations
        $searchNormalized = preg_replace('/\s+/', ' ', $searchTerm);

        // Build search patterns
        $exactPattern = $searchNormalized;
        $startsPattern = $searchNormalized . '%';
        $containsPattern = '%' . $searchNormalized . '%';

        // Split into words for word matching
        $words = array_filter(explode(' ', $searchNormalized), function ($w) {
            return mb_strlen($w) >= 3; // ignore very short words
        });

        // Build partner_id IN clause
        $pidPlaceholders = implode(',', array_fill(0, count($partnerIds), '?'));

        // Main search query with scoring
        $sql = "
            SELECT
                pp.partner_id,
                pp.product_id,
                pb.name,
                pb.image,
                pb.brand,
                pp.price,
                pp.price_promo,
                pp.promo_start,
                pp.promo_end,
                pp.stock,
                CASE
                    WHEN LOWER(pb.name) = ? THEN 100
                    WHEN LOWER(pb.name) ILIKE ? THEN 80
                    WHEN LOWER(pb.name) ILIKE ? THEN 60
                    ELSE 40
                END AS match_score
            FROM om_market_partner_products pp
            JOIN om_market_products_base pb ON pb.product_id = pp.product_id
            WHERE pp.partner_id IN ({$pidPlaceholders})
              AND pp.active = 1
              AND (pp.stock IS NULL OR pp.stock > 0)
              AND pb.status = 1
              AND LOWER(pb.name) ILIKE ?
            ORDER BY match_score DESC, pp.price ASC
        ";

        $params = array_merge(
            [$exactPattern, $startsPattern, $containsPattern],
            $partnerIds,
            [$containsPattern]
        );

        $searchStmt = dbQuery($db, $sql, $params);
        $results = $searchStmt->fetchAll(PDO::FETCH_ASSOC);

        // Group by partner, keep best match per partner
        $perPartner = [];
        foreach ($results as $row) {
            $pid = $row["partner_id"];
            if (!isset($perPartner[$pid])) {
                // Check if promo is currently active
                $price = floatval($row["price"]);
                $promoPrice = floatval($row["price_promo"] ?? 0);
                $promoActive = false;
                if ($promoPrice > 0 && $promoPrice < $price) {
                    $now = date('Y-m-d');
                    $promoStart = $row["promo_start"] ?? null;
                    $promoEnd = $row["promo_end"] ?? null;
                    if ((!$promoStart || $promoStart <= $now) && (!$promoEnd || $promoEnd >= $now)) {
                        $promoActive = true;
                    }
                }
                $effectivePrice = $promoActive ? $promoPrice : $price;

                $perPartner[$pid] = [
                    "partner_id" => (int)$pid,
                    "product_id" => (int)$row["product_id"],
                    "name" => $row["name"],
                    "image" => $row["image"],
                    "brand" => $row["brand"],
                    "price" => round($effectivePrice, 2),
                    "original_price" => round($price, 2),
                    "has_promo" => $promoActive,
                    "match_score" => (int)$row["match_score"],
                    "stock" => $row["stock"] !== null ? (int)$row["stock"] : null,
                ];
            }
        }

        // If no ILIKE match found, try word-based fuzzy matching
        if (empty($perPartner) && count($words) >= 1) {
            $wordConditions = [];
            $wordParams = [];
            foreach ($words as $word) {
                $wordConditions[] = "LOWER(pb.name) ILIKE ?";
                $wordParams[] = '%' . $word . '%';
            }
            $wordWhere = implode(' AND ', $wordConditions);

            $fuzzySql = "
                SELECT
                    pp.partner_id,
                    pp.product_id,
                    pb.name,
                    pb.image,
                    pb.brand,
                    pp.price,
                    pp.price_promo,
                    pp.promo_start,
                    pp.promo_end,
                    pp.stock,
                    30 AS match_score
                FROM om_market_partner_products pp
                JOIN om_market_products_base pb ON pb.product_id = pp.product_id
                WHERE pp.partner_id IN ({$pidPlaceholders})
                  AND pp.active = 1
                  AND (pp.stock IS NULL OR pp.stock > 0)
                  AND pb.status = 1
                  AND ({$wordWhere})
                ORDER BY pp.price ASC
                LIMIT 100
            ";

            $fuzzyParams = array_merge($partnerIds, $wordParams);
            $fuzzyStmt = dbQuery($db, $fuzzySql, $fuzzyParams);
            $fuzzyResults = $fuzzyStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($fuzzyResults as $row) {
                $pid = $row["partner_id"];
                if (!isset($perPartner[$pid])) {
                    $price = floatval($row["price"]);
                    $promoPrice = floatval($row["price_promo"] ?? 0);
                    $promoActive = false;
                    if ($promoPrice > 0 && $promoPrice < $price) {
                        $now = date('Y-m-d');
                        $promoStart = $row["promo_start"] ?? null;
                        $promoEnd = $row["promo_end"] ?? null;
                        if ((!$promoStart || $promoStart <= $now) && (!$promoEnd || $promoEnd >= $now)) {
                            $promoActive = true;
                        }
                    }
                    $effectivePrice = $promoActive ? $promoPrice : $price;

                    $perPartner[$pid] = [
                        "partner_id" => (int)$pid,
                        "product_id" => (int)$row["product_id"],
                        "name" => $row["name"],
                        "image" => $row["image"],
                        "brand" => $row["brand"],
                        "price" => round($effectivePrice, 2),
                        "original_price" => round($price, 2),
                        "has_promo" => $promoActive,
                        "match_score" => (int)$row["match_score"],
                        "stock" => $row["stock"] !== null ? (int)$row["stock"] : null,
                    ];
                }
            }
        }

        $matchedItems[$itemName] = $perPartner;
    }

    // ─── 3. Build per-store comparison ───
    $storeResults = [];

    foreach ($partnerMap as $pid => $partner) {
        $foundItems = [];
        $itemsTotal = 0;

        foreach ($items as $itemName) {
            if (isset($matchedItems[$itemName][$pid])) {
                $match = $matchedItems[$itemName][$pid];
                $foundItems[] = [
                    "requested" => $itemName,
                    "found" => $match["name"],
                    "product_id" => $match["product_id"],
                    "image" => $match["image"],
                    "brand" => $match["brand"],
                    "price" => $match["price"],
                    "original_price" => $match["original_price"],
                    "has_promo" => $match["has_promo"],
                    "match_score" => $match["match_score"],
                ];
                $itemsTotal += $match["price"];
            }
        }

        // Skip stores with zero matches
        if (empty($foundItems)) continue;

        $deliveryFee = floatval($partner["delivery_fee"] ?? $partner["taxa_entrega"] ?? 0);
        $freeAbove = floatval($partner["free_delivery_above"] ?? 0);

        // Check if qualifies for free delivery
        if ($freeAbove > 0 && $itemsTotal >= $freeAbove) {
            $deliveryFee = 0;
        }

        $deliveryTimeMin = (int)($partner["delivery_time_min"] ?? $partner["tempo_preparo"] ?? 30);
        $deliveryTimeMax = (int)($partner["delivery_time_max"] ?? $deliveryTimeMin + 15);

        $storeName = $partner["trade_name"] ?: $partner["name"];
        $rating = $partner["rating"] ? round(floatval($partner["rating"]), 1) : null;

        $storeResults[] = [
            "partner_id" => (int)$pid,
            "name" => $storeName,
            "logo" => $partner["logo"],
            "rating" => $rating,
            "city" => $partner["city"],
            "state" => $partner["state"],
            "store_type" => $partner["store_type"] ?? $partner["categoria"] ?? "mercado",
            "is_open" => (bool)($partner["is_open"] ?? false),
            "busy_mode" => (bool)($partner["busy_mode"] ?? false),
            "items_found" => count($foundItems),
            "items_total" => count($items),
            "items_subtotal" => round($itemsTotal, 2),
            "delivery_fee" => round($deliveryFee, 2),
            "delivery_fee_original" => round(floatval($partner["delivery_fee"] ?? $partner["taxa_entrega"] ?? 0), 2),
            "free_delivery_above" => $freeAbove > 0 ? round($freeAbove, 2) : null,
            "grand_total" => round($itemsTotal + $deliveryFee, 2),
            "delivery_time_min" => $deliveryTimeMin,
            "delivery_time_max" => $deliveryTimeMax,
            "min_order" => floatval($partner["min_order"] ?? $partner["min_order_value"] ?? 0),
            "found_items" => $foundItems,
        ];
    }

    // ─── 4. Compute badges ───
    if (!empty($storeResults)) {
        // Sort by grand_total to find cheapest
        $cheapestTotal = PHP_FLOAT_MAX;
        $cheapestIdx = -1;
        $mostItemsCount = 0;
        $mostItemsIdx = -1;
        $fastestTime = PHP_INT_MAX;
        $fastestIdx = -1;

        foreach ($storeResults as $i => $store) {
            // Cheapest (only consider stores with at least 50% of items)
            if ($store["items_found"] >= ceil(count($items) * 0.5)) {
                if ($store["grand_total"] < $cheapestTotal) {
                    $cheapestTotal = $store["grand_total"];
                    $cheapestIdx = $i;
                }
            }
            // Most complete
            if ($store["items_found"] > $mostItemsCount ||
                ($store["items_found"] === $mostItemsCount && $mostItemsIdx >= 0 && $store["grand_total"] < $storeResults[$mostItemsIdx]["grand_total"])) {
                $mostItemsCount = $store["items_found"];
                $mostItemsIdx = $i;
            }
            // Fastest
            if ($store["delivery_time_min"] < $fastestTime) {
                $fastestTime = $store["delivery_time_min"];
                $fastestIdx = $i;
            }
        }

        // Assign badges
        foreach ($storeResults as $i => &$store) {
            $store["badges"] = [];
            if ($i === $cheapestIdx) $store["badges"][] = "cheapest";
            if ($i === $mostItemsIdx && $mostItemsCount > 0) $store["badges"][] = "most_complete";
            if ($i === $fastestIdx) $store["badges"][] = "fastest";
        }
        unset($store);

        // Default sort: cheapest grand_total (with items_found as tiebreaker)
        usort($storeResults, function ($a, $b) {
            // Primary: more items found is better
            if ($a["items_found"] !== $b["items_found"]) {
                return $b["items_found"] - $a["items_found"];
            }
            // Secondary: cheaper grand_total
            return $a["grand_total"] <=> $b["grand_total"];
        });
    }

    // ─── 5. Build per-item availability summary ───
    $itemSummary = [];
    foreach ($items as $itemName) {
        $storeCount = 0;
        $minPrice = PHP_FLOAT_MAX;
        $maxPrice = 0;
        foreach ($matchedItems[$itemName] ?? [] as $pid => $match) {
            $storeCount++;
            if ($match["price"] < $minPrice) $minPrice = $match["price"];
            if ($match["price"] > $maxPrice) $maxPrice = $match["price"];
        }
        $itemSummary[] = [
            "name" => $itemName,
            "available_in" => $storeCount,
            "min_price" => $storeCount > 0 ? round($minPrice, 2) : null,
            "max_price" => $storeCount > 0 ? round($maxPrice, 2) : null,
            "price_spread" => $storeCount > 0 ? round($maxPrice - $minPrice, 2) : null,
        ];
    }

    response(true, [
        "stores" => $storeResults,
        "item_count" => count($items),
        "items_summary" => $itemSummary,
    ]);

} catch (Exception $e) {
    error_log("[ComparePrices] Error: " . $e->getMessage());
    response(false, null, "Erro ao comparar precos: " . $e->getMessage(), 500);
}
