-- ══════════════════════════════════════════════════════════════════════════════
-- Migration 010: Lojas Demo + Produtos - Popular vitrine com dados realistas
-- Executar apos 009_features_extras.sql
-- MySQL 8.4 compativel
-- ══════════════════════════════════════════════════════════════════════════════

-- ═══════════════════════════════════════════
-- LOJAS DEMO - Governador Valadares / MG
-- ═══════════════════════════════════════════

-- 1. Burger King GV (restaurante)
INSERT INTO om_market_partners (name, email, login_password, phone, description, address, city, state, cep, categoria, is_open, rating, delivery_fee, delivery_time_min, min_order, open_time, close_time, lat, lng, free_delivery_above, status)
VALUES ('Burger King GV', 'bk@demo.com', '', '(33) 3271-1001', 'O sabor da brasa que voce ama. Whopper, BK Chicken e muito mais.', 'Av. Brasil, 1200 - Centro', 'Governador Valadares', 'MG', '35010-000', 'restaurante', 1, 4.50, 6.99, 35, 20.00, '10:00:00', '23:00:00', -18.85100000, -41.94940000, 50.00, 1);
SET @bk_id = LAST_INSERT_ID();

-- 2. Pizzaria Napoli (restaurante)
INSERT INTO om_market_partners (name, email, login_password, phone, description, address, city, state, cep, categoria, is_open, rating, delivery_fee, delivery_time_min, min_order, open_time, close_time, lat, lng, free_delivery_above, status)
VALUES ('Pizzaria Napoli', 'napoli@demo.com', '', '(33) 3271-1002', 'Pizza artesanal no forno a lenha. Massa fininha e ingredientes selecionados.', 'Rua Marechal Floriano, 450 - Centro', 'Governador Valadares', 'MG', '35010-020', 'restaurante', 1, 4.80, 5.99, 45, 25.00, '17:00:00', '23:30:00', -18.84950000, -41.95100000, 60.00, 1);
SET @napoli_id = LAST_INSERT_ID();

-- 3. Sushi Yama (restaurante)
INSERT INTO om_market_partners (name, email, login_password, phone, description, address, city, state, cep, categoria, is_open, rating, delivery_fee, delivery_time_min, min_order, open_time, close_time, lat, lng, free_delivery_above, status)
VALUES ('Sushi Yama', 'sushi@demo.com', '', '(33) 3271-1003', 'Culinaria japonesa autentica. Sashimi fresco, sushi e temaki.', 'Rua Sao Paulo, 320 - Vila Rica', 'Governador Valadares', 'MG', '35010-050', 'restaurante', 1, 4.70, 8.99, 50, 35.00, '11:00:00', '22:30:00', -18.85200000, -41.94800000, 80.00, 1);
SET @sushi_id = LAST_INSERT_ID();

-- 4. Acai da Terra (restaurante)
INSERT INTO om_market_partners (name, email, login_password, phone, description, address, city, state, cep, categoria, is_open, rating, delivery_fee, delivery_time_min, min_order, open_time, close_time, lat, lng, free_delivery_above, status)
VALUES ('Acai da Terra', 'acai@demo.com', '', '(33) 3271-1004', 'Acai puro da Amazonia com frutas frescas. Bowls e cremes artesanais.', 'Av. Minas Gerais, 780 - Centro', 'Governador Valadares', 'MG', '35010-030', 'restaurante', 1, 4.60, 4.99, 25, 15.00, '09:00:00', '21:00:00', -18.85000000, -41.95050000, 40.00, 1);
SET @acai_id = LAST_INSERT_ID();

-- 5. Padaria Pao Quente (padaria -> categoria 'loja')
INSERT INTO om_market_partners (name, email, login_password, phone, description, address, city, state, cep, categoria, is_open, rating, delivery_fee, delivery_time_min, min_order, open_time, close_time, lat, lng, free_delivery_above, status)
VALUES ('Padaria Pao Quente', 'paoquente@demo.com', '', '(33) 3271-1005', 'Paes fresquinhos toda hora. Salgados, bolos e cafe especial.', 'Rua Tiradentes, 200 - Centro', 'Governador Valadares', 'MG', '35010-060', 'loja', 1, 4.40, 3.99, 20, 10.00, '06:00:00', '20:00:00', -18.85300000, -41.95200000, 30.00, 1);
SET @padaria_id = LAST_INSERT_ID();

-- 6. Confeitaria Doce Mel (padaria -> categoria 'loja')
INSERT INTO om_market_partners (name, email, login_password, phone, description, address, city, state, cep, categoria, is_open, rating, delivery_fee, delivery_time_min, min_order, open_time, close_time, lat, lng, free_delivery_above, status)
VALUES ('Confeitaria Doce Mel', 'docemel@demo.com', '', '(33) 3271-1006', 'Bolos decorados, doces finos e sobremesas artesanais para toda ocasiao.', 'Rua Gontijo Vasconcelos, 150 - Vila Rica', 'Governador Valadares', 'MG', '35010-070', 'loja', 1, 4.90, 5.99, 30, 20.00, '08:00:00', '19:00:00', -18.85400000, -41.94700000, 50.00, 1);
SET @confeitaria_id = LAST_INSERT_ID();

