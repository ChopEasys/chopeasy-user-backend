<?php

namespace Tests\Unit;

use App\Models\AgentEarning;
use App\Models\Order;
use App\Services\AgentCommissionService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AgentCommissionServiceTest extends TestCase
{
    protected object $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new class extends AgentCommissionService
        {
            public function customerBase(Order $order): float
            {
                return $this->customerCompanyRevenueBase($order);
            }

            public function vendorBase(Order $order, ?float $platformTakeAmount = null, ?float $grossAmount = null): float
            {
                return $this->vendorCompanyProfitBase($order, $platformTakeAmount, $grossAmount);
            }

            public function riderBase(Order $order, ?float $riderPayoutAmount = null): float
            {
                return $this->riderCompanyProfitBase($order, $riderPayoutAmount);
            }
        };
    }

    #[Test]
    public function it_uses_markup_and_service_fee_for_customer_commission_base(): void
    {
        $order = new Order([
            'service_fee_total' => 2000,
            'pricing_breakdown' => [
                'product_markup_total' => 3000,
                'service_fee_total' => 2000,
                'platform_revenue' => 15000,
            ],
        ]);

        $this->assertEquals(5000.0, $this->service->customerBase($order));
    }

    #[Test]
    public function it_does_not_use_full_platform_revenue_for_customer_commission_base(): void
    {
        $order = new Order([
            'service_fee_total' => 1000,
            'pricing_breakdown' => [
                'product_markup_total' => 2000,
                'service_fee_total' => 1000,
                'payout_breakdown' => [
                    'platform_revenue' => 20000,
                ],
            ],
        ]);

        $this->assertEquals(3000.0, $this->service->customerBase($order));
    }

    #[Test]
    public function it_uses_platform_vendor_take_for_vendor_commission_base(): void
    {
        $order = new Order([
            'pricing_breakdown' => [
                'vendor_take_percent' => 3,
                'payout_breakdown' => [
                    'vendor_take_percent' => 3,
                ],
            ],
        ]);

        $this->assertEquals(1500.0, $this->service->vendorBase($order, 1500, 50000));
    }

    #[Test]
    public function it_uses_delivery_margin_for_rider_commission_base(): void
    {
        $order = new Order([
            'delivery_fee_total' => 15000,
            'rider_payout' => 9000,
        ]);

        $this->assertEquals(6000.0, $this->service->riderBase($order, 9000));
    }

    #[Test]
    public function it_reconstructs_the_recorded_commission_base_for_display(): void
    {
        $earning = new AgentEarning([
            'earning_type' => 'customer_order',
            'commission_percent' => 15,
            'amount' => 750,
            'order_amount' => 99999,
        ]);

        $details = $this->service->describeEarning($earning);

        $this->assertEquals(5000.0, $details['commission_base_amount']);
        $this->assertSame('Markup + service fee', $details['commission_base_label']);
    }
}
