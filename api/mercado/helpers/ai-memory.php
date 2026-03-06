<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * AI CALL CENTER — CONVERSATION MEMORY
 * Persistent memory across calls for the SuperBora AI call center.
 * Tracks customer preferences, order history, complaints, and call patterns.
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * SQL (run once):
 *
 * CREATE TABLE IF NOT EXISTS om_ai_call_memory (
 *     id SERIAL PRIMARY KEY,
 *     customer_phone VARCHAR(20) NOT NULL,
 *     customer_id INT,
 *     memory_type VARCHAR(30) NOT NULL,
 *     memory_key VARCHAR(100) NOT NULL,
 *     memory_value TEXT NOT NULL,
 *     confidence FLOAT DEFAULT 1.0,
 *     times_confirmed INT DEFAULT 1,
 *     last_used_at TIMESTAMP DEFAULT NOW(),
 *     created_at TIMESTAMP DEFAULT NOW(),
 *     updated_at TIMESTAMP DEFAULT NOW(),
 *     UNIQUE(customer_phone, memory_type, memory_key)
 * );
 *
 * CREATE INDEX IF NOT EXISTS idx_ai_call_memory_phone
 *     ON om_ai_call_memory(customer_phone);
 * CREATE INDEX IF NOT EXISTS idx_ai_call_memory_customer
 *     ON om_ai_call_memory(customer_id)
 *     WHERE customer_id IS NOT NULL;
 * CREATE INDEX IF NOT EXISTS idx_ai_call_memory_type
 *     ON om_ai_call_memory(customer_phone, memory_type);
 * CREATE INDEX IF NOT EXISTS idx_ai_call_memory_last_used
 *     ON om_ai_call_memory(last_used_at DESC);
 *
 */

/**
 * Normalize phone number to a consistent format for lookups.
 * Strips everything except digits, ensures 11-digit Brazilian format.
 */
function aiMemoryNormalizePhone(string $phone): string
{
    $digits = preg_replace('/\D/', '', $phone);
    // Remove country code 55 if present (13 digits: 55 + 11)
    if (strlen($digits) === 13 && str_starts_with($digits, '55')) {
        $digits = substr($digits, 2);
    }
    // Remove leading zero if 12 digits (0 + DDD + 9-digit mobile)
    if (strlen($digits) === 12 && str_starts_with($digits, '0')) {
        $digits = substr($digits, 1);
    }
    return $digits;
}

/**
 * Load all memories for a customer phone number.
 *
 * @return array{
 *     preferences: array<string, array{value: string, confidence: float, times: int}>,
 *     order_history: array<string, array{value: string, confidence: float, times: int}>,
 *     complaints: array<string, array{value: string, confidence: float, times: int}>,
 *     notes: array<string, array{value: string, confidence: float, times: int}>,
 *     patterns: array<string, array{value: string, confidence: float, times: int}>
 * }
 */