-- 7. Drogaria Saude (farmacia)
INSERT INTO om_market_partners (name, email, login_password, phone, description, address, city, state, cep, categoria, is_open, rating, delivery_fee, delivery_time_min, min_order, open_time, close_time, lat, lng, free_delivery_above, status)
VALUES ('Drogaria Saude', 'drogaria@demo.com', '', '(33) 3271-1007', 'Medicamentos, higiene pessoal, vitaminas e mais. Entrega rapida.', 'Av. Brasil, 800 - Centro', 'Governador Valadares', 'MG', '35010-000', 'farmacia', 1, 4.30, 4.99, 25, 15.00, '07:00:00', '22:00:00', -18.85150000, -41.95000000, 60.00, 1);
SET @drogaria_id = LAST_INSERT_ID();

-- 8. Farma Bem (farmacia)
INSERT INTO om_market_partners (name, email, login_password, phone, description, address, city, state, cep, categoria, is_open, rating, delivery_fee, delivery_time_min, min_order, open_time, close_time, lat, lng, free_delivery_above, status)
VALUES ('Farma Bem', 'farmabem@demo.com', '', '(33) 3271-1008', 'Sua farmacia de confianca. Perfumaria, dermocosmeticos e manipulados.', 'Rua Prudente de Morais, 310 - Centro', 'Governador Valadares', 'MG', '35010-040', 'farmacia', 1, 4.20, 3.99, 20, 10.00, '07:30:00', '21:30:00', -18.85050000, -41.95150000, 50.00, 1);
SET @farmabem_id = LAST_INSERT_ID();

-- 9. Pet Amigo (petshop -> categoria 'loja')
INSERT INTO om_market_partners (name, email, login_password, phone, description, address, city, state, cep, categoria, is_open, rating, delivery_fee, delivery_time_min, min_order, open_time, close_time, lat, lng, free_delivery_above, status)
VALUES ('Pet Amigo', 'petamigo@demo.com', '', '(33) 3271-1009', 'Tudo para seu pet! Racao, acessorios, higiene e brinquedos.', 'Rua Gontijo Vasconcelos, 500 - Vila Rica', 'Governador Valadares', 'MG', '35010-070', 'loja', 1, 4.50, 5.99, 30, 25.00, '08:30:00', '19:00:00', -18.85450000, -41.94750000, 70.00, 1);
SET @pet_id = LAST_INSERT_ID();

-- 10. Conveniencia 24h (conveniencia -> categoria 'loja')
INSERT INTO om_market_partners (name, email, login_password, phone, description, address, city, state, cep, categoria, is_open, rating, delivery_fee, delivery_time_min, min_order, open_time, close_time, lat, lng, free_delivery_above, status)
VALUES ('Conveniencia 24h', 'conveniencia@demo.com', '', '(33) 3271-1010', 'Aberto 24 horas! Bebidas, snacks, sorvetes e itens de emergencia.', 'Av. Brasil, 1500 - Centro', 'Governador Valadares', 'MG', '35010-000', 'loja', 1, 4.00, 3.99, 15, 10.00, '00:00:00', '23:59:00', -18.85080000, -41.94900000, 40.00, 1);
SET @conv_id = LAST_INSERT_ID();

-- 11. Acougue Boi Nobre (acougue -> categoria 'mercado')
INSERT INTO om_market_partners (name, email, login_password, phone, description, address, city, state, cep, categoria, is_open, rating, delivery_fee, delivery_time_min, min_order, open_time, close_time, lat, lng, free_delivery_above, status)
VALUES ('Acougue Boi Nobre', 'boinobre@demo.com', '', '(33) 3271-1011', 'Carnes selecionadas e cortes especiais. Churrasqueiro de confianca.', 'Rua Israel Pinheiro, 250 - Centro', 'Governador Valadares', 'MG', '35010-080', 'mercado', 1, 4.60, 5.99, 25, 30.00, '07:00:00', '18:00:00', -18.85250000, -41.95250000, 80.00, 1);
SET @acougue_id = LAST_INSERT_ID();

-- 12. Utilidades Casa (loja)
INSERT INTO om_market_partners (name, email, login_password, phone, description, address, city, state, cep, categoria, is_open, rating, delivery_fee, delivery_time_min, min_order, open_time, close_time, lat, lng, free_delivery_above, status)
VALUES ('Utilidades Casa', 'utilidades@demo.com', '', '(33) 3271-1012', 'Produtos para casa, cozinha, limpeza e organizacao. Precos imbativeis.', 'Rua Marechal Floriano, 700 - Centro', 'Governador Valadares', 'MG', '35010-020', 'loja', 1, 4.10, 6.99, 35, 20.00, '08:00:00', '18:00:00', -18.84980000, -41.95300000, 60.00, 1);
SET @utilidades_id = LAST_INSERT_ID();

-- 13. Espetinho do Joao (restaurante)
INSERT INTO om_market_partners (name, email, login_password, phone, description, address, city, state, cep, categoria, is_open, rating, delivery_fee, delivery_time_min, min_order, open_time, close_time, lat, lng, free_delivery_above, status)
VALUES ('Espetinho do Joao', 'espetinho@demo.com', '', '(33) 3271-1013', 'Espetinhos na brasa, porcoes e petiscos. O melhor happy hour da cidade!', 'Rua Sao Paulo, 600 - Vila Rica', 'Governador Valadares', 'MG', '35010-050', 'restaurante', 1, 4.30, 4.99, 30, 15.00, '16:00:00', '00:00:00', -18.85350000, -41.94850000, 45.00, 1);
SET @espetinho_id = LAST_INSERT_ID();

