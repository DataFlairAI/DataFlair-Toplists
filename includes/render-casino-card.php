<?php
/**
 * Render a single casino card for toplist
 * Using custom CSS classes for reliable styling
 */

// Extract data
$brand = $item['brand'];
$offer = $item['offer'];
$position = $item['position'];

$brand_name = esc_html($brand['name']);
$brand_slug = sanitize_title($brand_name);

// Handle logo - prioritize local_logo, then fallback to external URLs
$logo_url = '';

// First, check if we have a locally saved logo
if (!empty($brand['local_logo'])) {
    $logo_url = $brand['local_logo'];
} else {
    // Fallback to external logo URLs
    $logo_sources = array(
        'logo',           // Standard key
        'brandLogo',      // Alternative key
        'logoUrl',        // Alternative key
        'image',          // Alternative key
        'logoImage'       // Alternative key
    );

    foreach ($logo_sources as $key) {
        if (!empty($brand[$key])) {
            if (is_array($brand[$key])) {
                // Check for nested logo object with rectangular/square options
                if (!empty($brand[$key]['rectangular'])) {
                    $logo_url = $brand[$key]['rectangular'];
                    break;
                } elseif (!empty($brand[$key]['square'])) {
                    $logo_url = $brand[$key]['square'];
                    break;
                } elseif (!empty($brand[$key]['url'])) {
                    $logo_url = $brand[$key]['url'];
                    break;
                } elseif (!empty($brand[$key]['src'])) {
                    $logo_url = $brand[$key]['src'];
                    break;
                } elseif (!empty($brand[$key]['path'])) {
                    $logo_url = $brand[$key]['path'];
                    break;
                } elseif (!empty($brand[$key][0])) {
                    $logo_url = $brand[$key][0];
                    break;
                }
            } else {
                // It's a string - use directly
                $logo_url = $brand[$key];
                break;
            }
        }
    }
}

// Clean and validate the logo URL
if (!empty($logo_url) && !is_array($logo_url)) {
    $logo_url = esc_url($logo_url);
} else {
    $logo_url = '';
}

$rating = !empty($item['rating']) ? floatval($item['rating']) : (!empty($brand['rating']) ? floatval($brand['rating']) : 0);
$offer_text = !empty($offer['offerText']) ? esc_html($offer['offerText']) : '';

// Handle features - check block editor pros/cons first, then fall back to API features
$features = array();

// Build casino key matching the block editor format: casino-{position}-{brandSlug}
$casino_key = 'casino-' . $position . '-' . $brand_slug;

// Debug logging
error_log('DataFlair: Position: ' . $position . ', Brand slug: ' . $brand_slug);
error_log('DataFlair: Casino key: ' . $casino_key);
error_log('DataFlair: Pros/cons data available: ' . (!empty($pros_cons_data) ? 'Yes' : 'No'));
if (!empty($pros_cons_data)) {
    error_log('DataFlair: Pros/cons keys: ' . implode(', ', array_keys($pros_cons_data)));
}

if (!empty($pros_cons_data) && isset($pros_cons_data[$casino_key])) {
    // Use pros from block editor
    $casino_pros_cons = $pros_cons_data[$casino_key];
    error_log('DataFlair: Found pros/cons for casino ' . $casino_key . ': ' . json_encode($casino_pros_cons));
    if (!empty($casino_pros_cons['pros']) && is_array($casino_pros_cons['pros'])) {
        // Filter out empty strings
        $features = array_filter($casino_pros_cons['pros'], function($pro) {
            return !empty(trim($pro));
        });
        $features = array_slice($features, 0, 3);
        error_log('DataFlair: Using ' . count($features) . ' pros as features');
    }
} 

// Fall back to API features if no block editor pros are set
if (empty($features) && !empty($item['features'])) {
    $features = array_slice($item['features'], 0, 3);
    error_log('DataFlair: Using ' . count($features) . ' API features');
}

// Payment methods - check multiple possible keys
$payment_methods = array();
if (!empty($item['payment_methods'])) {
    $payment_methods = $item['payment_methods'];
} elseif (!empty($item['paymentMethods'])) {
    $payment_methods = $item['paymentMethods'];
} elseif (!empty($brand['payment_methods'])) {
    $payment_methods = $brand['payment_methods'];
} elseif (!empty($brand['paymentMethods'])) {
    $payment_methods = $brand['paymentMethods'];
}

