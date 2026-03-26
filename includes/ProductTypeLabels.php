<?php
namespace DataFlair\Toplists\Models;

/**
 * Resolves display labels and field visibility based on product type (casino, sportsbook, etc.).
 *
 * Centralizes all label text so renderers never hardcode vertical-specific strings.
 */
class ProductTypeLabels
{
    /**
     * Canonical label sets keyed by normalized product type.
     *
     * @var array<string, array<string, string>>
     */
    private static $labels = [
        'casino' => [
            'offer_text_label'      => 'Welcome Bonus',
            'bonus_wagering_label'  => 'Bonus Wagering',
            'min_deposit_label'     => 'Min Deposit',
            'games_count_label'     => 'Casino Games',
            'payout_time_label'     => 'Payout Time',
            'max_payout_label'      => 'Max Payout',
            'licences_label'        => 'Licences',
        ],
        'sportsbook' => [
            'offer_text_label'      => 'Welcome Offer',
            'bonus_wagering_label'  => 'Rollover Requirement',
            'min_deposit_label'     => 'Min Deposit',
            'games_count_label'     => 'Casino Games', // hidden via isFieldVisible()
            'payout_time_label'     => 'Payout Time',
            'max_payout_label'      => 'Max Payout',
            'licences_label'        => 'Licences',
        ],
    ];

    /**
     * Fields that should be hidden for a given product type.
     *
     * @var array<string, string[]>
     */
    private static $hiddenFields = [
        'sportsbook' => ['games_count', 'has_free_spins'],
    ];

    /**
     * Return the label set for the given product type, defaulting to casino.
     *
     * @param string $productType Raw product type from the API (e.g. "Casino", "Sportsbook").
     * @return array<string, string>
     */
    public static function getLabels($productType)
    {
        $type = self::normalizeType($productType);

        return self::$labels[$type] ?? self::$labels['casino'];
    }

    /**
     * Whether a field should be rendered for this product type.
     *
     * @param string $productType Raw product type value.
     * @param string $fieldKey    Field identifier (e.g. "games_count", "has_free_spins").
     * @return bool
     */
    public static function isFieldVisible($productType, $fieldKey)
    {
        $type = self::normalizeType($productType);
        $hidden = self::$hiddenFields[$type] ?? [];

        return !in_array($fieldKey, $hidden, true);
    }

    /**
     * Normalize API product type values to internal keys.
     *
     * @param string $productType
     * @return string
     */
    public static function normalizeType($productType)
    {
        return strtolower(trim((string) $productType));
    }
}

// Backward compatibility for legacy global references.
\class_alias(__NAMESPACE__ . '\\ProductTypeLabels', 'ProductTypeLabels');
