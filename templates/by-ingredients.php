<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- variables here are template-local, not actually global.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
use Cookbook\App;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- idempotent read-only filter.
$have_ids = isset( $_GET['have'] ) && is_array( $_GET['have'] )
    ? array_values( array_unique( array_filter( array_map( 'absint', wp_unslash( $_GET['have'] ) ) ) ) )
    : [];
$max_missing = isset( $_GET['max_missing'] ) ? max( 0, min( 99, (int) $_GET['max_missing'] ) ) : 99;
// phpcs:enable WordPress.Security.NonceVerification.Recommended

$all_terms = get_terms( [
    'taxonomy'   => App::TAX_INGREDIENT,
    'hide_empty' => true,
    'orderby'    => 'count',
    'order'      => 'DESC',
] );
if ( is_wp_error( $all_terms ) ) {
    $all_terms = [];
}

$have_set = array_flip( $have_ids );

$results = [];
$has_query = $have_ids !== [];
if ( $has_query ) {
    $candidates = get_posts( [
        'post_type'      => App::POST_TYPE,
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- ingredient filter is the whole point of this page.
        'tax_query'      => [
            [
                'taxonomy' => App::TAX_INGREDIENT,
                'field'    => 'term_id',
                'terms'    => $have_ids,
                'operator' => 'IN',
            ],
        ],
    ] );
    foreach ( $candidates as $r ) {
        $rows = (array) get_post_meta( $r->ID, App::META_INGREDIENTS, true );
        $matched = 0;
        $total   = 0;
        $missing = [];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) continue;
            $name = isset( $row['name'] ) ? (string) $row['name'] : '';
            if ( $name === '' ) continue;
            $total++;
            $term_id = isset( $row['term_id'] ) ? (int) $row['term_id'] : 0;
            if ( $term_id && isset( $have_set[ $term_id ] ) ) {
                $matched++;
            } else {
                $missing[] = $name;
            }
        }
        if ( $total === 0 || $matched === 0 ) continue;
        if ( ( $total - $matched ) > $max_missing ) continue;
        $results[] = [
            'post'    => $r,
            'matched' => $matched,
            'total'   => $total,
            'missing' => $missing,
        ];
    }
    usort( $results, function( $a, $b ) {
        $ra = $a['matched'] / max( 1, $a['total'] );
        $rb = $b['matched'] / max( 1, $b['total'] );
        if ( $ra !== $rb ) return $rb <=> $ra;
        if ( $a['matched'] !== $b['matched'] ) return $b['matched'] <=> $a['matched'];
        return ( $a['total'] - $a['matched'] ) <=> ( $b['total'] - $b['matched'] );
    } );
}

// Sizing for the tag cloud: log-scale based on each term's count.
$max_count = 0;
foreach ( $all_terms as $t ) { $max_count = max( $max_count, (int) $t->count ); }

$page_title = __( 'Find recipes by ingredients', 'cook-app' );
include __DIR__ . '/_header.php';
?>
<?php cookbook_page_head( __( 'Find recipes by ingredients', 'cook-app' ), [
    'current_section' => 'ingredients',
    'subtitle'        => __( 'Click the ingredients you have, then search.', 'cook-app' ),
] ); ?>

<?php if ( ! $all_terms ) : ?>
    <div class="notice"><?php esc_html_e( 'No ingredients yet. Add or import some recipes first — their ingredients will appear here.', 'cook-app' ); ?></div>
