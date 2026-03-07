<?php
/**
 * ChurrascoPlanet - Page Template
 *
 * @package ChurrascoPlanet
 */

get_header();
?>

<main id="main" class="site-main">
    <div class="container page-container">
        <?php
        while (have_posts()) :
            the_post();
        ?>
        <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
            <?php if (!is_account_page()) : ?>
            <header class="page-header">
                <h1 class="page-title"><?php the_title(); ?></h1>
            </header>
            <?php endif; ?>

            <div class="page-content">
                <?php the_content(); ?>
            </div>
        </article>
        <?php
        endwhile;
        ?>
    </div>
</main>

<?php
get_footer();
