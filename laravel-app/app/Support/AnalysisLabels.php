<?php

namespace App\Support;

/**
 * Label & nada tampilan untuk nilai-nilai hasil analisis (enum dari FastAPI).
 */
final class AnalysisLabels
{
    /** @return array{0: string, 1: string} [label, tone x-status] */
    public static function aiVerdict(?string $verdict): array
    {
        return match ($verdict) {
            'LIKELY_AI' => ['Kemungkinan AI', 'danger'],
            'MIXED_AI' => ['Campuran', 'warn'],
            'HUMAN_WRITTEN' => ['Tulisan manusia', 'ok'],
            'ERROR' => ['Gagal dianalisis', 'muted'],
            default => [$verdict ?? '—', 'muted'],
        };
    }

    /** @return array{0: string, 1: string} */
    public static function action(?string $action): array
    {
        return match ($action) {
            'PERIKSA_MANUAL' => ['Periksa manual', 'warn'],
            'SKIP_KOREKSI' => ['Lewati koreksi', 'muted'],
            'AMAN' => ['Aman', 'ok'],
            default => [$action ?? '—', 'muted'],
        };
    }

    /** @return array{0: string, 1: string} */
    public static function matchType(?string $type): array
    {
        return match ($type) {
            'VERBATIM_COPY' => ['Salinan langsung', 'danger'],
            default => ['Parafrase', 'warn'],
        };
    }

    public static function score(?float $score, int $decimals = 3): string
    {
        return $score === null ? '—' : number_format($score, $decimals, ',', '.');
    }

    public static function percent(?float $value): string
    {
        return $value === null ? '—' : number_format($value * 100, 0, ',', '.') . '%';
    }
}
