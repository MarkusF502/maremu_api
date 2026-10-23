<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\CategoriaPai;
use App\Models\Loja;
use App\Models\SubCategoria;
use App\Services\TaxonomiaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TaxonomiaTest extends TestCase
{
    use RefreshDatabase;

    private Loja $loja;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loja = Loja::factory()->create();
        Sanctum::actingAs($this->loja->user);
    }

    public function test_sincronizar_cria_a_arvore_e_e_idempotente(): void
    {
        $servico = app(TaxonomiaService::class);

        $resultado = $servico->sincronizar($this->taxonomia());
        $servico->sincronizar($this->taxonomia());

        $this->assertSame(['categorias_pai' => 2, 'sub_categorias' => 4], $resultado);
        $this->assertSame(2, CategoriaPai::count());
        $this->assertSame(4, SubCategoria::count());
        $this->assertFalse(SubCategoria::where('slug', 'outros')->first()->comparavel);
        $this->assertTrue(SubCategoria::where('slug', 'jeans')->first()->comparavel);
    }

    public function test_sincronizar_de_novo_preserva_ids_e_atualiza_nome(): void
    {
        $servico = app(TaxonomiaService::class);
        $servico->sincronizar($this->taxonomia());
        $idJeans = SubCategoria::where('slug', 'jeans')->value('id');

        $nova = $this->taxonomia();
        $nova[0]['sub_categorias'][0]['google_taxonomy_id'] = 204;
        $nova[0]['sub_categorias'][] = ['nome' => 'Alfaiataria'];
        $servico->sincronizar($nova);

        $this->assertSame($idJeans, SubCategoria::where('slug', 'jeans')->value('id'));
        $this->assertSame(204, SubCategoria::find($idJeans)->google_taxonomy_id);
        $this->assertSame(5, SubCategoria::count());
    }

    public function test_sincronizar_rejeita_pai_sem_sub_categoria(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(TaxonomiaService::class)->sincronizar([['nome' => 'Vestidos', 'sub_categorias' => []]]);
    }

    public function test_arquivo_da_taxonomia_carrega_as_14_categorias_pai(): void
    {
        $servico = app(TaxonomiaService::class);

        $resultado = $servico->sincronizar($servico->lerArquivo());

        $this->assertSame(14, $resultado['categorias_pai']);
        $this->assertSame(SubCategoria::count(), $resultado['sub_categorias']);
        // "Outros" só existe onde há sub-categorias de verdade, e nunca é comparável.
        $this->assertSame(7, SubCategoria::where('comparavel', false)->count());
        $this->assertSame(0, SubCategoria::where('slug', 'outros')->where('comparavel', true)->count());
    }

    public function test_endpoint_devolve_a_arvore_ordenada(): void
    {
        app(TaxonomiaService::class)->sincronizar($this->taxonomia());

        $this->getJson('/api/taxonomia')
            ->assertOk()
            ->assertJsonCount(2, 'categorias_pai')
            ->assertJsonPath('categorias_pai.0.nome', 'Calças')
            ->assertJsonPath('categorias_pai.0.sub_categorias.0.nome', 'Jeans')
            ->assertJsonPath('categorias_pai.1.sub_categorias.0.nome', 'Geral');
    }

    public function test_cria_categoria_com_sub_categoria(): void
    {
        app(TaxonomiaService::class)->sincronizar($this->taxonomia());
        $sub = SubCategoria::where('slug', 'jeans')->first();

        $this->postJson('/api/categorias', ['nome' => 'Jeans da casa', 'sub_categoria_id' => $sub->id])
            ->assertCreated()
            ->assertJsonPath('categoria.sub_categoria_id', $sub->id);
    }

    public function test_rejeita_categoria_sem_sub_categoria(): void
    {
        $this->postJson('/api/categorias', ['nome' => 'Jeans da casa'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sub_categoria_id');
    }

    public function test_rejeita_sub_categoria_inexistente(): void
    {
        $this->postJson('/api/categorias', ['nome' => 'Jeans da casa', 'sub_categoria_id' => 9999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sub_categoria_id');
    }

    public function test_listagem_conta_categorias_pendentes_de_classificacao(): void
    {
        app(TaxonomiaService::class)->sincronizar($this->taxonomia());
        $sub = SubCategoria::where('slug', 'jeans')->first();

        Categoria::factory()->create(['loja_id' => $this->loja->id, 'nome' => 'Antiga']);
        Categoria::factory()->create(['loja_id' => $this->loja->id, 'nome' => 'Nova', 'sub_categoria_id' => $sub->id]);

        $this->getJson('/api/categorias')
            ->assertOk()
            ->assertJsonPath('pendentes_classificacao', 1)
            ->assertJsonPath('categorias.1.sub_categoria.nome', 'Jeans')
            ->assertJsonPath('categorias.1.sub_categoria.categoria_pai.nome', 'Calças');
    }

    public function test_classifica_categoria_existente(): void
    {
        app(TaxonomiaService::class)->sincronizar($this->taxonomia());
        $sub = SubCategoria::where('slug', 'jeans')->first();
        $categoria = Categoria::factory()->create(['loja_id' => $this->loja->id]);

        $this->patchJson("/api/categorias/{$categoria->id}", ['sub_categoria_id' => $sub->id])
            ->assertOk()
            ->assertJsonPath('categoria.sub_categoria.nome', 'Jeans');

        $this->assertSame($sub->id, $categoria->fresh()->sub_categoria_id);
    }

    public function test_nao_classifica_categoria_de_outra_loja(): void
    {
        app(TaxonomiaService::class)->sincronizar($this->taxonomia());
        $sub = SubCategoria::where('slug', 'jeans')->first();
        $alheia = Categoria::factory()->create();

        $this->patchJson("/api/categorias/{$alheia->id}", ['sub_categoria_id' => $sub->id])
            ->assertNotFound();

        $this->assertNull($alheia->fresh()->sub_categoria_id);
    }

    private function taxonomia(): array
    {
        return [
            [
                'nome' => 'Calças',
                'google_taxonomy_id' => 204,
                'sub_categorias' => [
                    ['nome' => 'Jeans'],
                    ['nome' => 'Moletom'],
                    ['nome' => 'Outros', 'comparavel' => false],
                ],
            ],
            [
                'nome' => 'Vestidos',
                'sub_categorias' => [
                    ['nome' => 'Geral'],
                ],
            ],
        ];
    }
}