-- ═══════════════════════════════════════════
-- CATEGORIAS DE PRODUTOS POR LOJA
-- ═══════════════════════════════════════════

-- Burger King
INSERT INTO om_categories (partner_id, name, sort_order, status) VALUES (@bk_id, 'Combos', 1, 1);
SET @bk_c1 = LAST_INSERT_ID();
INSERT INTO om_categories (partner_id, name, sort_order, status) VALUES (@bk_id, 'Sanduiches', 2, 1);
SET @bk_c2 = LAST_INSERT_ID();
INSERT INTO om_categories (partner_id, name, sort_order, status) VALUES (@bk_id, 'Acompanhamentos', 3, 1);
SET @bk_c3 = LAST_INSERT_ID();
INSERT INTO om_categories (partner_id, name, sort_order, status) VALUES (@bk_id, 'Bebidas', 4, 1);
SET @bk_c4 = LAST_INSERT_ID();
INSERT INTO om_categories (partner_id, name, sort_order, status) VALUES (@bk_id, 'Sobremesas', 5, 1);
SET @bk_c5 = LAST_INSERT_ID();

-- Pizzaria Napoli
INSERT INTO om_categories (partner_id, name, sort_order, status) VALUES (@napoli_id, 'Pizzas Tradicionais', 1, 1);
SET @nap_c1 = LAST_INSERT_ID();
INSERT INTO om_categories (partner_id, name, sort_order, status) VALUES (@napoli_id, 'Pizzas Especiais', 2, 1);
SET @nap_c2 = LAST_INSERT_ID();
INSERT INTO om_categories (partner_id, name, sort_order, status) VALUES (@napoli_id, 'Bebidas', 3, 1);
SET @nap_c3 = LAST_INSERT_ID();

-- Sushi Yama
INSERT INTO om_categories (partner_id, name, sort_order, status) VALUES (@sushi_id, 'Sashimi', 1, 1);
SET @su_c1 = LAST_INSERT_ID();
INSERT INTO om_categories (partner_id, name, sort_order, status) VALUES (@sushi_id, 'Sushi', 2, 1);
SET @su_c2 = LAST_INSERT_ID();
INSERT INTO om_categories (partner_id, name, sort_order, status) VALUES (@sushi_id, 'Temaki', 3, 1);
SET @su_c3 = LAST_INSERT_ID();
INSERT INTO om_categories (partner_id, name, sort_order, status) VALUES (@sushi_id, 'Combos', 4, 1);
SET @su_c4 = LAST_INSERT_ID();

-- Acai da Terra
INSERT INTO om_categories (partner_id, name, sort_order, status) VALUES (@acai_id, 'Acai no Copo', 1, 1);
SET @ac_c1 = LAST_INSERT_ID();
INSERT INTO om_categories (partner_id, name, sort_order, status) VALUES (@acai_id, 'Bowls', 2, 1);
SET @ac_c2 = LAST_INSERT_ID();
INSERT INTO om_categories (partner_id, name, sort_order, status) VALUES (@acai_id, 'Cremes e Sorvetes', 3, 1);
SET @ac_c3 = LAST_INSERT_ID();

-- Padaria Pao Quente
INSERT INTO om_categories (partner_id, name, sort_order, status) VALUES (@padaria_id, 'Paes', 1, 1);
SET @pad_c1 = LAST_INSERT_ID();
INSERT INTO om_categories (partner_id, name, sort_order, status) VALUES (@padaria_id, 'Salgados', 2, 1);
SET @pad_c2 = LAST_INSERT_ID();
INSERT INTO om_categories (partner_id, name, sort_order, status) VALUES (@padaria_id, 'Doces e Bolos', 3, 1);
SET @pad_c3 = LAST_INSERT_ID();
INSERT INTO om_categories (partner_id, name, sort_order, status) VALUES (@padaria_id, 'Bebidas', 4, 1);
SET @pad_c4 = LAST_INSERT_ID();

-- Confeitaria Doce Mel
INSERT INTO om_categories (partner_id, name, sort_order, status) VALUES (@confeitaria_id, 'Bolos', 1, 1);
SET @conf_c1 = LAST_INSERT_ID();
INSERT INTO om_categories (partner_id, name, sort_order, status) VALUES (@confeitaria_id, 'Doces Finos', 2, 1);
SET @conf_c2 = LAST_INSERT_ID();
INSERT INTO om_categories (partner_id, name, sort_order, status) VALUES (@confeitaria_id, 'Tortas', 3, 1);
SET @conf_c3 = LAST_INSERT_ID();

-- Drogaria Saude
INSERT INTO om_categories (partner_id, name, sort_order, status) VALUES (@drogaria_id, 'Medicamentos', 1, 1);
SET @drog_c1 = LAST_INSERT_ID();
INSERT INTO om_categories (partner_id, name, sort_order, status) VALUES (@drogaria_id, 'Higiene Pessoal', 2, 1);
SET @drog_c2 = LAST_INSERT_ID();
INSERT INTO om_categories (partner_id, name, sort_order, status) VALUES (@drogaria_id, 'Vitaminas', 3, 1);
SET @drog_c3 = LAST_INSERT_ID();

