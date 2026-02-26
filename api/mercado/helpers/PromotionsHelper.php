<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * PromotionsHelper - Calculo de promocoes avancadas (Happy Hour, BOGO, etc.)
 * ══════════════════════════════════════════════════════════════════════════════
 */

class PromotionsHelper
{
    private static ?PromotionsHelper $instance = null;
    private ?PDO $db = null;
    private array $cache = [];

    public static function getInstance(?PDO $db = null): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        if ($db) {
            self::$instance->setDb($db);
        }
        return self::$instance;
    }

    public function setDb(PDO $db): self
    {
        $this->db = $db;
        return $this;
    }

    /**
     * Validates that $this->db has been set, throws if not
     */
    private function requireDb(): PDO
    {
        if ($this->db === null) {
            throw new RuntimeException('PromotionsHelper: database not set. Call setDb() or pass PDO to getInstance() before use.');
        }
        return $this->db;
    }

    /**
     * Busca todas promocoes ativas agora para um parceiro
     */
    public function getActivePromotions(int $partnerId): array
    {
        $db = $this->requireDb();

        $cacheKey = "active_promos_{$partnerId}";
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $spTz = new DateTimeZone('America/Sao_Paulo');
        $now = new DateTime('now', $spTz);
        $currentDate = $now->format('Y-m-d');
        $currentTime = $now->format('H:i:s');
        $dayOfWeek = ((int)$now->format('w') + 1);

        $stmt = $db->prepare("
            SELECT *
            FROM om_promotions_advanced
            WHERE partner_id = ?
              AND status = '1'
              AND (valid_from IS NULL OR valid_from <= ?)
              AND (valid_until IS NULL OR valid_until >= ?)
              AND (max_uses IS NULL OR current_uses < max_uses)
            ORDER BY priority DESC
        ");
        $stmt->execute([$partnerId, $currentDate, $currentDate]);
        $allPromotions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $activePromotions = [];

        foreach ($allPromotions as $promo) {
            if ($this->isPromotionActiveNow($promo, $currentTime, $dayOfWeek)) {
                $activePromotions[] = $promo;
            }
        }

        $this->cache[$cacheKey] = $activePromotions;
        return $activePromotions;
    }

    /**
     * Verifica se uma promocao esta ativa no momento
     */
    private function isPromotionActiveNow(array $promo, string $currentTime, int $dayOfWeek): bool
    {
        if ($promo['type'] === 'happy_hour') {
            // Verificar dia da semana
            $allowedDays = array_map('intval', explode(',', $promo['days_of_week'] ?? '1,2,3,4,5,6,7'));
            if (!in_array($dayOfWeek, $allowedDays)) {
                return false;
            }

            // Verificar horario
            if ($promo['start_time'] && $promo['end_time']) {
                $startTime = $promo['start_time'];
                $endTime = $promo['end_time'];

                if ($endTime < $startTime) {
                    if ($currentTime < $startTime && $currentTime >= $endTime) {
                        return false;
                    }
                } else {
                    if ($currentTime < $startTime || $currentTime >= $endTime) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Verifica se uma promocao se aplica a um produto
     */
    private function promotionAppliesToProduct(array $promo, int $productId, ?int $categoryId = null): bool
    {
        if ($promo['applies_to'] === 'all') {
            return true;
        }

        if ($promo['applies_to'] === 'products') {
            $productIds = $promo['product_ids'] ? json_decode($promo['product_ids'], true) : [];
            return in_array($productId, $productIds);
        }

        if ($promo['applies_to'] === 'category' && $categoryId) {
            $categoryIds = $promo['category_ids'] ? json_decode($promo['category_ids'], true) : [];
            return in_array($categoryId, $categoryIds);
        }

        return false;
    }

    /**
     * Aplica promocoes a um carrinho de itens
     *
     * @param int $partnerId ID do parceiro
     * @param array $items Array de itens: [['product_id' => X, 'quantity' => Y, 'price' => Z, 'category_id' => W], ...]
     * @param int|null $customerId ID do cliente (para verificar limites por cliente)
     * @return array Resultado com descontos aplicados
     */
    public function applyPromotionsToCart(int $partnerId, array $items, ?int $customerId = null): array
    {
        $promotions = $this->getActivePromotions($partnerId);

        if (empty($promotions)) {
            return [
                'items' => $items,
                'promotions_applied' => [],
                'total_discount' => 0,
                'savings_breakdown' => []
            ];
        }

        // Buscar categorias dos produtos se nao fornecidas
        $productIds = array_column($items, 'product_id');
        $productCategories = $this->getProductCategories($productIds);

        $promotionsApplied = [];
        $totalDiscount = 0;
        $savingsBreakdown = [];

        // Processar cada item
        foreach ($items as &$item) {
            $productId = (int)$item['product_id'];
            $quantity = (int)$item['quantity'];
            $unitPrice = (float)$item['price'];
            $categoryId = $item['category_id'] ?? ($productCategories[$productId] ?? null);

            $itemDiscount = 0;
            $itemPromotions = [];

            // Aplicar Happy Hour (desconto direto)
            foreach ($promotions as $promo) {
                if ($promo['type'] === 'happy_hour' && $this->promotionAppliesToProduct($promo, $productId, $categoryId)) {
                    $discountPercent = (float)$promo['discount_percent'];
                    $discount = round($unitPrice * $quantity * ($discountPercent / 100), 2);

                    $itemDiscount += $discount;
                    $itemPromotions[] = [
                        'type' => 'happy_hour',
                        'promo_id' => (int)$promo['id'],
                        'name' => $promo['name'],
                        'badge_text' => $promo['badge_text'],
                        'discount' => $discount,
                        'discount_percent' => $discountPercent
                    ];

                    if (!isset($promotionsApplied[$promo['id']])) {
                        $promotionsApplied[$promo['id']] = [
                            'id' => (int)$promo['id'],
                            'type' => 'happy_hour',
                            'name' => $promo['name'],
                            'total_discount' => 0,
                            'items_affected' => 0
                        ];
                    }
                    $promotionsApplied[$promo['id']]['total_discount'] += $discount;
                    $promotionsApplied[$promo['id']]['items_affected']++;
                }
            }

            // Aplicar BOGO (Compre X, Leve Y)
            foreach ($promotions as $promo) {
                if ($promo['type'] === 'bogo' && $this->promotionAppliesToProduct($promo, $productId, $categoryId)) {
                    $buyQty = (int)$promo['buy_quantity'];
                    $getQty = (int)$promo['get_quantity'];
                    $getDiscountPercent = (float)$promo['get_discount_percent'];

                    // Quantas vezes o cliente atinge a promocao?
                    $cycleSize = $buyQty + $getQty;
                    $fullCycles = floor($quantity / $cycleSize);
                    $remainder = $quantity % $cycleSize;

                    // Itens com desconto = getQty * fullCycles + max(0, remainder - buyQty)
                    $discountedItems = $fullCycles * $getQty;
                    if ($remainder > $buyQty) {
                        $discountedItems += ($remainder - $buyQty);
                    }

                    if ($discountedItems > 0) {
                        $discount = round($unitPrice * $discountedItems * ($getDiscountPercent / 100), 2);

                        $itemDiscount += $discount;
                        $itemPromotions[] = [
                            'type' => 'bogo',
                            'promo_id' => (int)$promo['id'],
                            'name' => $promo['name'],
                            'badge_text' => $promo['badge_text'],
                            'discount' => $discount,
                            'items_free' => $discountedItems,
                            'discount_percent' => $getDiscountPercent
                        ];

                        if (!isset($promotionsApplied[$promo['id']])) {
                            $promotionsApplied[$promo['id']] = [
                                'id' => (int)$promo['id'],
                                'type' => 'bogo',
                                'name' => $promo['name'],
                                'total_discount' => 0,
                                'items_affected' => 0
                            ];
                        }
                        $promotionsApplied[$promo['id']]['total_discount'] += $discount;
                        $promotionsApplied[$promo['id']]['items_affected'] += $discountedItems;
                    }
                }
            }

            // Aplicar Desconto por Quantidade
            foreach ($promotions as $promo) {
                if ($promo['type'] === 'quantity_discount' && $this->promotionAppliesToProduct($promo, $productId, $categoryId)) {
                    $minQty = (int)$promo['min_quantity'];
                    $discountPercent = (float)$promo['quantity_discount_percent'];

                    if ($quantity >= $minQty) {
                        $discount = round($unitPrice * $quantity * ($discountPercent / 100), 2);

                        $itemDiscount += $discount;
                        $itemPromotions[] = [
                            'type' => 'quantity_discount',
                            'promo_id' => (int)$promo['id'],
                            'name' => $promo['name'],
                            'badge_text' => $promo['badge_text'],
                            'discount' => $discount,
                            'discount_percent' => $discountPercent
                        ];

                        if (!isset($promotionsApplied[$promo['id']])) {
                            $promotionsApplied[$promo['id']] = [
                                'id' => (int)$promo['id'],
                                'type' => 'quantity_discount',
                                'name' => $promo['name'],
                                'total_discount' => 0,
                                'items_affected' => 0
                            ];
                        }
                        $promotionsApplied[$promo['id']]['total_discount'] += $discount;
                        $promotionsApplied[$promo['id']]['items_affected']++;
                    }
                }
            }

            // Atualizar item com desconto
            $item['discount'] = $itemDiscount;
            $item['promotions'] = $itemPromotions;
            $item['final_total'] = round(($unitPrice * $quantity) - $itemDiscount, 2);

            $totalDiscount += $itemDiscount;

            if (!empty($itemPromotions)) {
                $savingsBreakdown[] = [
                    'product_id' => $productId,
                    'product_name' => $item['name'] ?? "Produto #{$productId}",
                    'original_total' => round($unitPrice * $quantity, 2),
                    'discount' => $itemDiscount,
                    'final_total' => $item['final_total'],
                    'promotions' => $itemPromotions
                ];
            }
        }

        return [
            'items' => $items,
            'promotions_applied' => array_values($promotionsApplied),
            'total_discount' => round($totalDiscount, 2),
            'savings_breakdown' => $savingsBreakdown
        ];
    }

    /**
     * Busca categorias dos produtos
     */
    private function getProductCategories(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        $db = $this->requireDb();
        $ph = implode(',', array_fill(0, count($productIds), '?'));
        $stmt = $db->prepare("SELECT id, category_id FROM om_market_products WHERE id IN ($ph)");
        $stmt->execute($productIds);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int)$row['id']] = (int)$row['category_id'];
        }
        return $result;
    }

    /**
     * Registra uso de promocoes apos pedido confirmado
     *
     * Idempotent: uses INSERT ... ON CONFLICT DO NOTHING on (promotion_id, order_id)
     * so duplicate calls for the same order are safe. current_uses is only
     * incremented when the insert actually occurs (rowCount > 0).
     * Wrapped in a transaction to keep usage row and counter in sync.
     */
    public function recordPromotionUsage(array $promotionsApplied, int $customerId, int $orderId): void
    {
        if (empty($promotionsApplied)) {
            return;
        }

        $db = $this->requireDb();

        // If caller already started a transaction, we participate; otherwise start our own
        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }

        try {
            $stmtInsert = $db->prepare("
                INSERT INTO om_promotions_advanced_usage
                    (promotion_id, customer_id, order_id, discount_amount, items_affected, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
                ON CONFLICT (promotion_id, order_id) DO NOTHING
            ");

            $stmtUpdate = $db->prepare("
                UPDATE om_promotions_advanced
                SET current_uses = current_uses + 1
                WHERE id = ?
            ");

            foreach ($promotionsApplied as $promo) {
                $stmtInsert->execute([
                    $promo['id'],
                    $customerId,
                    $orderId,
                    $promo['total_discount'],
                    $promo['items_affected']
                ]);

                // Only increment counter if the row was actually inserted (not a duplicate)
                if ($stmtInsert->rowCount() > 0) {
                    $stmtUpdate->execute([$promo['id']]);
                }
            }

            if ($ownTransaction) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Verifica limite de uso por cliente
     */
    public function checkCustomerUsageLimit(int $promotionId, int $customerId, int $maxPerCustomer): bool
    {
        $db = $this->requireDb();
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM om_promotions_advanced_usage
            WHERE promotion_id = ? AND customer_id = ?
        ");
        $stmt->execute([$promotionId, $customerId]);
        $usageCount = (int)$stmt->fetchColumn();

        return $usageCount < $maxPerCustomer;
    }

    /**
     * Calcula preco com desconto de Happy Hour para exibicao
     */
    public function getHappyHourPrice(float $originalPrice, int $partnerId, int $productId, ?int $categoryId = null): ?array
    {
        $promotions = $this->getActivePromotions($partnerId);

        foreach ($promotions as $promo) {
            if ($promo['type'] === 'happy_hour' && $this->promotionAppliesToProduct($promo, $productId, $categoryId)) {
                $discountPercent = (float)$promo['discount_percent'];
                $discountedPrice = round($originalPrice * (1 - $discountPercent / 100), 2);

                return [
                    'original_price' => $originalPrice,
                    'discounted_price' => $discountedPrice,
                    'discount_percent' => $discountPercent,
                    'badge_text' => $promo['badge_text'],
                    'badge_color' => $promo['badge_color'],
                    'promo_name' => $promo['name']
                ];
            }
        }

        return null;
    }

    /**
     * Limpa cache
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }
}

/**
 * Helper function para acesso rapido
 */
function promotions(): PromotionsHelper
{
    return PromotionsHelper::getInstance();
}
