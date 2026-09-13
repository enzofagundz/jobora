<?php

namespace App\Mcp\Tools\Screening;

use App\Mcp\Tools\JoboraTool;
use App\Models\ScreeningPreference;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Define os critérios de triagem do usuário configurado (listas de texto livre). Rejeições têm prioridade sobre preferências. Uma linha por usuário.')]
class SaveScreeningPreferencesTool extends JoboraTool
{
    protected function execute(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'preferences' => ['nullable', 'array', 'max:100'],
            'preferences.*' => ['string', 'max:100'],
            'rejections' => ['nullable', 'array', 'max:100'],
            'rejections.*' => ['string', 'max:100'],
        ]);

        $preferences = $this->normalize($validated['preferences'] ?? []);
        $rejections = $this->normalize($validated['rejections'] ?? []);

        ScreeningPreference::updateOrCreate(
            ['user_id' => $this->user()->id],
            ['preferences' => $preferences, 'rejections' => $rejections],
        );

        return $this->ok([
            'preferences' => $preferences,
            'rejections' => $rejections,
        ]);
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @return array<int, string>
     */
    private function normalize(array $values): array
    {
        $normalized = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $value = trim($value);

            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'preferences' => $schema->array()->description('Critérios desejados (texto livre).')->items($schema->string()),
            'rejections' => $schema->array()->description('Critérios indesejados (têm prioridade).')->items($schema->string()),
        ];
    }
}