$bonus_wagering = !empty($offer['bonus_wagering_requirement']) ? esc_html($offer['bonus_wagering_requirement']) : '';
$min_deposit = !empty($offer['minimum_deposit']) ? esc_html($offer['minimum_deposit']) : '';
$payout_time = !empty($offer['payout_time']) ? esc_html($offer['payout_time']) : '';
$max_payout = !empty($offer['max_payout']) ? esc_html($offer['max_payout']) : 'None';
$games_count = !empty($item['games_count']) ? esc_html($item['games_count']) : '';

// Handle licenses (might be array or string)
$licenses = '';
if (!empty($brand['licenses'])) {
    if (is_array($brand['licenses'])) {
        $licenses = esc_html(implode(', ', $brand['licenses']));
    } else {
        $licenses = esc_html($brand['licenses']);
    }
}

$reviewer = !empty($item['reviewer']) ? esc_html($item['reviewer']) : '';

// Get casino URL (handle arrays)
$casino_url = '';
if (!empty($offer['tracking_url'])) {
    $casino_url = is_array($offer['tracking_url']) ? '' : $offer['tracking_url'];
} elseif (!empty($offer['url'])) {
    $casino_url = is_array($offer['url']) ? '' : $offer['url'];
} elseif (!empty($brand['url'])) {
    $casino_url = is_array($brand['url']) ? '' : $brand['url'];
}
$casino_url = !empty($casino_url) ? esc_url($casino_url) : '#';

// Get review URL - use pre-set URL from parent function, or generate it
if (!isset($review_url) || empty($review_url)) {
    // Check if review URL was passed from parent function
    if (!empty($brand['review_url']) && !is_array($brand['review_url'])) {
        $review_url = esc_url($brand['review_url']);
    } else {
        // Generate review URL: /reviews/{brand-slug}
        $brand_slug = !empty($brand['slug']) ? $brand['slug'] : sanitize_title($brand['name']);
        $review_url = home_url('/reviews/' . $brand_slug . '/');
    }
}

// Fallback to casino URL if review URL is still empty
if (empty($review_url)) {
    $review_url = $casino_url;
}
?>

