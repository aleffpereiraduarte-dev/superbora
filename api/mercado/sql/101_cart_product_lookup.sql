-- 101_cart_product_lookup.sql — Cart product lookup index (24/Abr/2026)
--
-- Motivo:
--   `om_market_products` usa um padrao "dual-id": PK e na coluna `id`
--   (autoincrement) mas o carrinho (e todo o app novo) referencia via `product_id`
--   (int, nullable, sem indice). EXPLAIN em produção:
--
--     EXPLAIN ANALYZE SELECT ... FROM om_market_products WHERE product_id = 12345;
--     -> Seq Scan on om_market_products  Buffers: shared hit=41
--        Execution Time: 0.388 ms  (1002 rows)
--
--   Com 1k produtos ja custa 0.4ms por chamada. Crescendo pra 50k+ (projeção
--   para 2026-Q4 com parceiros novos de farmácia/pet/cesta) vira ~20ms de Seq
--   Scan em cada adicionar.php, cada listar.php fallback, cada produto/[id].php.
--
--   `adicionar.php` chama: SELECT product_id, name, price, image, quantity AS
--   stock FROM om_market_products WHERE product_id = ? (com ou sem partner_id).
--   Quando SEM partner_id, nao tem nenhum indice utilizavel -> Seq Scan garantido.
--
-- Safe to re-run (IF NOT EXISTS). CONCURRENTLY não funciona em PgBouncer
-- transaction-pool, por isso vai sem (o indice e pequeno, bloqueia <1s).

CREATE INDEX IF NOT EXISTS idx_products_product_id
    ON om_market_products(product_id)
    WHERE product_id IS NOT NULL;

-- Rodar ANALYZE para que o planner atualize estatisticas apos criar o indice.
ANALYZE om_market_products;