<?php else : ?>
<form method="get" action="" id="byi-form">
    <div class="ingredient-cloud" role="group" aria-label="<?php esc_attr_e( 'Available ingredients', 'cook-app' ); ?>">
        <?php foreach ( $all_terms as $t ) :
            $is_on  = isset( $have_set[ $t->term_id ] );
            $weight = $max_count > 0 ? sqrt( (int) $t->count / $max_count ) : 0;
            $size   = 0.85 + $weight * 0.6; // 0.85rem … 1.45rem
            ?>
            <label class="ing-chip<?php echo $is_on ? ' on' : ''; ?>" style="font-size:<?php echo esc_attr( number_format( $size, 2, '.', '' ) ); ?>rem">
                <input type="checkbox" name="have[]" value="<?php echo (int) $t->term_id; ?>" <?php checked( $is_on ); ?>>
                <span><?php echo esc_html( $t->name ); ?></span>
                <span class="ing-chip-count"><?php echo (int) $t->count; ?></span>
            </label>
        <?php endforeach; ?>
    </div>

    <div class="toolbar">
        <label for="max_missing" style="margin:0"><?php esc_html_e( 'Allow missing:', 'cook-app' ); ?></label>
        <select id="max_missing" name="max_missing">
            <?php foreach ( [ 0, 1, 2, 3, 5, 10, 99 ] as $opt ) :
                $label = $opt === 0
                    ? __( 'None (have everything)', 'cook-app' )
                    : ( $opt === 99
                        ? __( 'Any', 'cook-app' )
                        /* translators: %d: number of allowed missing ingredients */
                        : sprintf( _n( 'up to %d ingredient', 'up to %d ingredients', $opt, 'cook-app' ), $opt ) );
                ?>
                <option value="<?php echo (int) $opt; ?>" <?php selected( $max_missing, $opt ); ?>><?php echo esc_html( $label ); ?></option>
            <?php endforeach; ?>
        </select>
        <span class="spacer"></span>
        <?php if ( $has_query ) : ?>
            <a class="btn secondary" href="<?php echo esc_url( home_url( '/cook-app/by-ingredients' ) ); ?>"><?php esc_html_e( 'Clear', 'cook-app' ); ?></a>
        <?php endif; ?>
        <button class="btn" type="submit"><?php esc_html_e( 'Find recipes', 'cook-app' ); ?></button>
    </div>
</form>
<?php endif; ?>

<?php if ( $has_query ) : ?>
    <h2>
        <?php
        /* translators: %d: number of matching recipes */
        echo esc_html( sprintf( _n( '%d match', '%d matches', count( $results ), 'cook-app' ), count( $results ) ) );
        ?>
    </h2>
    <?php if ( ! $results ) : ?>
        <div class="notice"><?php esc_html_e( 'No recipes match. Try selecting more ingredients, or allow more missing ones.', 'cook-app' ); ?></div>
    <?php else : ?>
        <div class="grid">
        <?php foreach ( $results as $row ) :
            $r        = $row['post'];
            $matched  = $row['matched'];
            $total    = $row['total'];
            $missing  = $row['missing'];
            $is_full  = ! $missing;
            ?>
            <a class="recipe-card" href="<?php echo esc_url( home_url( '/cook-app/recipe/' . $r->ID ) ); ?>" style="<?php echo has_post_thumbnail( $r->ID ) ? 'display:flex;gap:0.9rem;align-items:flex-start' : ''; ?>">
                <?php if ( has_post_thumbnail( $r->ID ) ) : ?>
                    <?php echo get_the_post_thumbnail( $r->ID, 'thumbnail', [
                        'style' => 'width:80px;height:80px;object-fit:cover;border-radius:6px;flex-shrink:0',
                        'alt'   => '',
                    ] ); ?>
                    <div style="flex:1;min-width:0">
                <?php endif; ?>
                <h3><?php echo esc_html( get_the_title( $r ) ); ?>
                    <?php if ( $is_full ) : ?><span class="badge" style="background:var(--success-bg);border-color:var(--success-bd)"><?php esc_html_e( 'have everything', 'cook-app' ); ?></span><?php endif; ?>
                </h3>
                <div class="meta">
                    <span>
                        <?php
                        /* translators: 1: number of matched ingredients, 2: total ingredients */
                        echo esc_html( sprintf( __( '%1$d of %2$d ingredients on hand', 'cook-app' ), $matched, $total ) );
                        ?>
                    </span>
                </div>
                <?php if ( $missing ) : ?>
                    <p style="margin:0.4rem 0 0;color:var(--muted);font-size:0.9rem">
                        <strong><?php esc_html_e( 'Missing:', 'cook-app' ); ?></strong>
                        <?php echo esc_html( implode( ', ', $missing ) ); ?>
                    </p>
                <?php endif; ?>
                <?php if ( has_post_thumbnail( $r->ID ) ) : ?>
                    </div>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>



<?php include __DIR__ . '/_footer.php'; ?>
