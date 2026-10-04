<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

final class IgnoreNoticeConfirmationRequired extends \RuntimeException
{
    public function __construct(public readonly array $preview)
    {
        parent::__construct('Přenos ignorování z avíz vyžaduje potvrzení.');
    }
}
