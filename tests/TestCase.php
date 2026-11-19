<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Nenhum teste sai para a rede: requisição sem Http::fake() lança
        // StrayRequestException. A precificação usa o provedor simulado por
        // padrão (IA_PROVIDER=simulado no phpunit.xml), e quem testa um
        // provedor real precisa falsificar a resposta com Http::fake().
        Http::preventStrayRequests();
    }
}
