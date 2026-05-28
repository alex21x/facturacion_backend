<?php

namespace App\Contracts;

interface PadronLookupGateway
{
    public function consultDni(string $dni): array;

    public function consultRuc(string $ruc): array;
}
