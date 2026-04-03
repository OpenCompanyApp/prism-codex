<?php

declare(strict_types=1);

namespace OpenCompany\PrismCodex\Contracts;

use OpenCompany\PrismCodex\ValueObjects\CodexToken;

interface CodexTokenStore
{
    public function current(): ?CodexToken;

    public function save(CodexToken $token): CodexToken;

    public function clear(): void;
}
