<?php
declare(strict_types=1);

namespace LorkhanServer\Application;

interface EmbeddingProvider
{
    /** @return list<float> */
    public function embed(string $text,CancellationToken $cancellation):array;

    public function model():string;
}