-- Farma Bem
INSERT INTO om_categories (partner_id, name, sort_order, status) VALUES (@farmabem_id, 'Medicamentos', 1, 1);
SET @fb_c1 = LAST_INSERT_ID();
INSERT INTO om_categories (partner_id, name, sort_order, status) VALUES (@farmabem_id, 'Perfumaria', 2, 1);
SET @fb_c2 = LAST_INSERT_ID();
INSERT INTO om_categories (partner_id, name, sort_order, status) VALUES (@farmabem_id, 'Dermocosmeticos', 3, 1);
SET @fb_c3 = LAST_INSERT_ID();

-- Pet Amigo
INSERT INTO om_categories (partner_id, name, sort_order, status) VALUES (@pet_id, 'Racao', 1, 1);
SET @pet_c1 = LAST_INSERT_ID();
INSERT INTO om_categories (partner_id, name, sort_order, status) VALUES (@pet_id, 'Acessorios', 2, 1);
SET @pet_c2 = LAST_INSERT_ID();
INSERT INTO om_categories (partner_id, name, sort_order, status) VALUES (@pet_id, 'Higiene Pet', 3, 1);
SET @pet_c3 = LAST_INSERT_ID();

-- Conveniencia 24h
INSERT INTO om_categories (partner_id, name, sort_order, status) VALUES (@conv_id, 'Bebidas', 1, 1);
SET @conv_c1 = LAST_INSERT_ID();
INSERT INTO om_categories (partner_id, name, sort_order, status) VALUES (@conv_id, 'Snacks', 2, 1);
SET @conv_c2 = LAST_INSERT_ID();
INSERT INTO om_categories (partner_id, name, sort_order, status) VALUES (@conv_id, 'Sorvetes', 3, 1);
SET @conv_c3 = LAST_INSERT_ID();

-- Acougue Boi Nobre
INSERT INTO om_categories (partner_id, name, sort_order, status) VALUES (@acougue_id, 'Bovinos', 1, 1);
SET @acg_c1 = LAST_INSERT_ID();
INSERT INTO om_categories (partner_id, name, sort_order, status) VALUES (@acougue_id, 'Suinos', 2, 1);
SET @acg_c2 = LAST_INSERT_ID();
INSERT INTO om_categories (partner_id, name, sort_order, status) VALUES (@acougue_id, 'Aves', 3, 1);
SET @acg_c3 = LAST_INSERT_ID();

-- Utilidades Casa
INSERT INTO om_categories (partner_id, name, sort_order, status) VALUES (@utilidades_id, 'Cozinha', 1, 1);
SET @util_c1 = LAST_INSERT_ID();
INSERT INTO om_categories (partner_id, name, sort_order, status) VALUES (@utilidades_id, 'Limpeza', 2, 1);
SET @util_c2 = LAST_INSERT_ID();
INSERT INTO om_categories (partner_id, name, sort_order, status) VALUES (@utilidades_id, 'Organizacao', 3, 1);
SET @util_c3 = LAST_INSERT_ID();

-- Espetinho do Joao
INSERT INTO om_categories (partner_id, name, sort_order, status) VALUES (@espetinho_id, 'Espetinhos', 1, 1);
SET @esp_c1 = LAST_INSERT_ID();
INSERT INTO om_categories (partner_id, name, sort_order, status) VALUES (@espetinho_id, 'Porcoes', 2, 1);
SET @esp_c2 = LAST_INSERT_ID();
INSERT INTO om_categories (partner_id, name, sort_order, status) VALUES (@espetinho_id, 'Bebidas', 3, 1);
SET @esp_c3 = LAST_INSERT_ID();


-- ═══════════════════════════════════════════
-- PRODUTOS - ~130 produtos demo
-- om_market_products: partner_id, category_id, name, description, price, special_price, quantity, unit, status
-- ═══════════════════════════════════════════

-- ── Burger King GV (13 produtos) ──
INSERT INTO om_market_products (partner_id, category_id, name, description, price, special_price, quantity, unit, status) VALUES
(@bk_id, @bk_c1, 'Combo Whopper', 'Whopper + Batata media + Refri 400ml', 35.90, 29.90, 100, 'un', 1),
(@bk_id, @bk_c1, 'Combo Chicken Crispy', 'Chicken Crispy + Batata media + Refri 400ml', 32.90, NULL, 100, 'un', 1),
(@bk_id, @bk_c1, 'Combo BK Stacker Duplo', 'BK Stacker Duplo + Batata + Refri', 38.90, 33.90, 100, 'un', 1),
(@bk_id, @bk_c2, 'Whopper', 'Pao, carne grelhada, alface, tomate, maionese, cebola, picles', 25.90, NULL, 100, 'un', 1),
(@bk_id, @bk_c2, 'Whopper Duplo', 'Duas carnes grelhadas com todos os ingredientes', 31.90, NULL, 100, 'un', 1),
(@bk_id, @bk_c2, 'Chicken Crispy', 'Frango empanado crocante com maionese especial', 22.90, NULL, 100, 'un', 1),
(@bk_id, @bk_c2, 'BK Stacker Duplo', 'Duas carnes, bacon, queijo cheddar e molho BK', 29.90, NULL, 100, 'un', 1),
(@bk_id, @bk_c3, 'Batata Frita Media', 'Batata frita sequinha e crocante', 12.90, NULL, 100, 'un', 1),
(@bk_id, @bk_c3, 'Onion Rings 8un', 'Aneis de cebola empanados', 14.90, 11.90, 100, 'un', 1),
(@bk_id, @bk_c4, 'Coca-Cola 400ml', 'Refrigerante Coca-Cola lata', 8.90, NULL, 100, 'un', 1),
(@bk_id, @bk_c4, 'Guarana Antarctica 400ml', 'Refrigerante Guarana lata', 7.90, NULL, 100, 'un', 1),
(@bk_id, @bk_c5, 'Sundae Chocolate', 'Sorvete com calda de chocolate', 9.90, NULL, 100, 'un', 1),
(@bk_id, @bk_c5, 'BK Mix Ovomaltine', 'Sorvete batido com Ovomaltine', 12.90, NULL, 100, 'un', 1);