function aiMemoryLoad(PDO $db, string $phone, ?int $customerId = null): array
{
    $phone = aiMemoryNormalizePhone($phone);

    $result = [
        'preference'    => [],
        'order_history' => [],
        'complaint'     => [],
        'note'          => [],
        'pattern'       => [],
    ];

    // Load by phone, or also by customer_id if known
    $conditions = ['m.customer_phone = ?'];
    $params     = [$phone];

    if ($customerId) {
        $conditions[] = 'm.customer_id = ?';
        $params[]     = $customerId;
    }

    $where = implode(' OR ', $conditions);

    $stmt = $db->prepare("
        SELECT memory_type, memory_key, memory_value, confidence, times_confirmed, last_used_at
        FROM om_ai_call_memory m
        WHERE ({$where})
        ORDER BY memory_type, times_confirmed DESC, last_used_at DESC
    ");
    $stmt->execute($params);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $type = $row['memory_type'];
        if (!isset($result[$type])) {
            $result[$type] = [];
        }
        $result[$type][$row['memory_key']] = [
            'value'      => $row['memory_value'],
            'confidence' => (float)$row['confidence'],
            'times'      => (int)$row['times_confirmed'],
            'last_used'  => $row['last_used_at'],
        ];
    }

    // Touch last_used_at so we know this memory set was accessed
    $db->prepare("
        UPDATE om_ai_call_memory
        SET last_used_at = NOW()
        WHERE customer_phone = ?
    ")->execute([$phone]);

    return $result;
}

/**
 * Save or update a single memory entry (upsert).
 * On conflict, increments times_confirmed and raises confidence if re-confirmed.
 */
function aiMemorySave(
    PDO    $db,
    string $phone,
    ?int   $customerId,
    string $type,
    string $key,
    string $value
): void {
    $phone = aiMemoryNormalizePhone($phone);

    // Validate memory_type
    $validTypes = ['preference', 'order_history', 'complaint', 'note', 'pattern'];
    if (!in_array($type, $validTypes, true)) {
        error_log("[aiMemorySave] Invalid memory_type: {$type}");
        return;
    }

    // Truncate key and value to column limits
    $key   = mb_substr(trim($key), 0, 100);
    $value = trim($value);

    if ($key === '' || $value === '') {
        return;
    }

    $stmt = $db->prepare("
        INSERT INTO om_ai_call_memory
            (customer_phone, customer_id, memory_type, memory_key, memory_value, confidence, times_confirmed, last_used_at, created_at, updated_at)
        VALUES
            (?, ?, ?, ?, ?, 1.0, 1, NOW(), NOW(), NOW())
        ON CONFLICT (customer_phone, memory_type, memory_key)
        DO UPDATE SET
            memory_value    = EXCLUDED.memory_value,
            customer_id     = COALESCE(EXCLUDED.customer_id, om_ai_call_memory.customer_id),
            confidence      = LEAST(om_ai_call_memory.confidence + 0.1, 1.0),
            times_confirmed = om_ai_call_memory.times_confirmed + 1,
            last_used_at    = NOW(),
            updated_at      = NOW()
    ");
    $stmt->execute([$phone, $customerId, $type, $key, $value]);
}

/**
 * Build a text block for Claude's system prompt with all relevant memories.
 * Returns empty string if no memories exist.
 */
function aiMemoryBuildContext(PDO $db, string $phone, ?int $customerId = null): string
{
    $memories = aiMemoryLoad($db, $phone, $customerId);

    // Check if there's anything to report
    $totalEntries = 0;
    foreach ($memories as $entries) {
        $totalEntries += count($entries);
    }
    if ($totalEntries === 0) {
        return '';
    }

    $lines = [];
    $lines[] = '=== MEMORIA DO CLIENTE (telefone: ' . aiMemoryNormalizePhone($phone) . ') ===';

    // Preferences
    if (!empty($memories['preference'])) {
        $lines[] = '';
        $lines[] = 'PREFERENCIAS:';
        foreach ($memories['preference'] as $key => $mem) {
            $conf = $mem['times'] > 3 ? ' [confirmado ' . $mem['times'] . 'x]' : '';
            $lines[] = '- ' . aiMemoryKeyLabel($key) . ': ' . $mem['value'] . $conf;
        }
    }

    // Order history summary
    if (!empty($memories['order_history'])) {
        $lines[] = '';
        $lines[] = 'HISTORICO DE PEDIDOS:';
        foreach ($memories['order_history'] as $key => $mem) {
            $lines[] = '- ' . aiMemoryKeyLabel($key) . ': ' . $mem['value'];
        }
    }

    // Complaints
    if (!empty($memories['complaint'])) {
        $lines[] = '';
        $lines[] = 'RECLAMACOES ANTERIORES (tratar com cuidado):';
        foreach ($memories['complaint'] as $key => $mem) {
            $lines[] = '- ' . $mem['value'] . ' (' . $mem['last_used'] . ')';
        }
    }

    // Notes
    if (!empty($memories['note'])) {
        $lines[] = '';
        $lines[] = 'OBSERVACOES:';
        foreach ($memories['note'] as $key => $mem) {
            $lines[] = '- ' . $mem['value'];
        }
    }

    // Patterns
    if (!empty($memories['pattern'])) {
        $lines[] = '';
        $lines[] = 'PADROES DE COMPORTAMENTO:';
        foreach ($memories['pattern'] as $key => $mem) {
            $lines[] = '- ' . aiMemoryKeyLabel($key) . ': ' . $mem['value'];
        }
    }

    $lines[] = '=== FIM DA MEMORIA ===';

    return implode("\n", $lines);
}

/**
 * Translate memory_key into a human-readable label for the prompt.
 */
function aiMemoryKeyLabel(string $key): string
{
    $labels = [
        'favorite_store'     => 'Loja favorita',
        'favorite_store_2'   => 'Segunda loja favorita',
        'favorite_store_3'   => 'Terceira loja favorita',
        'usual_order'        => 'Pedido habitual',
        'usual_items'        => 'Itens que costuma pedir',
        'payment_method'     => 'Forma de pagamento preferida',
        'delivery_address'   => 'Endereco de entrega',
        'average_ticket'     => 'Ticket medio',
        'total_orders'       => 'Total de pedidos',
        'last_order_date'    => 'Ultimo pedido',
        'last_order_store'   => 'Loja do ultimo pedido',
        'last_order_items'   => 'Itens do ultimo pedido',
        'last_complaint'     => 'Ultima reclamacao',
        'usual_call_time'    => 'Horario habitual de ligacao',
        'call_frequency'     => 'Frequencia de ligacoes',
        'total_calls'        => 'Total de ligacoes',
        'preferred_language' => 'Idioma preferido',
        'name'               => 'Nome do cliente',
        'dietary_restriction' => 'Restricao alimentar',
    ];

    return $labels[$key] ?? ucfirst(str_replace('_', ' ', $key));
}

/**
 * Learn from a completed order. Extracts and persists preferences.
 *
 * @param array $orderData Expected keys:
 *   - order_id (int)
 *   - partner_id (int)
 *   - partner_name (string)
 *   - forma_pagamento (string)
 *   - total (float)
 *   - delivery_address (string, optional)
 *   - items (array of ['product_name' => string, 'quantity' => int], optional)
 */
function aiMemoryLearn(PDO $db, string $phone, ?int $customerId, array $orderData): void
{
    $phone = aiMemoryNormalizePhone($phone);

    if (empty($phone)) {
        return;
    }

    try {
        // 1. Favorite store — track the store they just ordered from
        $storeName = $orderData['partner_name'] ?? '';
        $storeId   = $orderData['partner_id'] ?? 0;
        if ($storeName && $storeId) {
            // Count orders from this store to determine ranking
            $countStmt = $db->prepare("
                SELECT COUNT(*) FROM om_market_orders
                WHERE customer_id = ? AND partner_id = ? AND status NOT IN ('cancelled', 'refunded')
            ");
            $countStmt->execute([$customerId ?? 0, $storeId]);
            $storeOrderCount = (int)$countStmt->fetchColumn();

            // Determine which favorite slot to use
            $existingStores = [];
            $memStmt = $db->prepare("
                SELECT memory_key, memory_value FROM om_ai_call_memory
                WHERE customer_phone = ? AND memory_type = 'preference'
                AND memory_key IN ('favorite_store', 'favorite_store_2', 'favorite_store_3')
                ORDER BY times_confirmed DESC
            ");
            $memStmt->execute([$phone]);
            while ($row = $memStmt->fetch(PDO::FETCH_ASSOC)) {
                $existingStores[$row['memory_key']] = $row['memory_value'];
            }

            // Check if this store is already saved
            $storeEntry = $storeName . ' (ID:' . $storeId . ')';
            $alreadySaved = false;
            foreach ($existingStores as $k => $v) {
                if (str_contains($v, 'ID:' . $storeId)) {
                    // Already tracked — just confirm it (upsert will increment times_confirmed)
                    aiMemorySave($db, $phone, $customerId, 'preference', $k, $storeEntry);
                    $alreadySaved = true;
                    break;
                }
            }

            if (!$alreadySaved) {
                // Find the first free slot
                if (!isset($existingStores['favorite_store'])) {
                    aiMemorySave($db, $phone, $customerId, 'preference', 'favorite_store', $storeEntry);
                } elseif (!isset($existingStores['favorite_store_2'])) {
                    aiMemorySave($db, $phone, $customerId, 'preference', 'favorite_store_2', $storeEntry);
                } elseif (!isset($existingStores['favorite_store_3'])) {
                    aiMemorySave($db, $phone, $customerId, 'preference', 'favorite_store_3', $storeEntry);
                }
                // If all 3 slots full, the upsert on favorite_store_3 will naturally rotate
            }
        }

        // 2. Payment method
        $paymentMethod = $orderData['forma_pagamento'] ?? $orderData['payment_method'] ?? '';
        if ($paymentMethod) {
            $paymentLabels = [
                'pix'             => 'PIX',
                'cartao_credito'  => 'Cartao de credito',
                'cartao_debito'   => 'Cartao de debito',
                'dinheiro'        => 'Dinheiro',
                'vale_refeicao'   => 'Vale refeicao',
            ];
            $label = $paymentLabels[$paymentMethod] ?? $paymentMethod;
            aiMemorySave($db, $phone, $customerId, 'preference', 'payment_method', $label);
        }

        // 3. Delivery address
        $address = $orderData['delivery_address'] ?? '';
        if ($address && strlen($address) > 5) {
            aiMemorySave($db, $phone, $customerId, 'preference', 'delivery_address', $address);
        }

        // 4. Order items — store last order items and build usual_items
        $items = $orderData['items'] ?? [];
        if (!empty($items)) {
            $itemSummary = implode(', ', array_map(function ($item) {
                $qty  = $item['quantity'] ?? 1;
                $name = $item['product_name'] ?? $item['name'] ?? '';
                return $qty . 'x ' . $name;
            }, array_slice($items, 0, 8))); // Cap at 8 items for brevity

            aiMemorySave($db, $phone, $customerId, 'order_history', 'last_order_items', $itemSummary);
            aiMemorySave($db, $phone, $customerId, 'order_history', 'last_order_store', $storeName);
            aiMemorySave($db, $phone, $customerId, 'order_history', 'last_order_date', date('Y-m-d H:i'));
        }

        // 5. Average ticket and total orders
        if ($customerId) {
            $statsStmt = $db->prepare("
                SELECT COUNT(*) as total_orders, AVG(total) as avg_ticket
                FROM om_market_orders
                WHERE customer_id = ? AND status NOT IN ('cancelled', 'refunded')
            ");
            $statsStmt->execute([$customerId]);
            $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

            if ($stats && $stats['total_orders'] > 0) {
                aiMemorySave($db, $phone, $customerId, 'order_history', 'total_orders', (string)$stats['total_orders']);
                aiMemorySave($db, $phone, $customerId, 'order_history', 'average_ticket',
                    'R$ ' . number_format((float)$stats['avg_ticket'], 2, ',', '.'));
            }
        }

        // 6. Call pattern — time of day
        $hour = (int)date('H');
        if ($hour >= 6 && $hour < 12) {
            $period = 'manha (6h-12h)';
        } elseif ($hour >= 12 && $hour < 18) {
            $period = 'tarde (12h-18h)';
        } elseif ($hour >= 18 && $hour < 23) {
            $period = 'noite (18h-23h)';
        } else {
            $period = 'madrugada (23h-6h)';
        }
        aiMemorySave($db, $phone, $customerId, 'pattern', 'usual_call_time', $period);

        // 7. Day of week pattern
        $dayOfWeek = date('l'); // Monday, Tuesday, etc.
        $dayLabels = [
            'Monday'    => 'segunda-feira',
            'Tuesday'   => 'terca-feira',
            'Wednesday' => 'quarta-feira',
            'Thursday'  => 'quinta-feira',
            'Friday'    => 'sexta-feira',
            'Saturday'  => 'sabado',
            'Sunday'    => 'domingo',
        ];
        $dayLabel = $dayLabels[$dayOfWeek] ?? $dayOfWeek;
        aiMemorySave($db, $phone, $customerId, 'pattern', 'usual_day', 'Costuma pedir na ' . $dayLabel);

        // 8. Customer name (from DB if available)
        if ($customerId) {
            $nameStmt = $db->prepare("SELECT name FROM om_market_customers WHERE customer_id = ? LIMIT 1");
            $nameStmt->execute([$customerId]);
            $name = $nameStmt->fetchColumn();
            if ($name) {
                aiMemorySave($db, $phone, $customerId, 'preference', 'name', $name);
            }
        }

    } catch (\Exception $e) {
        // Learning should never break the main flow
        error_log("[aiMemoryLearn] Error: " . $e->getMessage());
    }
}

/**
 * Generate a personalized greeting hint based on stored memories.
 * Returns a short text that can be injected into Claude's system prompt
 * to help it greet the customer personally.
 *
 * Returns null if there are no useful memories.
 */
function aiMemoryGetGreeting(PDO $db, string $phone, ?int $customerId = null): ?string
{
    $memories = aiMemoryLoad($db, $phone, $customerId);

    $totalEntries = 0;
    foreach ($memories as $entries) {
        $totalEntries += count($entries);
    }
    if ($totalEntries === 0) {
        return null;
    }

    $hints = [];

    // Customer name
    $name = $memories['preference']['name']['value'] ?? null;
    if ($name) {
        $hints[] = 'O cliente se chama ' . $name . '. Use o nome dele na saudacao.';
    }

    // Returning customer indicator
    $totalOrders = $memories['order_history']['total_orders']['value'] ?? '0';
    if ((int)$totalOrders > 0) {
        $hints[] = 'Cliente recorrente com ' . $totalOrders . ' pedido(s) anterior(es).';
    }

    // Days since last order — detect win-back opportunity
    $lastOrderDate = $memories['order_history']['last_order_date']['value'] ?? null;
    if ($lastOrderDate) {
        try {
            $last = new \DateTime($lastOrderDate);
            $now  = new \DateTime();
            $days = (int)$now->diff($last)->days;

            if ($days > 30) {
                $hints[] = 'Faz ' . $days . ' dias desde o ultimo pedido — oportunidade de reconquistar.';
            } elseif ($days > 14) {
                $hints[] = 'Nao pede ha ' . $days . ' dias.';
            } elseif ($days <= 1) {
                $hints[] = 'Pediu ontem ou hoje — cliente muito ativo.';
            }
        } catch (\Exception $e) {
            // Ignore date parse errors
        }
    }

    // Favorite store
    $favStore = $memories['preference']['favorite_store']['value'] ?? null;
    if ($favStore) {
        // Clean the ID suffix for the greeting
        $cleanStore = preg_replace('/\s*\(ID:\d+\)/', '', $favStore);
        $hints[] = 'Loja favorita: ' . $cleanStore . '.';
    }

    // Last order items — suggest reorder
    $lastItems = $memories['order_history']['last_order_items']['value'] ?? null;
    if ($lastItems) {
        $hints[] = 'Ultimo pedido: ' . $lastItems . '. Pode sugerir pedir novamente.';
    }

    // Payment preference
    $payPref = $memories['preference']['payment_method']['value'] ?? null;
    if ($payPref) {
        $hints[] = 'Prefere pagar com ' . $payPref . '.';
    }

    // Recent complaint — be careful
    if (!empty($memories['complaint'])) {
        $lastComplaint = reset($memories['complaint']);
        $hints[] = 'ATENCAO: Teve uma reclamacao recente: "' . mb_substr($lastComplaint['value'], 0, 100) . '". Seja empatitico.';
    }

    // Day/time pattern
    $usualDay = $memories['pattern']['usual_day']['value'] ?? null;
    $usualTime = $memories['pattern']['usual_call_time']['value'] ?? null;
    if ($usualDay && $usualTime) {
        $hints[] = 'Padrao: ' . $usualDay . ', periodo da ' . $usualTime . '.';
    }

    // Dietary restrictions
    $dietary = $memories['preference']['dietary_restriction']['value'] ?? null;
    if ($dietary) {
        $hints[] = 'Restricao alimentar: ' . $dietary . '. Cuidado ao sugerir itens.';
    }

    if (empty($hints)) {
        return null;
    }

    return implode("\n", $hints);
}

/**
 * Record a call event for pattern tracking.
 * Call this at the start of every inbound call.
 */
function aiMemoryTrackCall(PDO $db, string $phone, ?int $customerId = null): void
{
    $phone = aiMemoryNormalizePhone($phone);

    if (empty($phone)) {
        return;
    }

    try {
        // Track call count
        $countStmt = $db->prepare("
            SELECT memory_value FROM om_ai_call_memory
            WHERE customer_phone = ? AND memory_type = 'pattern' AND memory_key = 'total_calls'
        ");
        $countStmt->execute([$phone]);
        $current = (int)($countStmt->fetchColumn() ?: 0);
        aiMemorySave($db, $phone, $customerId, 'pattern', 'total_calls', (string)($current + 1));

        // Track time-of-day pattern
        $hour = (int)date('H');
        if ($hour >= 6 && $hour < 12) {
            $period = 'manha (6h-12h)';
        } elseif ($hour >= 12 && $hour < 18) {
            $period = 'tarde (12h-18h)';
        } elseif ($hour >= 18 && $hour < 23) {
            $period = 'noite (18h-23h)';
        } else {
            $period = 'madrugada (23h-6h)';
        }
        aiMemorySave($db, $phone, $customerId, 'pattern', 'usual_call_time', $period);

        // Track call frequency (calls per week)
        $freqStmt = $db->prepare("
            SELECT COUNT(*) FROM om_ai_call_memory
            WHERE customer_phone = ? AND memory_type = 'pattern' AND memory_key = 'total_calls'
            AND updated_at >= NOW() - INTERVAL '7 days'
        ");
        $freqStmt->execute([$phone]);
        $weekCalls = (int)$freqStmt->fetchColumn();

        if ($weekCalls > 5) {
            $freq = 'muito frequente (5+ por semana)';
        } elseif ($weekCalls > 2) {
            $freq = 'frequente (3-5 por semana)';
        } elseif ($weekCalls > 0) {
            $freq = 'regular (1-2 por semana)';
        } else {
            $freq = 'ocasional';
        }
        aiMemorySave($db, $phone, $customerId, 'pattern', 'call_frequency', $freq);

    } catch (\Exception $e) {
        error_log("[aiMemoryTrackCall] Error: " . $e->getMessage());
    }
}

/**
 * Record a complaint for future reference.
 */
function aiMemoryRecordComplaint(
    PDO    $db,
    string $phone,
    ?int   $customerId,
    string $summary,
    ?int   $orderId = null
): void {
    $key = $orderId ? 'order_' . $orderId : 'complaint_' . date('Ymd_His');
    $value = $summary;
    if ($orderId) {
        $value = "Pedido #{$orderId}: {$summary}";
    }

    aiMemorySave($db, $phone, $customerId, 'complaint', $key, $value);

    // Also update the "last_complaint" shortcut
    aiMemorySave($db, $phone, $customerId, 'complaint', 'last_complaint', $value);
}

/**
 * Decay old memories that haven't been used in a long time.
 * Call this periodically (e.g., weekly cron).
 * Reduces confidence of unused memories and deletes very old low-confidence ones.
 */
function aiMemoryDecay(PDO $db, int $staleMonths = 6, int $deleteMonths = 12): int
{
    $deleted = 0;

    try {
        // Reduce confidence of memories not used in $staleMonths
        $db->prepare("
            UPDATE om_ai_call_memory
            SET confidence = GREATEST(confidence - 0.2, 0.1),
                updated_at = NOW()
            WHERE last_used_at < NOW() - INTERVAL '{$staleMonths} months'
            AND confidence > 0.1
        ")->execute();

        // Delete very old, low-confidence memories (except complaints — keep those)
        $stmt = $db->prepare("
            DELETE FROM om_ai_call_memory
            WHERE last_used_at < NOW() - INTERVAL '{$deleteMonths} months'
            AND confidence <= 0.3
            AND memory_type NOT IN ('complaint')
        ");
        $stmt->execute();
        $deleted = $stmt->rowCount();

    } catch (\Exception $e) {
        error_log("[aiMemoryDecay] Error: " . $e->getMessage());
    }

    return $deleted;
}
