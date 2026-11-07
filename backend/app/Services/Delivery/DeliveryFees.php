<?php

namespace App\Services\Delivery;

use App\Exceptions\BusinessException;
use App\Models\Core\Branch;
use App\Services\Core\SettingsService;

/**
 * Delivery fee by distance: starting fee + fee per km, at least the minimum,
 * rounded up. Distance is from the branch (or the business location) to the
 * customer, as the crow flies times a road factor, so no paid map service is
 * needed. Without a location or a per-km fee, the flat shop fee is used.
 */
class DeliveryFees
{
    public function __construct(private readonly SettingsService $settings) {}

    /** @return array{float, float}|null [latitude, longitude] the delivery starts from */
    public function origin(?Branch $branch): ?array
    {
        if ($branch && $branch->latitude !== null && $branch->longitude !== null) {
            return [(float) $branch->latitude, (float) $branch->longitude];
        }
        $lat = $this->settings->get('business_latitude');
        $lng = $this->settings->get('business_longitude');

        return is_numeric($lat) && is_numeric($lng) ? [(float) $lat, (float) $lng] : null;
    }

    /** @return array{fee: float, distance_km: ?float, by_distance: bool} */
    public function quote(?Branch $branch, ?float $lat, ?float $lng): array
    {
        $perKm = (float) $this->settings->get('delivery_fee_per_km');
        $origin = $this->origin($branch);
        if ($lat === null || $lng === null || ! $origin || $perKm <= 0) {
            return ['fee' => (float) $this->settings->get('shop_delivery_fee'), 'distance_km' => $origin && $lat !== null && $lng !== null ? $this->distance($origin, [$lat, $lng]) : null, 'by_distance' => false];
        }

        $km = $this->distance($origin, [$lat, $lng]);
        $max = (float) $this->settings->get('delivery_max_km');
        if ($max > 0 && $km > $max) {
            throw new BusinessException(__('Sorry, we deliver up to :max km. This place is about :km km away.', ['max' => 0 + $max, 'km' => $km]), 'TOO_FAR');
        }
        $fee = (float) $this->settings->get('delivery_base_fee') + $perKm * $km;
        $fee = max($fee, (float) $this->settings->get('delivery_min_fee'));
        $step = max(1, $this->settings->int('delivery_round_to'));

        return ['fee' => (float) (ceil(round($fee, 2) / $step) * $step), 'distance_km' => $km, 'by_distance' => true];
    }

    /** Road distance estimate in km (great-circle distance × road factor), 2 decimals. */
    public function distance(array $from, array $to): float
    {
        [$lat1, $lng1] = array_map('deg2rad', $from);
        [$lat2, $lng2] = array_map('deg2rad', $to);
        $a = sin(($lat2 - $lat1) / 2) ** 2 + cos($lat1) * cos($lat2) * sin(($lng2 - $lng1) / 2) ** 2;
        $km = 6371.0 * 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round($km * max(1.0, (float) $this->settings->get('delivery_road_factor')), 2);
    }
}