-- ── Pizzaria Napoli (10 produtos) ──
INSERT INTO om_market_products (partner_id, category_id, name, description, price, special_price, quantity, unit, status) VALUES
(@napoli_id, @nap_c1, 'Pizza Margherita Grande', 'Molho de tomate, mussarela, manjericao fresco', 42.90, NULL, 50, 'un', 1),
(@napoli_id, @nap_c1, 'Pizza Calabresa Grande', 'Calabresa fatiada, cebola e azeitona', 44.90, NULL, 50, 'un', 1),
(@napoli_id, @nap_c1, 'Pizza Portuguesa Grande', 'Presunto, ovo, cebola, azeitona, ervilha', 46.90, NULL, 50, 'un', 1),
(@napoli_id, @nap_c1, 'Pizza 4 Queijos Grande', 'Mussarela, provolone, gorgonzola, parmesao', 48.90, NULL, 50, 'un', 1),
(@napoli_id, @nap_c2, 'Pizza Napolitana', 'Tomate San Marzano, mussarela de bufala, manjericao, azeite', 55.90, 49.90, 50, 'un', 1),
(@napoli_id, @nap_c2, 'Pizza Parma com Rucula', 'Presunto parma, rucula, tomate seco, parmesao', 58.90, NULL, 50, 'un', 1),
(@napoli_id, @nap_c2, 'Pizza Nutella com Morango', 'Nutella, morangos frescos e acucar de confeiteiro', 49.90, NULL, 50, 'un', 1),
(@napoli_id, @nap_c3, 'Suco de Laranja Natural 500ml', 'Suco de laranja espremido na hora', 12.90, NULL, 100, 'un', 1),
(@napoli_id, @nap_c3, 'Coca-Cola 2L', 'Refrigerante Coca-Cola garrafa 2 litros', 14.90, NULL, 100, 'un', 1),
(@napoli_id, @nap_c3, 'Agua Mineral 500ml', 'Agua mineral sem gas', 4.90, NULL, 100, 'un', 1);

-- ── Sushi Yama (10 produtos) ──
INSERT INTO om_market_products (partner_id, category_id, name, description, price, special_price, quantity, unit, status) VALUES
(@sushi_id, @su_c1, 'Sashimi Salmao 10 fatias', 'Fatias finas de salmao fresco', 42.90, NULL, 30, 'un', 1),
(@sushi_id, @su_c1, 'Sashimi Atum 10 fatias', 'Fatias de atum premium', 48.90, NULL, 30, 'un', 1),
(@sushi_id, @su_c2, 'Hot Roll 10 pecas', 'Salmao empanado com cream cheese', 32.90, 27.90, 50, 'un', 1),
(@sushi_id, @su_c2, 'Uramaki Salmao 10 pecas', 'Arroz por fora, salmao e cream cheese', 34.90, NULL, 50, 'un', 1),
(@sushi_id, @su_c2, 'Nigiri Variado 10 pecas', 'Mix de nigiri com peixes variados', 38.90, NULL, 50, 'un', 1),
(@sushi_id, @su_c3, 'Temaki Salmao Grelhado', 'Salmao grelhado com cream cheese e cebolinha', 24.90, NULL, 50, 'un', 1),
(@sushi_id, @su_c3, 'Temaki Philadelphia', 'Salmao, cream cheese e pepino', 22.90, NULL, 50, 'un', 1),
(@sushi_id, @su_c4, 'Combo Casal 40 pecas', 'Mix de sushi, sashimi e hot roll para dois', 89.90, 79.90, 20, 'un', 1),
(@sushi_id, @su_c4, 'Combo Family 60 pecas', 'Grande variedade para a familia toda', 129.90, NULL, 20, 'un', 1),
(@sushi_id, @su_c4, 'Combo Solo 20 pecas', 'Selecao individual com sushi e temaki', 49.90, NULL, 30, 'un', 1);

-- ── Acai da Terra (10 produtos) ──
INSERT INTO om_market_products (partner_id, category_id, name, description, price, special_price, quantity, unit, status) VALUES
(@acai_id, @ac_c1, 'Acai 300ml', 'Acai puro batido', 12.90, NULL, 100, 'un', 1),
(@acai_id, @ac_c1, 'Acai 500ml', 'Acai puro batido com granola e banana', 17.90, 14.90, 100, 'un', 1),
(@acai_id, @ac_c1, 'Acai 700ml', 'Acai puro com frutas, granola e leite condensado', 22.90, NULL, 100, 'un', 1),
(@acai_id, @ac_c2, 'Bowl Tropical', 'Acai, manga, banana, granola, coco ralado', 24.90, NULL, 50, 'un', 1),
(@acai_id, @ac_c2, 'Bowl Fitness', 'Acai, banana, aveia, mel, castanhas', 26.90, NULL, 50, 'un', 1),
(@acai_id, @ac_c2, 'Bowl Nutella', 'Acai com Nutella, morango, granola e leite condensado', 28.90, 24.90, 50, 'un', 1),
(@acai_id, @ac_c3, 'Creme de Cupuacu 300ml', 'Creme batido de cupuacu', 14.90, NULL, 50, 'un', 1),
(@acai_id, @ac_c3, 'Sorvete de Acai Pote 500ml', 'Sorvete cremoso de acai', 19.90, NULL, 50, 'un', 1),
(@acai_id, @ac_c3, 'Sorvete Misto Pote 500ml', 'Acai e cupuacu no mesmo pote', 21.90, NULL, 50, 'un', 1),
(@acai_id, @ac_c1, 'Acai 1 Litro', 'Acai puro para levar', 32.90, 28.90, 50, 'un', 1);

