<?php

namespace Devolon\EPassi\Tests\Unit;

use Devolon\Payment\Contracts\HasUpdateTransactionData;
use Devolon\Payment\Contracts\PaymentGatewayInterface;
use Devolon\Payment\DTOs\PurchaseResultDTO;
use Devolon\Payment\DTOs\RedirectDTO;
use Devolon\Payment\Models\Transaction;
use Devolon\Payment\Services\GenerateCallbackURLService;
use Devolon\Payment\Services\PaymentGatewayDiscoveryService;
use Devolon\EPassi\EPassiGateway;
use Devolon\EPassi\Tests\EPassiTestCase;
use Devolon\Payment\Services\SetGatewayResultService;
use Hamcrest\Core\AnyOf;
use Illuminate\Foundation\Testing\WithFaker;
use Mockery\MockInterface;

class EPassiGatewayTest extends EPassiTestCase
{
    use WithFaker;

    public function testGetName()
    {
        // Arrange
        $gateway = $this->resolveGateway();

        // Act
        $result = $gateway->getName();

        // Assert
        $this->assertEquals('epassi', $result);
    }

    public function testItRegisteredAsGateway()
    {
        // Arrange
        $paymentGatewayDiscoveryService = $this->resolvePaymentGatewayDiscoveryService();

        // Act
        $result = $paymentGatewayDiscoveryService->get('epassi');

        // Assert
        $this->assertInstanceOf(EPassiGateway::class, $result);
        $this->assertInstanceOf(HasUpdateTransactionData::class, $result);
    }

    public function testPurchase()
    {
        // Arrange
        $redirectUrl = $this->faker->url;
        $macKey = $this->faker->word;
        $accountId = $this->faker->word;
        config([
            'epassi.redirect_url' => $redirectUrl,
            'epassi.mac_key' => $macKey,
            'epassi.account_id' => $accountId,
        ]);

        $successUrl = $this->faker->url;
        $failureUrl = $this->faker->url;
        $generateCallbackURLService = $this->mockGenerateCallBackUrlService();

        $transaction = Transaction::factory()->create(['money_amount' => $this->faker->randomFloat('1')]);
        $amount = number_format($transaction->money_amount, 2, '.', '');
        $expectedFormParameters = [
            'STAMP' => $transaction->id,
            'SITE' => $accountId,
            'AMOUNT' => $amount,
            'REJECT' => $failureUrl,
            'CANCEL' => $failureUrl,
            'RETURN' => $successUrl,
            'MAC' => hash(
                "sha512",
                "{$transaction->id}&{$accountId}&{$amount}&{$macKey}"
            )
        ];

        $expectedPurchaseResultDTO = PurchaseResultDTO::fromArray([
            'should_redirect' => true,
            'redirect_to' => RedirectDTO::fromArray([
                'redirect_url' => $redirectUrl,
                'redirect_method' => 'POST',
                'redirect_data' => $expectedFormParameters,
            ]),
        ]);
        $gateway = $this->discoverGateway();

        // Expect
        $generateCallbackURLService
            ->shouldReceive('__invoke')
            ->with($transaction, AnyOf::anyOf([Transaction::STATUS_DONE, Transaction::STATUS_FAILED]))
            ->andReturnUsing(function ($tx, $status) use ($successUrl, $failureUrl) {
                return match ($status) {
                    Transaction::STATUS_DONE => $successUrl,
                    Transaction::STATUS_FAILED => $failureUrl,
                };
            });

        // Act
        $result = $gateway->purchase($transaction);

        // Assert
        $this->assertEquals($expectedPurchaseResultDTO, $result);
    }

    public function testVerifySuccessfully()
    {
        // Arrange
        $setGatewayResultService = $this->mockSetGatewayResultService();
        $gateway = $this->discoverGateway();
        $transaction = Transaction::factory()->create();
        $stamp = $transaction->id;
        $paid = $this->faker->randomNumber(5);
        $macKey = $this->faker->word;
        config([
            'epassi.mac_key' => $macKey,
        ]);
        $mac = hash('sha512', "{$stamp}&{$paid}&{$macKey}");
        $data = [
            'STAMP' => $stamp,
            'MAC' => $mac,
            'PAID' => $paid,
        ];

        // Expect
        $setGatewayResultService
            ->shouldReceive('__invoke')
            ->with($transaction, 'commit', $data)
            ->once();

        // Act
        $result = $gateway->verify($transaction, $data);

        // Assert
        $this->assertTrue($result);
        $transaction->refresh();
    }

    public function testVerifyFailed()
    {
        // Arrange
        $setGatewayResultService = $this->mockSetGatewayResultService();
        $gateway = $this->discoverGateway();
        $transaction = Transaction::factory()->create();
        $stamp = $this->faker->randomNumber(5);
        $paid = $this->faker->randomNumber(5);
        $mac = $this->faker->word;
        $data = [
            'STAMP' => $stamp,
            'MAC' => $mac,
            'PAID' => $paid,
        ];

        // Expect
        $setGatewayResultService->shouldNotReceive('__invoke');

        // Act
        $result = $gateway->verify($transaction, $data);

        // Assert
        $this->assertFalse($result);
        $transaction->refresh();
    }

    public function testVerifyFailedForWrongTransaction()
    {
        // Arrange
        $setGatewayResultService = $this->mockSetGatewayResultService();
        $gateway = $this->discoverGateway();
        $transaction = Transaction::factory()->create();
        $stamp = $this->faker->randomNumber(5);
        $paid = $this->faker->randomNumber(5);

        $macKey = $this->faker->word;
        config([
            'epassi.mac_key' => $macKey,
        ]);
        $mac = hash('sha512', "{$stamp}&{$paid}&{$macKey}");
        $data = [
            'STAMP' => $stamp,
            'MAC' => $mac,
            'PAID' => $paid,
        ];

        // Expect
        $setGatewayResultService->shouldNotReceive('__invoke');

        // Act
        $result = $gateway->verify($transaction, $data);

        // Assert
        $this->assertFalse($result);
        $transaction->refresh();
    }

    public function testUpdateTransactionDataRulesWithDoneStatus()
    {
        // Arrange
        $gateway = $this->resolveGateway();
        $expected = [
            'STAMP' => [
                'required',
                'string',
            ],
            'MAC' => [
                'required',
                'string',
            ],
            'PAID' => [
                'required',
                'string',
            ],
        ];

        // Act
        $result = $gateway->updateTransactionDataRules('done');

        // Assert
        $this->assertEquals($expected, $result);
    }

    public function testUpdateTransactionDataRulesWithFailedStatus()
    {
        // Arrange
        $gateway = $this->resolveGateway();
        $expected = [];

        // Act
        $result = $gateway->updateTransactionDataRules('failed');

        // Assert
        $this->assertEquals($expected, $result);
    }

    private function resolveGateway(): EPassiGateway
    {
        return resolve(EPassiGateway::class);
    }

    private function resolvePaymentGatewayDiscoveryService(): PaymentGatewayDiscoveryService
    {
        return resolve(PaymentGatewayDiscoveryService::class);
    }

    private function discoverGateway(): PaymentGatewayInterface
    {
        $paymentDiscoveryService = $this->resolvePaymentGatewayDiscoveryService();

        return $paymentDiscoveryService->get('epassi');
    }
    private function mockGenerateCallBackUrlService(): MockInterface
    {
        return $this->mock(GenerateCallbackURLService::class);
    }

    private function mockSetGatewayResultService(): MockInterface
    {
        return $this->mock(SetGatewayResultService::class);
    }
}
