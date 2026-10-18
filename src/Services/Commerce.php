<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Settings;

/**
 * Business defaults from the "commerce" settings (editable in Admin → Settings), with safe fallbacks.
 * Per-style values (MOQ, lead time, packing) override these where a style sets them.
 */
final class Commerce
{
    /** Used for freight estimates when a style has no packing data of its own. */
    public const PACKING_DEFAULTS = [
        'piece_weight_g' => 650,
        'pcs_per_carton' => 50,
        'carton_length_mm' => 600,
        'carton_width_mm' => 400,
        'carton_height_mm' => 400,
        'carton_weight_g' => 1200,
    ];

    /** Every size ordered in a color needs at least this many pieces (owner rule 2026-10-17), unless the settings say otherwise. */
    public const MIN_PCS_PER_SIZE = 20;

    /** Sample price per piece when the settings have none (owner decision 2026-10-17: $100 plus courier). */
    public const SAMPLE_PRICE_CENTS = 10000;

    /** @var array<string, mixed>|null */
    private ?array $values = null;

    public function __construct(private readonly Settings $settings)
    {
    }

    public function depositPercent(): int
    {
        return max(0, min(100, (int) ($this->values()['deposit_percent'] ?? 50)));
    }

    public function leadTimeDays(): int
    {
        return max(1, (int) ($this->values()['lead_time_days'] ?? 30));
    }

    /** Days to prepare samples of shop styles, counted from the payment's confirmation. */
    public function sampleLeadTimeDays(): int
    {
        return max(1, (int) ($this->values()['sample_lead_time_days'] ?? 7));
    }

    /** Days to make a custom-design sample (from the buyer's tech pack or reference). */
    public function customSampleLeadTimeDays(): int
    {
        return max(1, (int) ($this->values()['custom_sample_lead_time_days'] ?? 14));
    }

    /** The sample price of a style without its own (per piece). */
    public function samplePriceCents(): int
    {
        return max(0, (int) ($this->values()['sample_price_cents'] ?? self::SAMPLE_PRICE_CENTS));
    }

    /** How long a proforma invoice's prices are valid. */
    public function piValidDays(): int
    {
        return max(1, (int) ($this->values()['pi_valid_days'] ?? 14));
    }

    /** Above this volume (CBM), sea freight is quoted as a full container instead of estimated. */
    public function fclFromCbm(): float
    {
        return max(1.0, (float) ($this->values()['fcl_from_cbm'] ?? 15));
    }

    /** @return array<string, int> */
    public function packingDefaults(): array
    {
        $packing = $this->values()['packing'] ?? [];
        $defaults = [];
        foreach (self::PACKING_DEFAULTS as $key => $fallback) {
            $value = is_array($packing) ? (int) ($packing[$key] ?? 0) : 0;
            $defaults[$key] = $value > 0 ? $value : $fallback;
        }

        return $defaults;
    }

    /** @return array<string, mixed> */
    private function values(): array
    {
        if ($this->values === null) {
            $values = $this->settings->get('commerce', []);
            $this->values = is_array($values) ? $values : [];
        }

        return $this->values;
    }
}
