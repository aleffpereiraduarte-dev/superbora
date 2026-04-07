<?php
namespace SuperBora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use OmPricing;

class PricingTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../../includes/classes/OmPricing.php';
    }

    #[Test]
    public function boraum_cost_respects_minimum(): void
    {
        // Very short distance should still hit the floor (R$8)
        $this->assertSame(8.00, OmPricing::calcularCustoBoraUm(0.5));
        $this->assertSame(8.00, OmPricing::calcularCustoBoraUm(1.0));
    }

    #[Test]
    public function boraum_cost_grows_with_distance(): void
    {
        // 5km = 5.00 base + 5*1.50 = 12.50
        $this->assertSame(12.50, OmPricing::calcularCustoBoraUm(5.0));
        // 10km = 5.00 + 10*1.50 = 20.00
        $this->assertSame(20.00, OmPricing::calcularCustoBoraUm(10.0));
    }

    #[Test]
    public function commission_for_partner_own_delivery_is_10_percent(): void
    {
        $r = OmPricing::calcularComissao(100.00, 'proprio');
        $this->assertSame(0.10, $r['taxa']);
        $this->assertSame(10.00, $r['valor']);
    }

    #[Test]
    public function commission_for_pickup_is_8_percent(): void
    {
        $r = OmPricing::calcularComissao(100.00, 'pickup');
        $this->assertSame(0.08, $r['taxa']);
        $this->assertSame(8.00, $r['valor']);
    }

    #[Test]
    public function commission_for_boraum_is_18_percent(): void
    {
        $r = OmPricing::calcularComissao(100.00, 'boraum');
        $this->assertSame(0.18, $r['taxa']);
        $this->assertSame(18.00, $r['valor']);
    }

    #[Test]
    public function boraum_commission_enforces_minimum_margin(): void
    {
        // Small order R$30 with BoraUm cost R$15 -> 18% = R$5.40
        // Margin would be 5.40 - 15 = -9.60 (loss). Engine should bump.
        $r = OmPricing::calcularComissao(30.00, 'boraum', 15.00);
        $expectedMin = 15.00 + OmPricing::MARGEM_MINIMA_SUPERBORA; // 17.00
        $this->assertGreaterThanOrEqual($expectedMin, $r['valor']);
        $this->assertGreaterThanOrEqual(OmPricing::MARGEM_MINIMA_SUPERBORA, $r['lucro_superbora']);
    }

    #[Test]
    public function service_fee_is_constant(): void
    {
        $this->assertSame(2.49, OmPricing::TAXA_SERVICO);
    }

    #[Test]
    public function tip_max_is_capped(): void
    {
        $this->assertSame(200.00, OmPricing::GORJETA_MAX);
    }
}