-- ── Padaria Pao Quente (10 produtos) ──
INSERT INTO om_market_products (partner_id, category_id, name, description, price, special_price, quantity, unit, status) VALUES
(@padaria_id, @pad_c1, 'Pao Frances (10 un)', 'Paezinhos fresquinhos do forno', 6.90, NULL, 200, 'un', 1),
(@padaria_id, @pad_c1, 'Pao de Queijo (6 un)', 'Pao de queijo mineiro quentinho', 12.90, NULL, 100, 'un', 1),
(@padaria_id, @pad_c1, 'Pao Integral', 'Pao integral fatiado artesanal', 9.90, NULL, 50, 'un', 1),
(@padaria_id, @pad_c2, 'Coxinha', 'Coxinha de frango cremosa', 6.90, NULL, 100, 'un', 1),
(@padaria_id, @pad_c2, 'Pastel de Carne', 'Pastel frito recheado com carne moida', 7.90, NULL, 100, 'un', 1),
(@padaria_id, @pad_c2, 'Empada de Frango', 'Empada caseira de frango', 5.90, NULL, 100, 'un', 1),
(@padaria_id, @pad_c3, 'Bolo de Chocolate Fatia', 'Bolo de chocolate com cobertura', 8.90, NULL, 50, 'un', 1),
(@padaria_id, @pad_c3, 'Sonho de Creme', 'Sonho recheado com creme', 5.90, 4.90, 80, 'un', 1),
(@padaria_id, @pad_c4, 'Cafe Expresso', 'Cafe expresso forte e encorpado', 5.90, NULL, 200, 'un', 1),
(@padaria_id, @pad_c4, 'Suco Natural 300ml', 'Suco de fruta natural do dia', 8.90, NULL, 100, 'un', 1);

-- ── Confeitaria Doce Mel (10 produtos) ──
INSERT INTO om_market_products (partner_id, category_id, name, description, price, special_price, quantity, unit, status) VALUES
(@confeitaria_id, @conf_c1, 'Bolo Red Velvet Inteiro', 'Bolo red velvet com cream cheese', 89.90, NULL, 20, 'un', 1),
(@confeitaria_id, @conf_c1, 'Bolo de Cenoura com Brigadeiro', 'Bolo de cenoura com cobertura de brigadeiro', 69.90, NULL, 20, 'un', 1),
(@confeitaria_id, @conf_c1, 'Bolo Naked Cake Frutas', 'Bolo naked com frutas vermelhas e chantilly', 99.90, 89.90, 15, 'un', 1),
(@confeitaria_id, @conf_c2, 'Brigadeiro Gourmet (6 un)', 'Brigadeiros gourmet sabores variados', 24.90, NULL, 50, 'un', 1),
(@confeitaria_id, @conf_c2, 'Trufa de Chocolate (4 un)', 'Trufas artesanais de chocolate belga', 22.90, NULL, 50, 'un', 1),
(@confeitaria_id, @conf_c2, 'Bem-Casado (10 un)', 'Doce fino para festas e eventos', 34.90, NULL, 30, 'un', 1),
(@confeitaria_id, @conf_c3, 'Torta Holandesa', 'Torta holandesa com biscoito e chocolate', 59.90, NULL, 15, 'un', 1),
(@confeitaria_id, @conf_c3, 'Torta de Limao', 'Torta de limao com merengue', 49.90, 44.90, 15, 'un', 1),
(@confeitaria_id, @conf_c3, 'Torta Alema de Maca', 'Torta de maca com canela e nozes', 54.90, NULL, 15, 'un', 1),
(@confeitaria_id, @conf_c2, 'Macaron (6 un)', 'Macarons franceses sabores sortidos', 29.90, NULL, 30, 'un', 1);

