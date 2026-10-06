<?php

namespace App\Imports\Normalizing;

use Carbon\CarbonImmutable;

/**
 * A statement row in canonical form: a real date, signed cents and a cleaned payee.
 */
final readonly class NormalizedTransaction
{
    public function __construct(
        public CarbonImmutable $postedOn,
        public int $amountCents,
        public string $descriptionRaw,
        public string $payeeNormalized,
        public ?string $memo = null,
    ) {}

    /**
     * @return array{posted_on: string, amount_cents: int, description_raw: string, payee_normalized: string, memo: string|null}
     */
    public function toArray(): array
    {
        return [
            'posted_on' => $this->postedOn->toDateString(),
            'amount_cents' => $this->amountCents,
            'description_raw' => $this->descriptionRaw,
            'payee_normalized' => $this->payeeNormalized,
            'memo' => $this->memo,
        ];
    }

    /**
     * @param  array{posted_on: string, amount_cents: int, description_raw: string, payee_normalized: string, memo: string|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            CarbonImmutable::parse($data['posted_on']),
            $data['amount_cents'],
            $data['description_raw'],
            $data['payee_normalized'],
            $data['memo'],
        );
    }
}