<div class="casino-card-wrapper" x-data="{ showDetails: false }" data-position="<?php echo esc_attr($position); ?>">
    
    <?php if ($position === 1): ?>
    <div class="casino-card-ribbon">
        <svg class="ribbon-star" fill="currentColor" viewBox="0 0 20 20">
            <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
        </svg>
        OUR TOP CHOICE
    </div>
    <?php endif; ?>
    
    <div class="casino-card <?php echo $position === 1 ? 'top-choice' : ''; ?>">
        
        <div class="casino-card-main">
            
            <!-- Brand Column -->
            <div class="casino-brand-col">
                <div class="casino-position-badge">
                    <?php echo esc_html($position); ?>
                </div>
                
                <div class="casino-logo">
                    <a href="<?php echo esc_url($review_url); ?>">
                        <?php if (!empty($logo_url)): ?>
                            <img src="<?php echo esc_url($logo_url); ?>" 
                                 alt="<?php echo esc_attr($brand_name); ?>" 
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <div class="casino-logo-placeholder" style="display: none;">
                                <?php echo esc_html(substr($brand_name, 0, 2)); ?>
                            </div>
                        <?php else: ?>
                            <div class="casino-logo-placeholder">
                                <?php echo esc_html(substr($brand_name, 0, 2)); ?>
                            </div>
                        <?php endif; ?>
                    </a>
                </div>
                
                <div class="casino-brand-info">
                    <a href="<?php echo esc_url($review_url); ?>" class="casino-brand-name">
                        <?php echo esc_html($brand_name); ?>
                    </a>
                    
                    <?php if ($rating > 0): ?>
                    <div class="casino-rating">
                        <span class="rating-label">Our rating:</span>
                        <svg class="rating-star" viewBox="0 0 20 20">
                            <path fill="currentColor" d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                        </svg>
                        <span class="rating-value"><?php echo esc_html($rating); ?><span class="rating-max">/5</span></span>
                    </div>
                    <?php endif; ?>
                    
                    <a href="<?php echo esc_url($review_url); ?>" class="casino-review-link">
                        Read Review
                        <svg class="review-arrow" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        </svg>
                    </a>
                </div>
            </div>
            
            <!-- Bonus Column -->
            <div class="casino-bonus-col">
                <div class="bonus-label">Welcome Bonus</div>
                <a href="<?php echo $casino_url; ?>" target="_blank" rel="nofollow" class="bonus-text">
                    <?php echo $offer_text ?: 'Bonus Available'; ?>
                </a>
            </div>
            
            <!-- Features Column -->
            <div class="casino-features-col">
                <?php if (!empty($features)): ?>
                    <?php foreach ($features as $feature): ?>
                    <div class="feature-item">
                        <svg class="feature-check" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
                        </svg>
                        <span><?php echo esc_html($feature); ?></span>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="feature-item no-features">No features listed</div>
                <?php endif; ?>
            </div>
            
            <!-- CTA Column -->
            <div class="casino-cta-col">
                <a href="<?php echo $casino_url; ?>" target="_blank" rel="nofollow" class="casino-cta-button">
                    Visit Site
                </a>
                
                <button type="button" class="casino-toggle-button" @click="showDetails = !showDetails">
                    <span x-text="showDetails ? 'Show less' : 'Show more'">Show more</span>
                    <svg class="toggle-arrow" :class="showDetails ? 'rotated' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>
            </div>
            
        </div>
        
        <!-- Expandable Details -->
        <div class="casino-card-details" 
             x-show="showDetails" 
             x-transition:enter="details-enter"
             x-transition:enter-start="details-enter-start"
             x-transition:enter-end="details-enter-end"
             style="display: none;">
            
            <!-- Payment Methods -->
            <?php if (!empty($payment_methods) && is_array($payment_methods)): ?>
            <div class="details-section">
                <h4 class="details-heading">
                    <svg class="heading-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/>
                    </svg>
                    Payment Methods
                </h4>
                <div class="payment-methods-grid">
                    <?php foreach (array_slice($payment_methods, 0, 10) as $method): 
                        $method_name = is_array($method) ? ($method['name'] ?? '') : $method;
                        $method_logo_raw = is_array($method) ? ($method['logo'] ?? '') : '';
                        // Ensure logo is a string, not an array
                        $method_logo = (!empty($method_logo_raw) && !is_array($method_logo_raw)) ? $method_logo_raw : '';
                    ?>
                        <?php if (!empty($method_logo)): ?>
                        <div class="payment-icon-wrapper">
                            <img src="<?php echo esc_url($method_logo); ?>" 
                                 alt="<?php echo esc_attr($method_name); ?>" 
                                 class="payment-icon"
                                 title="<?php echo esc_attr($method_name); ?>">
                        </div>
                        <?php elseif (!empty($method_name)): ?>
                        <div class="payment-text"><?php echo esc_html($method_name); ?></div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Metrics Grid -->
            <div class="casino-metrics-grid">
                <?php if (!empty($bonus_wagering)): ?>
                <div class="metric-item">
                    <div class="metric-label">Bonus Wagering</div>
                    <div class="metric-value"><?php echo esc_html($bonus_wagering); ?>x</div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($min_deposit)): ?>
                <div class="metric-item">
                    <div class="metric-label">Min Deposit</div>
                    <div class="metric-value"><?php echo esc_html($min_deposit); ?></div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($games_count)): ?>
                <div class="metric-item">
                    <div class="metric-label">Casino Games</div>
                    <div class="metric-value"><?php echo esc_html($games_count); ?>+</div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($payout_time)): ?>
                <div class="metric-item">
                    <div class="metric-label">Payout Time</div>
                    <div class="metric-value"><?php echo esc_html($payout_time); ?></div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($max_payout)): ?>
                <div class="metric-item">
                    <div class="metric-label">Max Payout</div>
                    <div class="metric-value"><?php echo esc_html($max_payout); ?></div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($licenses)): ?>
                <div class="metric-item">
                    <div class="metric-label">Licences</div>
                    <div class="metric-value"><?php echo esc_html($licenses); ?></div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Footer Info -->
            <div class="details-footer">
                <?php if (!empty($reviewer)): ?>
                <div class="reviewer-info">
                    <svg class="reviewer-icon" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-6-3a2 2 0 11-4 0 2 2 0 014 0zm-2 4a5 5 0 00-4.546 2.916A5.986 5.986 0 0010 16a5.986 5.986 0 004.546-2.084A5 5 0 0010 11z" clip-rule="evenodd"/>
                    </svg>
                    Reviewed by <strong><?php echo esc_html($reviewer); ?></strong>
                </div>
                <?php endif; ?>
                
                <div class="rg-disclaimer">
                    <strong>18+ only.</strong> Please gamble responsibly. 
                    <a href="https://www.gambleaware.org" target="_blank">www.gambleaware.org</a>
                </div>
            </div>
            
        </div>
        
    </div>
    
</div>
