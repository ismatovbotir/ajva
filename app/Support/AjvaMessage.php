<?php

namespace App\Support;

/** Builds the displayable AjvaAI answer (note + local result sections) from an assistant reply. */
class AjvaMessage
{
    /**
     * @param  array{text: string, results: array<int, array{tool: string, args: array<string, mixed>, data: array<string, mixed>}>}  $result
     * @return array<string, mixed>
     */
    public static function fromResult(array $result): array
    {
        // Results stay on this server: they are only rendered here, never sent back to the AI.
        $sections = array_map(fn (array $r) => [
            'tool' => $r['tool'],
            'args' => collect($r['args'])->map(fn ($v, $k) => $k.': '.(is_array($v) ? implode(', ', $v) : $v))->implode(' · '),
            'error' => $r['data']['error'] ?? null,
            'notes' => isset($r['data']['error']) ? [] : LocalAdvisor::notes($r['tool'], $r['data']),
            'blocks' => isset($r['data']['error']) ? [] : ResultBlocks::from($r['data']),
        ], $result['results']);

        return ['role' => 'model', 'text' => $result['text'], 'sections' => $sections];
    }
}
