<?php

namespace App\Domain\Audit;

interface InteractionRepository
{
    public function persist(Interaction $interaction): void;
}