-- ── Drogaria Saude (10 produtos) ──
INSERT INTO om_market_products (partner_id, category_id, name, description, price, special_price, quantity, unit, status) VALUES
(@drogaria_id, @drog_c1, 'Dipirona 500mg (10 cp)', 'Analgesico e antipiretico', 8.90, NULL, 200, 'un', 1),
(@drogaria_id, @drog_c1, 'Ibuprofeno 400mg (10 cp)', 'Anti-inflamatorio', 12.90, NULL, 200, 'un', 1),
(@drogaria_id, @drog_c1, 'Dorflex (10 cp)', 'Analgesico e relaxante muscular', 14.90, 11.90, 200, 'un', 1),
(@drogaria_id, @drog_c2, 'Pasta Dental Colgate 90g', 'Creme dental com fluor', 6.90, NULL, 100, 'un', 1),
(@drogaria_id, @drog_c2, 'Sabonete Dove 90g', 'Sabonete hidratante', 4.90, NULL, 100, 'un', 1),
(@drogaria_id, @drog_c2, 'Desodorante Rexona 150ml', 'Desodorante aerosol 48h', 16.90, 13.90, 100, 'un', 1),
(@drogaria_id, @drog_c2, 'Shampoo Pantene 400ml', 'Shampoo restauracao', 24.90, NULL, 50, 'un', 1),
(@drogaria_id, @drog_c3, 'Vitamina C 1000mg (30 cp)', 'Suplemento de vitamina C', 29.90, NULL, 80, 'un', 1),
(@drogaria_id, @drog_c3, 'Omega 3 (60 caps)', 'Oleo de peixe concentrado', 49.90, 42.90, 50, 'un', 1),
(@drogaria_id, @drog_c3, 'Multivitaminico (30 cp)', 'Complexo vitaminico completo', 34.90, NULL, 80, 'un', 1);

-- ── Farma Bem (10 produtos) ──
INSERT INTO om_market_products (partner_id, category_id, name, description, price, special_price, quantity, unit, status) VALUES
(@farmabem_id, @fb_c1, 'Paracetamol 750mg (10 cp)', 'Analgesico e antipiretico', 7.90, NULL, 200, 'un', 1),
(@farmabem_id, @fb_c1, 'Buscopan Composto (10 cp)', 'Antiespamodico', 18.90, NULL, 150, 'un', 1),
(@farmabem_id, @fb_c1, 'Band-Aid (10 un)', 'Curativos adesivos', 9.90, NULL, 100, 'un', 1),
(@farmabem_id, @fb_c2, 'Perfume Natura Kaiak 100ml', 'Eau de toilette masculino', 129.90, 109.90, 20, 'un', 1),
(@farmabem_id, @fb_c2, 'Hidratante Nivea 200ml', 'Hidratante corporal', 22.90, NULL, 50, 'un', 1),
(@farmabem_id, @fb_c2, 'Protetor Solar FPS50 120ml', 'Protetor solar facial e corporal', 39.90, 34.90, 50, 'un', 1),
(@farmabem_id, @fb_c3, 'Acido Hialuronico Serum 30ml', 'Serum anti-idade', 59.90, NULL, 30, 'un', 1),
(@farmabem_id, @fb_c3, 'Vitamina C Serum Facial 30ml', 'Serum clareador e antioxidante', 49.90, NULL, 30, 'un', 1),
(@farmabem_id, @fb_c3, 'Agua Micelar 200ml', 'Demaquilante e limpeza facial', 29.90, 24.90, 50, 'un', 1),
(@farmabem_id, @fb_c3, 'Creme Anti-Idade 50g', 'Creme facial noturno', 69.90, NULL, 30, 'un', 1);

-- ── Pet Amigo (8 produtos) ──
INSERT INTO om_market_products (partner_id, category_id, name, description, price, special_price, quantity, unit, status) VALUES
(@pet_id, @pet_c1, 'Racao Golden Cao Adulto 15kg', 'Racao premium para caes adultos', 129.90, 109.90, 30, 'un', 1),
(@pet_id, @pet_c1, 'Racao Whiskas Gato Adulto 10kg', 'Racao para gatos adultos sabor peixe', 89.90, NULL, 30, 'un', 1),
(@pet_id, @pet_c1, 'Sache Pedigree (4 un)', 'Alimento umido para caes', 16.90, NULL, 100, 'un', 1),
(@pet_id, @pet_c2, 'Coleira Ajustavel', 'Coleira de nylon ajustavel P/M/G', 24.90, NULL, 50, 'un', 1),
(@pet_id, @pet_c2, 'Brinquedo Bolinha com Som', 'Bolinha que faz som para caes', 14.90, 11.90, 80, 'un', 1),
(@pet_id, @pet_c2, 'Cama Pet Tamanho M', 'Cama acolchoada para caes e gatos', 79.90, NULL, 20, 'un', 1),
(@pet_id, @pet_c3, 'Shampoo Pet 500ml', 'Shampoo neutro para caes e gatos', 24.90, NULL, 50, 'un', 1),
(@pet_id, @pet_c3, 'Tapete Higienico (30 un)', 'Tapete absorvente para pets', 39.90, 34.90, 40, 'un', 1);

-- ── Conveniencia 24h (8 produtos) ──
INSERT INTO om_market_products (partner_id, category_id, name, description, price, special_price, quantity, unit, status) VALUES
(@conv_id, @conv_c1, 'Coca-Cola Lata 350ml', 'Refrigerante gelado', 5.90, NULL, 200, 'un', 1),
(@conv_id, @conv_c1, 'Cerveja Heineken 350ml', 'Cerveja premium long neck', 8.90, NULL, 200, 'un', 1),
(@conv_id, @conv_c1, 'Red Bull 250ml', 'Energetico', 12.90, NULL, 100, 'un', 1),
(@conv_id, @conv_c1, 'Agua Mineral 1.5L', 'Agua mineral sem gas', 5.90, NULL, 200, 'un', 1),
(@conv_id, @conv_c2, 'Doritos 96g', 'Salgadinho sabor queijo nacho', 9.90, NULL, 100, 'un', 1),
(@conv_id, @conv_c2, 'Trident Menta', 'Chiclete sem acucar', 3.90, NULL, 200, 'un', 1),
(@conv_id, @conv_c3, 'Picole Kibon Fruttare', 'Picole de frutas', 6.90, 5.90, 100, 'un', 1),
(@conv_id, @conv_c3, 'Sorvete Haagen-Dazs 473ml', 'Sorvete premium chocolate', 42.90, NULL, 20, 'un', 1);

