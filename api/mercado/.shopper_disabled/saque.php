<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * POST /api/mercado/shopper/saque.php
 * Redireciona para API financeira de saque
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * REQUER: Autenticação de Shopper APROVADO pelo RH
 * Header: Authorization: Bearer <token>
 *
 * Body: {
 *   "valor": 50.00,
 *   "pix_tipo": "cpf",
 *   "pix_chave": "12345678900"
 * }
 */

// Redirecionar para API financeira unificada
require_once dirname(__DIR__, 2) . '/financeiro/saque.php';
