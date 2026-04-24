-- 100_listing_perf.sql — listing/perf indexes (24/Abr/2026)
--
-- Apenas indices que faltavam apos auditoria de pg_indexes em SP.
-- Indices ja existentes (NAO duplicados aqui):
--   om_market_partners(city, status)            => idx_partners_location
--   om_market_partners(status)                  => idx_partners_status
--   om_market_products(partner_id, status)      => idx_products_partner_status
--   om_market_orders(partner_id, status, ...)   => idx_orders_partner_status
--   om_market_orders(customer_id, ...)          => idx_orders_customer_date
--   om_market_order_items(order_id)             => idx_order_items_order
--
-- IF NOT EXISTS evita erro se a migration rodar 2x.
-- CONCURRENTLY nao funciona em PgBouncer transaction-pooled, entao vai sem.

-- 1) home.php verificarSeAberto: WHERE partner_id = ? AND day_of_week = ?
CREATE INDEX IF NOT EXISTS idx_partner_hours_partner_day
    ON om_partner_hours(partner_id, day_of_week);

-- 2) home.php buscarProximoHorarioAberto + verificarSeAberto:
--    WHERE partner_id = ? AND date = ?
CREATE INDEX IF NOT EXISTS idx_partner_holidays_partner_date
    ON om_partner_holidays(partner_id, date);

-- 3) home.php buscarMercadoMaisProximo cobertura especifica:
--    WHERE BETWEEN cep_inicio AND cep_fim (CAST AS BIGINT)
--    Indice por (partner_id) ja existe; um composite com CEP melhora o filtro
--    secundario, mas o BETWEEN com CAST nao usa btree convencional. Skip.

-- 4) listar.php topSellers + customer/recommendations:
--    WHERE oi.product_id = ? GROUP BY oi.product_id
--    Hoje so existe idx por order_id; product_id em order_items e usado em
--    aggregations frequentes.
CREATE INDEX IF NOT EXISTS idx_order_items_product
    ON om_market_order_items(product_id);

-- 5) home.php buscarBanners:
--    WHERE status = 1 AND (end_date IS NULL OR end_date > NOW())
--    Indice parcial por status ativo + sort_order acelera o ORDER BY LIMIT 5.
CREATE INDEX IF NOT EXISTS idx_market_banners_status_sort
    ON om_market_banners(status, sort_order, created_at DESC)
    WHERE status::text = '1';

-- 6) listar.php categoria nome lookup
--    LEFT JOIN om_market_categories c ON p.category_id = c.category_id
--    pkey ja cobre (category_id), nada a fazer.

-- 7) parceiros/detalhes.php categorias do parceiro:
--    DISTINCT c.* FROM om_market_categories c
--    INNER JOIN om_market_products p ON p.category_id = c.category_id
--    WHERE p.partner_id = ? AND p.status::text = '1'
--    Indice (partner_id, status) ja existe (idx_products_partner_status), mas
--    um composite incluindo category_id ajuda no DISTINCT join. Index-only scan
--    melhora a query.
CREATE INDEX IF NOT EXISTS idx_products_partner_status_category
    ON om_market_products(partner_id, category_id)
    WHERE status::text = '1';

-- Verificar:
--   EXPLAIN (ANALYZE, BUFFERS) SELECT ... FROM om_partner_hours WHERE partner_id = 106 AND day_of_week = 3;
--   EXPLAIN (ANALYZE, BUFFERS) SELECT product_id, SUM(quantity) FROM om_market_order_items WHERE product_id IN (1,2,3) GROUP BY product_id;

ANALYZE om_partner_hours;
ANALYZE om_partner_holidays;
ANALYZE om_market_order_items;
ANALYZE om_market_banners;
ANALYZE om_market_products;
