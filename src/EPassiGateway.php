<?php

namespace Devolon\EPassi;

use Devolon\Payment\Contracts\HasUpdateTransactionData;
use Devolon\Payment\Contracts\PaymentGatewayInterface;
use Devolon\Payment\DTOs\PurchaseResultDTO;
use Devolon\Payment\DTOs\RedirectDTO;
use Devolon\Payment\Models\Transaction;
use Devolon\Payment\Services\GenerateCallbackURLService;
use Devolon\Payment\Services\SetGatewayResultService;

class EPassiGateway implements PaymentGatewayInterface, HasUpdateTransactionData
{
    public const NAME = 'epassi';

    public function __construct(
        private GenerateCallbackURLService $generateCallbackURLService,
        private SetGatewayResultService $setGatewayResultService,
    ) {
    }

    public function purchase(Transaction $transaction): PurchaseResultDTO
    {
        $accountId = config('epassi.account_id');
        $macKey = config('epassi.mac_key');
        $failureUrl = ($this->generateCallbackURLService)($transaction, Transaction::STATUS_FAILED);
        $successUrl = ($this->generateCallbackURLService)($transaction, Transaction::STATUS_DONE);
        $amount = number_format($transaction->money_amount, 2, '.', '');

        return PurchaseResultDTO::fromArray([
            'should_redirect' => true,
            'redirect_to' => RedirectDTO::fromArray([
                'redirect_url' => config('epassi.redirect_url'),
                'redirect_method' => 'POST',
                'redirect_data' => [
                    'STAMP' => $transaction->id,
                    'SITE' => $accountId,
                    'AMOUNT' => $amount,
                    'REJECT' => $failureUrl,
                    'CANCEL' => $failureUrl,
                    'RETURN' => $successUrl,
                    'MAC' => hash(
                        "sha512",
                        "{$transaction->id}&{$accountId}&{$amount}&$macKey"
                    )
                ],
            ])
        ]);
    }

    public function verify(Transaction $transaction, array $data): bool
    {
        if ($transaction->id !== $data['STAMP']) {
            return false;
        }

        $macKey = config('epassi.mac_key');
        $calculatedMac = hash('sha512', "{$data['STAMP']}&{$data['PAID']}&{$macKey}");

        if ($data['MAC'] !== $calculatedMac) {
            return false;
        }

        ($this->setGatewayResultService)($transaction, 'commit', $data);

        return true;
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function updateTransactionDataRules(string $newStatus): array
    {
        if ($newStatus !== Transaction::STATUS_DONE) {
            return [];
        }

        return [
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
    }
}
