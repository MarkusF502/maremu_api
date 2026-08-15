<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AuthTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_retorna_usuario_e_token(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name'                  => 'Maria Teste',
            'email'                 => 'maria@example.com',
            'password'              => 'senha1234',
            'password_confirmation' => 'senha1234',
        ]);

        $response->assertCreated();
        $response->assertJsonStructure(['user', 'token']);
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_login_retorna_usuario_e_token(): void
    {
        $user = User::factory()->create([
            'email'    => 'joao@example.com',
            'password' => 'senha1234',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email'    => 'joao@example.com',
            'password' => 'senha1234',
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['user', 'token']);
        $this->assertNotEmpty($response->json('token'));
        $this->assertSame($user->id, $response->json('user.id'));
    }

    public function test_login_com_credenciais_invalidas_retorna_401(): void
    {
        User::factory()->create([
            'email'    => 'joao@example.com',
            'password' => 'senha1234',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email'    => 'joao@example.com',
            'password' => 'senha-errada',
        ]);

        $response->assertUnauthorized();
    }

    public function test_rota_protegida_autentica_via_bearer_token(): void
    {
        $user  = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me');

        $response->assertOk();
        $response->assertJsonPath('user.id', $user->id);
    }

    public function test_rota_protegida_sem_token_retorna_401(): void
    {
        $response = $this->getJson('/api/auth/me');

        $response->assertUnauthorized();
        $response->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_logout_revoga_apenas_o_token_atual(): void
    {
        $user   = User::factory()->create();
        $token1 = $user->createToken('auth_token')->plainTextToken;
        $token2 = $user->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token1}")
            ->postJson('/api/auth/logout')
            ->assertOk();

        // O guard 'sanctum' cacheia o usuário resolvido por requisição de teste
        // (mesma instância de container entre chamadas); força reresolução.
        Auth::forgetGuards();

        // Token usado no logout foi revogado.
        $this->withHeader('Authorization', "Bearer {$token1}")
            ->getJson('/api/auth/me')
            ->assertUnauthorized();

        Auth::forgetGuards();

        // Outros tokens do mesmo usuário continuam válidos.
        $this->withHeader('Authorization', "Bearer {$token2}")
            ->getJson('/api/auth/me')
            ->assertOk();
    }
}