-- ── Acougue Boi Nobre (8 produtos) ──
INSERT INTO om_market_products (partner_id, category_id, name, description, price, special_price, quantity, unit, status) VALUES
(@acougue_id, @acg_c1, 'Picanha 1kg', 'Picanha bovina selecionada', 79.90, NULL, 30, 'kg', 1),
(@acougue_id, @acg_c1, 'Alcatra 1kg', 'Alcatra limpa de primeira', 49.90, 44.90, 40, 'kg', 1),
(@acougue_id, @acg_c1, 'Costela 1kg', 'Costela bovina para churrasco', 34.90, NULL, 50, 'kg', 1),
(@acougue_id, @acg_c1, 'Maminha 1kg', 'Maminha para grelhar', 54.90, NULL, 30, 'kg', 1),
(@acougue_id, @acg_c2, 'Costela Suina 1kg', 'Costela suina carnuda', 29.90, NULL, 40, 'kg', 1),
(@acougue_id, @acg_c2, 'Linguica Toscana 1kg', 'Linguica toscana artesanal', 24.90, 21.90, 50, 'kg', 1),
(@acougue_id, @acg_c3, 'Frango Inteiro', 'Frango inteiro congelado ~2kg', 24.90, NULL, 40, 'un', 1),
(@acougue_id, @acg_c3, 'Peito de Frango 1kg', 'Peito de frango sem osso', 19.90, NULL, 50, 'kg', 1);

-- ── Utilidades Casa (8 produtos) ──
INSERT INTO om_market_products (partner_id, category_id, name, description, price, special_price, quantity, unit, status) VALUES
(@utilidades_id, @util_c1, 'Panela Antiaderente 24cm', 'Panela com revestimento antiaderente', 49.90, NULL, 30, 'un', 1),
(@utilidades_id, @util_c1, 'Jogo de Talheres 24 pecas', 'Talheres em aco inox', 39.90, 34.90, 20, 'un', 1),
(@utilidades_id, @util_c1, 'Potes Hermeticos (5 un)', 'Kit com 5 potes para armazenamento', 29.90, NULL, 40, 'un', 1),
(@utilidades_id, @util_c2, 'Detergente Ype 500ml', 'Detergente lava-loucas', 3.90, NULL, 200, 'un', 1),
(@utilidades_id, @util_c2, 'Desinfetante Pinho 1L', 'Desinfetante multiuso', 7.90, NULL, 100, 'un', 1),
(@utilidades_id, @util_c2, 'Vassoura + Pa de Lixo', 'Kit vassoura com pa coletora', 19.90, 16.90, 50, 'un', 1),
(@utilidades_id, @util_c3, 'Caixa Organizadora Grande', 'Caixa plastica com tampa 50L', 34.90, NULL, 30, 'un', 1),
(@utilidades_id, @util_c3, 'Cabideiro Porta 6 ganchos', 'Cabideiro de porta em metal', 24.90, NULL, 40, 'un', 1);

-- ── Espetinho do Joao (10 produtos) ──
INSERT INTO om_market_products (partner_id, category_id, name, description, price, special_price, quantity, unit, status) VALUES
(@espetinho_id, @esp_c1, 'Espetinho de Picanha (3 un)', 'Espetinhos de picanha na brasa', 18.90, NULL, 100, 'un', 1),
(@espetinho_id, @esp_c1, 'Espetinho de Frango (5 un)', 'Espetinhos de frango temperado', 14.90, NULL, 100, 'un', 1),
(@espetinho_id, @esp_c1, 'Espetinho Misto (4 un)', 'Picanha, frango, linguica e queijo', 16.90, 13.90, 100, 'un', 1),
(@espetinho_id, @esp_c1, 'Espetinho de Queijo Coalho (4 un)', 'Queijo coalho grelhado', 12.90, NULL, 100, 'un', 1),
(@espetinho_id, @esp_c2, 'Porcao Calabresa Acebolada', 'Calabresa fatiada com cebola', 29.90, NULL, 50, 'un', 1),
(@espetinho_id, @esp_c2, 'Porcao Batata Frita', 'Porcao generosa de batata frita', 22.90, NULL, 50, 'un', 1),
(@espetinho_id, @esp_c2, 'Porcao Mandioca Frita', 'Mandioca frita crocante com molho', 19.90, NULL, 50, 'un', 1),
(@espetinho_id, @esp_c3, 'Cerveja Brahma 350ml', 'Cerveja gelada lata', 5.90, NULL, 200, 'un', 1),
(@espetinho_id, @esp_c3, 'Caipirinha de Limao', 'Caipirinha tradicional', 14.90, NULL, 100, 'un', 1),
(@espetinho_id, @esp_c3, 'Refrigerante 350ml', 'Coca, Guarana ou Fanta', 5.90, NULL, 200, 'un', 1);

-- ═══════════════════════════════════════════
-- FIM DA MIGRATION 010
-- Total: 13 lojas novas + ~130 produtos
-- ═══════════════════════════════════════════
